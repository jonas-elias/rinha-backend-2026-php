<?php
declare(strict_types=1);

namespace App;

/**
 * Carrega o índice IVF8 quantizado em int8 e expõe ponteiros (strings binárias
 * + arrays de floats/ints) para o hot path de Knn::score().
 *
 * Layout:
 *   magic     "IVF8"     4 B
 *   n  : u32             4 B
 *   k  : u32             4 B
 *   d  : u32             4 B  (= 14)
 *   centroids f32[d*k]   d*k*4 B   (SoA)
 *   offsets   u32[k+1]   (k+1)*4 B
 *   labels    u8[n_pad]  n_pad B
 *   blocks    i16[n_pad*d] n_pad*d*2 B (AoS por slot, LE)
 */
final class Data
{
    /** Total de vetores reais (sem padding). */
    public static int $n = 0;
    /** Centroides (default 2048). */
    public static int $k = 0;
    /** Vetores arredondados para múltiplo de 8 (slots por bloco). */
    public static int $paddedN = 0;

    /** @var array<int,float> Centroides em AoS. ci*d + dim */
    public static array $centroids = [];

    /** @var array<int,int> Início (em vetores) de cada centroide. k+1 entries. */
    public static array $offsets = [];

    /** Labels (rótulos) crus, 1 byte por vetor (padded). */
    public static string $labels = '';

    /** Blocos AoS int16 LE: para o vetor i, dim d → 2 bytes em $blocks[(i*14 + d)*2 .. +1]. */
    public static string $blocks = '';

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

        $hdr = unpack('Vn/Vk/Vd', fread($fh, 12));
        $n = $hdr['n'];
        $k = $hdr['k'];
        $d = $hdr['d'];
        if ($d !== 14) {
            throw new \RuntimeException("expected d=14 got $d");
        }

        $cBytes = $d * $k * 4;
        $centroidsBin = fread($fh, $cBytes);
        $centroidsArr = unpack('f' . ($d * $k), $centroidsBin);
        $centroids = array_values($centroidsArr);
        unset($centroidsBin, $centroidsArr);

        $oBytes = ($k + 1) * 4;
        $offsetsArr = unpack('V' . ($k + 1), fread($fh, $oBytes));
        $offsets = array_values($offsetsArr);
        unset($offsetsArr);

        $totalBlocks = (int) $offsets[$k];
        $paddedN = $totalBlocks * 8;

        $labels = fread($fh, $paddedN);

        $blocksBytes = $paddedN * $d * 2;
        // stream o blob de ~84MB sem dobrar de memória
        $blocks = stream_get_contents($fh, $blocksBytes);
        fclose($fh);

        if ($blocks === false || strlen($blocks) !== $blocksBytes) {
            throw new \RuntimeException(
                "blocks size mismatch: got " . (is_string($blocks) ? strlen($blocks) : 'false') .
                " want $blocksBytes"
            );
        }

        self::$n = $n;
        self::$k = $k;
        self::$paddedN = $paddedN;
        self::$centroids = $centroids;
        self::$offsets = $offsets;
        self::$labels = $labels;
        self::$blocks = $blocks;

        $ms = (hrtime(true) - $t0) / 1e6;
        $totalMb = (16 + $cBytes + $oBytes + $paddedN + $blocksBytes) / 1048576;
        fwrite(STDERR, sprintf(
            "[data] loaded %s: n=%d k=%d padded_n=%d (%.2f MB) in %.2f ms\n",
            $path, $n, $k, $paddedN, $totalMb, $ms
        ));
    }
}
