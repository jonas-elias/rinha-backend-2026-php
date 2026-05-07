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
 * Compilar: gcc -O3 -march=x86-64-v2 -shared -fPIC -o knn.so knn.c -lm
 */

#include <stdint.h>
#include <stddef.h>
#include <float.h>
#include <math.h>

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
