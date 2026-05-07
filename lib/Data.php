<?php
declare(strict_types=1);

namespace App;

/**
 * Carrega o índice IVF6 quantizado int16 e o expõe via PHP FFI como buffers C.
 *
 * Layout do arquivo:
 *   magic     "IVF6"     4 B
 *   n  : u32             4 B
 *   k  : u32             4 B
 *   d  : u32             4 B  (= 14)
 *   centroids f32[d*k]   d*k*4 B
 *   offsets   u32[k+1]   (k+1)*4 B
 *   labels    u8[n_pad]  n_pad B
 *   blocks    i16[n_pad*d] n_pad*d*2 B (AoS por slot, LE)
 *
 * Os dados são copiados para memória C (via FFI) para que o hot path em
 * knn.c acesse os vetores com operações nativas SIMD sem overhead de PHP.
 */
final class Data
{
    public static int $n       = 0;
    public static int $k       = 0;
    public static int $paddedN = 0;

    /** FFI handle carregando knn.so. */
    public static \FFI $ffi;

    /** float[k * 14] — centroides do IVF. */
    public static \FFI\CData $cCentroids;
    /** int16_t[paddedN * 14] — vetores quantizados AoS. */
    public static \FFI\CData $cBlocks;
    /** uint8_t[paddedN] — labels. */
    public static \FFI\CData $cLabels;
    /** int32_t[k+1] — offsets dos clusters (em blocos de 8 vetores). */
    public static \FFI\CData $cOffsets;
    /** float[14] — buffer de query reutilizado por request (1 cópia por worker). */
    public static \FFI\CData $cQuery;

    public static function init(?string $path = null): void
    {
        $path ??= getenv('INDEX_PATH') ?: '/app/data/index_q.bin';
        if (!is_file($path)) {
            throw new \RuntimeException("index missing at $path");
        }

        $t0 = hrtime(true);

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new \RuntimeException("open failed: $path");
        }

        $magic = fread($fh, 4);
        if ($magic !== 'IVF6') {
            throw new \RuntimeException("bad magic (expected IVF6, got '$magic')");
        }

        $hdr     = unpack('Vn/Vk/Vd', fread($fh, 12));
        $n       = (int) $hdr['n'];
        $k       = (int) $hdr['k'];
        $d       = (int) $hdr['d'];
        if ($d !== 14) {
            throw new \RuntimeException("expected d=14 got $d");
        }

        /* Lê seções binárias brutas (sem unpack para PHP array). */
        $cBytes      = $d * $k * 4;
        $centroidsBin = fread($fh, $cBytes);

        $oBytes     = ($k + 1) * 4;
        $offsetsBin = fread($fh, $oBytes);

        /* Número de blocos total vem do último offset. */
        $lastOff     = unpack('V', substr($offsetsBin, $k * 4, 4))[1];
        $totalBlocks = (int) $lastOff;
        $paddedN     = $totalBlocks * 8;

        $labelsBin  = fread($fh, $paddedN);
        $blocksBytes = $paddedN * $d * 2;
        $blocksBin   = stream_get_contents($fh, $blocksBytes);
        fclose($fh);

        if ($blocksBin === false || strlen($blocksBin) !== $blocksBytes) {
            throw new \RuntimeException(
                "blocks size mismatch: got " . (is_string($blocksBin) ? strlen($blocksBin) : 'false') .
                " want $blocksBytes"
            );
        }

        /* Carrega a shared library C com o hot path de k-NN. */
        $ffi = \FFI::cdef(
            'int knn_fraud_count(
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

        /* Aloca buffers C e copia dados binários do índice. */
        $nCentroidsEl = $d * $k;                     // número de floats
        self::$cCentroids = $ffi->new("float[$nCentroidsEl]");
        \FFI::memcpy(self::$cCentroids, $centroidsBin, $cBytes);
        unset($centroidsBin);

        self::$cOffsets = $ffi->new("int32_t[" . ($k + 1) . "]");
        \FFI::memcpy(self::$cOffsets, $offsetsBin, $oBytes);
        unset($offsetsBin);

        self::$cLabels = $ffi->new("uint8_t[$paddedN]");
        \FFI::memcpy(self::$cLabels, $labelsBin, $paddedN);
        unset($labelsBin);

        $nBlocksEl = $paddedN * $d;                  // número de int16_t
        self::$cBlocks = $ffi->new("int16_t[$nBlocksEl]");
        \FFI::memcpy(self::$cBlocks, $blocksBin, $blocksBytes);
        unset($blocksBin);

        self::$cQuery = $ffi->new("float[14]");

        self::$n       = $n;
        self::$k       = $k;
        self::$paddedN = $paddedN;

        $ms      = (hrtime(true) - $t0) / 1e6;
        $totalMb = (16 + $cBytes + $oBytes + $paddedN + $blocksBytes) / 1048576;
        fwrite(STDERR, sprintf(
            "[data] loaded %s: n=%d k=%d padded_n=%d (%.2f MB) in %.2f ms\n",
            $path, $n, $k, $paddedN, $totalMb, $ms
        ));
    }
}
