/*
 * knn.c – hot path do IVF k-NN em C puro (compilado como .so para PHP FFI).
 *
 * Processo por request:
 *   1. Quantiza a query float32 → int16 (mesmo scale do índice Rust).
 *   2. Varre os k centroides (float32) e encontra top-N mais próximos.
 *   3. Para cada probe, escaneia blocos AoS int16 (8 vetores × 14 dims).
 *   4. Mantém heap de 5 vizinhos mais próximos; retorna contagem de label=1.
 *   5. Se count ∈ {2,3} (edge case), repete com FULL_NPROBE para maior acurácia.
 *
 * load_index() carrega o índice diretamente em heap C, sem passar por strings PHP,
 * evitando pico de memória de 2x o tamanho do índice durante a inicialização.
 *
 * Compilar: gcc -O3 -march=x86-64-v2 -shared -fPIC -o knn.so knn.c -lm
 */

#include <stdint.h>
#include <stddef.h>
#include <float.h>
#include <math.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

/* ============================================================
 * Estrutura do índice IVF6 carregada em memória C.
 * ============================================================ */
typedef struct {
    float*    centroids;  /* float[k * 14] */
    int16_t*  blocks;     /* int16_t[padded_n * 14] */
    uint8_t*  labels;     /* uint8_t[padded_n] */
    int32_t*  offsets;    /* int32_t[k + 1] */
    int32_t   n;
    int32_t   k;
    int32_t   padded_n;
} IvfIndex;

/*
 * Lê o arquivo índice IVF6 diretamente para heap C usando fread(),
 * sem criar strings PHP intermediárias. Retorna NULL em caso de falha.
 * Evita o pico de memória de ~166 MB que causa OOM no servidor de teste.
 */
IvfIndex* load_index(const char* path)
{
    FILE* f = fopen(path, "rb");
    if (!f) return NULL;

    char magic[4];
    if (fread(magic, 1, 4, f) != 4 || memcmp(magic, "IVF6", 4) != 0) {
        fclose(f); return NULL;
    }

    uint32_t hdr[3]; /* n, k, d */
    if (fread(hdr, 4, 3, f) != 3) { fclose(f); return NULL; }
    uint32_t n = hdr[0], k = hdr[1], d = hdr[2];
    if (d != 14) { fclose(f); return NULL; }

    IvfIndex* idx = (IvfIndex*)calloc(1, sizeof(IvfIndex));
    if (!idx) { fclose(f); return NULL; }
    idx->n = (int32_t)n;
    idx->k = (int32_t)k;

    /* Centroides: k * d floats */
    size_t c_elems = (size_t)k * d;
    idx->centroids = (float*)malloc(c_elems * sizeof(float));
    if (!idx->centroids || fread(idx->centroids, sizeof(float), c_elems, f) != c_elems)
        goto fail;

    /* Offsets: (k+1) int32 */
    idx->offsets = (int32_t*)malloc((k + 1) * sizeof(int32_t));
    if (!idx->offsets || fread(idx->offsets, sizeof(int32_t), k + 1, f) != k + 1)
        goto fail;

    /* padded_n = last_offset * 8 */
    uint32_t total_blocks = (uint32_t)idx->offsets[k];
    uint32_t padded_n = total_blocks * 8;
    idx->padded_n = (int32_t)padded_n;

    /* Labels: padded_n bytes */
    idx->labels = (uint8_t*)malloc(padded_n);
    if (!idx->labels || fread(idx->labels, 1, padded_n, f) != padded_n)
        goto fail;

    /* Blocks: padded_n * d int16 */
    size_t b_elems = (size_t)padded_n * d;
    idx->blocks = (int16_t*)malloc(b_elems * sizeof(int16_t));
    if (!idx->blocks || fread(idx->blocks, sizeof(int16_t), b_elems, f) != b_elems)
        goto fail;

    fclose(f);
    return idx;

fail:
    fclose(f);
    free(idx->centroids);
    free(idx->offsets);
    free(idx->labels);
    free(idx->blocks);
    free(idx);
    return NULL;
}

void free_index(IvfIndex* idx)
{
    if (!idx) return;
    free(idx->centroids);
    free(idx->blocks);
    free(idx->labels);
    free(idx->offsets);
    free(idx);
}

#define DIMS        14
#define MAX_NPROBE  64

static void top_n_centroids(
    const float* __restrict__ centroids,
    const float* __restrict__ query,
    int k, int n,
    int32_t* __restrict__ out
) {
    float top_d[MAX_NPROBE];
    for (int i = 0; i < n; i++) { top_d[i] = FLT_MAX; out[i] = 0; }
    float worst = FLT_MAX;

    for (int ci = 0; ci < k; ci++) {
        const float* c = centroids + (size_t)ci * DIMS;
        float d = 0.0f;
        for (int j = 0; j < DIMS; j++) {
            float a = c[j] - query[j];
            d += a * a;
        }
        if (d >= worst) continue;

        int pos = n - 1;
        while (pos > 0 && top_d[pos - 1] > d) {
            top_d[pos] = top_d[pos - 1];
            out[pos]   = out[pos - 1];
            pos--;
        }
        top_d[pos] = d;
        out[pos]   = ci;
        worst = top_d[n - 1];
    }
}

static int scan_blocks(
    const int16_t* __restrict__ blocks,
    const uint8_t* __restrict__ labels,
    const int32_t* __restrict__ offsets,
    const int16_t* __restrict__ qi,
    const int32_t* __restrict__ probes,
    int n_probes
) {
    /* Heap dos 5 vizinhos mais próximos. Sentinel = INT64_MAX/2. */
    int64_t SENT = (int64_t)4e18;
    int64_t top5d[5];
    uint8_t top5l[5];
    for (int i = 0; i < 5; i++) { top5d[i] = SENT; top5l[i] = 0; }
    int     worst_idx = 0;
    int64_t worst_val = SENT;

    for (int pi = 0; pi < n_probes; pi++) {
        int ci    = probes[pi];
        int start = offsets[ci];
        int end   = offsets[ci + 1];

        const int16_t* blk = blocks + (int64_t)start * (8 * DIMS);
        const uint8_t* lbl = labels + (int64_t)start * 8;

        for (int bi = start; bi < end; bi++, blk += 8 * DIMS, lbl += 8) {
            for (int s = 0; s < 8; s++) {
                const int16_t* v = blk + s * DIMS;

                /* Early exit após primeiros 8 dims. */
                int64_t d = 0;
                for (int j = 0; j < 8; j++) {
                    int64_t a = (int64_t)v[j] - qi[j];
                    d += a * a;
                }
                if (d >= worst_val) continue;

                for (int j = 8; j < DIMS; j++) {
                    int64_t a = (int64_t)v[j] - qi[j];
                    d += a * a;
                }
                if (d >= worst_val) continue;

                top5d[worst_idx] = d;
                top5l[worst_idx] = lbl[s];

                /* Encontra novo worst em top5. */
                int64_t w = top5d[0]; int wi = 0;
                if (top5d[1] > w) { w = top5d[1]; wi = 1; }
                if (top5d[2] > w) { w = top5d[2]; wi = 2; }
                if (top5d[3] > w) { w = top5d[3]; wi = 3; }
                if (top5d[4] > w) { w = top5d[4]; wi = 4; }
                worst_idx = wi;
                worst_val = w;
            }
        }
    }

    int count = 0;
    for (int j = 0; j < 5; j++) count += (top5l[j] == 1);
    return count;
}

/*
 * Ponto de entrada público (chamado via PHP FFI).
 *
 * centroids : float[k * 14]    – centroides do IVF (float32 LE)
 * blocks    : int16_t[padded_n * 14] – vetores quantizados (AoS LE)
 * labels    : uint8_t[padded_n]     – label de cada vetor
 * offsets   : int32_t[k + 1]        – início de cada cluster (em blocos de 8)
 * query     : float[14]             – vetor da transação
 * k         : número de centroides
 * fast_nprobe / full_nprobe         – número de probes rápido / completo
 */
int knn_fraud_count(
    const float*   centroids,
    const int16_t* blocks,
    const uint8_t* labels,
    const int32_t* offsets,
    const float*   query,
    int k,
    int fast_nprobe,
    int full_nprobe
) {
    /* Quantiza query float32 → int16 (scale = 10000). */
    int16_t qi[DIMS];
    for (int i = 0; i < DIMS; i++) {
        float fq  = query[i] * 10000.0f;
        int   iq  = (int)(fq + (fq >= 0.0f ? 0.5f : -0.5f));
        if (iq < -32768) iq = -32768;
        if (iq >  32767) iq =  32767;
        qi[i] = (int16_t)iq;
    }

    int32_t probes[MAX_NPROBE];

    top_n_centroids(centroids, query, k, fast_nprobe, probes);
    int count = scan_blocks(blocks, labels, offsets, qi, probes, fast_nprobe);

    /* Edge case: repete com mais probes para casos ambíguos. */
    if (count == 2 || count == 3) {
        top_n_centroids(centroids, query, k, full_nprobe, probes);
        count = scan_blocks(blocks, labels, offsets, qi, probes, full_nprobe);
    }

    return count;
}
