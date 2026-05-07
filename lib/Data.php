<?php
declare(strict_types=1);

namespace App;

/**
 * Carrega o índice IVF6 via load_index() em C — sem strings PHP intermediárias.
 *
 * A versão anterior usava fread() em PHP, criando uma string de 83 MB e ao mesmo
 * tempo um buffer C de 83 MB (durante FFI::memcpy), totalizando ~166 MB de dados
 * apenas para os blocks. Somado ao overhead PHP/OPcache (~47 MB), o pico era ~213 MB,
 * o que causava OOMKill no servidor de teste (limite de 165 MB sem swap).
 *
 * Agora load_index() faz fread() direto para heap C — pico máximo ~130 MB.
 */
final class Data
{
    public static int $n       = 0;
    public static int $k       = 0;
    public static int $paddedN = 0;

    /** FFI handle carregando knn.so. */
    public static \FFI $ffi;

    /** float* — centroides do IVF (ponteiro C, mantido vivo pelo $cIndex). */
    public static \FFI\CData $cCentroids;
    /** int16_t* — vetores quantizados AoS (ponteiro C). */
    public static \FFI\CData $cBlocks;
    /** uint8_t* — labels (ponteiro C). */
    public static \FFI\CData $cLabels;
    /** int32_t* — offsets dos clusters (ponteiro C). */
    public static \FFI\CData $cOffsets;
    /** float[14] — buffer de query reutilizado por request (1 cópia por worker). */
    public static \FFI\CData $cQuery;
    /** IvfIndex* — mantém o struct C vivo enquanto o processo existir. */
    public static \FFI\CData $cIndex;

    public static function init(?string $path = null): void
    {
        $path ??= getenv('INDEX_PATH') ?: '/app/data/index_q.bin';
        if (!is_file($path)) {
            throw new \RuntimeException("index missing at $path");
        }

        $t0 = hrtime(true);

        /* Carrega a shared library C. */
        $ffi = \FFI::cdef(
            '/* Struct do índice IVF6 — alocado e preenchido inteiramente em C. */
            typedef struct {
                float*    centroids;
                int16_t*  blocks;
                uint8_t*  labels;
                int32_t*  offsets;
                int32_t   n;
                int32_t   k;
                int32_t   padded_n;
            } IvfIndex;

            /* Lê o arquivo IVF6 direto para heap C (sem strings PHP). Retorna NULL em falha. */
            IvfIndex* load_index(const char* path);

            /* Libera o índice. Chamado apenas no shutdown (opcional para processos long-lived). */
            void free_index(IvfIndex* idx);

            /* Hot path: retorna contagem de vizinhos com label=1 para a query. */
            int knn_fraud_count(
                const float*   centroids,
                const int16_t* blocks,
                const uint8_t* labels,
                const int32_t* offsets,
                const float*   query,
                int k, int fast_nprobe, int full_nprobe
            );',
            '/app/lib/knn.so'
        );
        self::$ffi = $ffi;

        /* load_index() abre o arquivo e popula os buffers inteiramente em C.
         * Pico de memória: ~83 MB (blocks) + overhead C = ~90 MB.
         * Sem strings PHP de 83 MB duplicando o uso de memória. */
        $idx = $ffi->load_index($path);
        if (\FFI::isNull($idx)) {
            throw new \RuntimeException("load_index() falhou para: $path");
        }

        self::$cIndex     = $idx;
        self::$n          = $idx->n;
        self::$k          = $idx->k;
        self::$paddedN    = $idx->padded_n;
        self::$cCentroids = $idx->centroids;
        self::$cBlocks    = $idx->blocks;
        self::$cLabels    = $idx->labels;
        self::$cOffsets   = $idx->offsets;

        /* Buffer de query: alocado via PHP FFI (apenas 56 bytes). */
        self::$cQuery = $ffi->new("float[14]");

        $ms = (hrtime(true) - $t0) / 1e6;
        fwrite(STDERR, sprintf(
            "[data] loaded %s: n=%d k=%d padded_n=%d in %.2f ms\n",
            $path, self::$n, self::$k, self::$paddedN, $ms
        ));
    }
}
