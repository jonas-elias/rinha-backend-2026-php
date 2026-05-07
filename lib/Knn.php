<?php
declare(strict_types=1);

namespace App;

/**
 * Hot path do k-NN com IVF + scan de blocos quantizados em int16 (AoS).
 *
 * Para cada vetor temos 14 int16 contíguos (28 bytes) em Data::$blocks. Um
 * bloco = 8 vetores × 14 dims × 2 bytes = 224 bytes. Decoding é feito por
 * bloco com `unpack('s112', ..., off)` — uma única chamada por bloco.
 */
final class Knn
{
    private const FAST_NPROBE = 4;
    private const FULL_NPROBE = 24;
    private const D = 14;
    /** Sentinela INF (int 64). */
    private const INF = PHP_INT_MAX;

    /** Scale do Rust IVF1: f * 10000 → int16. */
    private const VECTOR_QUANT_SCALE = 10000.0;

    /**
     * @param array<int,float> $vec 14 floats vindos de Vector::vectorize().
     * @return int 0..5  contagem de vizinhos com label=1 entre os 5 NN.
     */
    public static function fraudCount(array $vec): int
    {
        $k = Data::$k;
        $centroids = Data::$centroids;
        $offsets = Data::$offsets;
        $labels = Data::$labels;
        $blocks = Data::$blocks;

        // 1) Quantize query para int16 (mesmo scale do IVF1 do Rust).
        $qi = [];
        for ($i = 0; $i < 14; $i++) {
            $q = (int) round($vec[$i] * self::VECTOR_QUANT_SCALE);
            if ($q < -32768) {
                $q = -32768;
            } elseif ($q > 32767) {
                $q = 32767;
            }
            $qi[$i] = $q;
        }

        // 2) Centroid distances (float32 AoS).  Inner loop desenrolado.
        $cdist = [];
        $v0=$vec[0]; $v1=$vec[1]; $v2=$vec[2]; $v3=$vec[3]; $v4=$vec[4];
        $v5=$vec[5]; $v6=$vec[6]; $v7=$vec[7]; $v8=$vec[8]; $v9=$vec[9];
        $v10=$vec[10]; $v11=$vec[11]; $v12=$vec[12]; $v13=$vec[13];
        for ($ci = 0; $ci < $k; $ci++) {
            $b = $ci * 14;
            $a0=$centroids[$b]-$v0; $a1=$centroids[$b+1]-$v1;
            $a2=$centroids[$b+2]-$v2; $a3=$centroids[$b+3]-$v3;
            $a4=$centroids[$b+4]-$v4; $a5=$centroids[$b+5]-$v5;
            $a6=$centroids[$b+6]-$v6; $a7=$centroids[$b+7]-$v7;
            $a8=$centroids[$b+8]-$v8; $a9=$centroids[$b+9]-$v9;
            $a10=$centroids[$b+10]-$v10; $a11=$centroids[$b+11]-$v11;
            $a12=$centroids[$b+12]-$v12; $a13=$centroids[$b+13]-$v13;
            $cdist[$ci] = $a0*$a0+$a1*$a1+$a2*$a2+$a3*$a3+$a4*$a4+$a5*$a5
                +$a6*$a6+$a7*$a7+$a8*$a8+$a9*$a9+$a10*$a10+$a11*$a11
                +$a12*$a12+$a13*$a13;
        }

        // 3) Top-N centroids → scan.
        $top = self::topNCentroids($cdist, $k, self::FAST_NPROBE);
        $count = self::scanBlocks($top, $offsets, $blocks, $labels, $qi);

        if ($count !== 2 && $count !== 3) {
            return $count;
        }

        $top = self::topNCentroids($cdist, $k, self::FULL_NPROBE);
        return self::scanBlocks($top, $offsets, $blocks, $labels, $qi);
    }

    /**
     * @param array<int,float> $cdist
     * @return array<int,int>  índices dos N centroides mais próximos.
     */
    private static function topNCentroids(array $cdist, int $k, int $n): array
    {
        $topD = array_fill(0, $n, INF);
        $topI = array_fill(0, $n, 0);
        $worst = INF;

        for ($ci = 0; $ci < $k; $ci++) {
            $d = $cdist[$ci];
            if ($d >= $worst) {
                continue;
            }
            $pos = $n - 1;
            while ($pos > 0 && $topD[$pos - 1] > $d) {
                $topD[$pos] = $topD[$pos - 1];
                $topI[$pos] = $topI[$pos - 1];
                $pos--;
            }
            $topD[$pos] = $d;
            $topI[$pos] = $ci;
            $worst = $topD[$n - 1];
        }

        return $topI;
    }

    /**
     * Scan: para cada probe, loop sobre blocos (8 vetores). Decode int16 do
     * bloco com `unpack('s112', ..., off)` numa só chamada e itera 8 vetores.
     *
     * @param array<int,int> $probes
     * @param array<int,int> $offsets
     * @param array<int,int> $qi  query int16
     */
    private static function scanBlocks(
        array $probes,
        array $offsets,
        string $blocks,
        string $labels,
        array $qi,
    ): int {
        $top5d = [self::INF, self::INF, self::INF, self::INF, self::INF];
        $top5l = [0, 0, 0, 0, 0];
        $worstIdx = 0;
        $worstVal = self::INF;

        $q0=$qi[0]; $q1=$qi[1]; $q2=$qi[2]; $q3=$qi[3]; $q4=$qi[4];
        $q5=$qi[5]; $q6=$qi[6]; $q7=$qi[7]; $q8=$qi[8]; $q9=$qi[9];
        $q10=$qi[10]; $q11=$qi[11]; $q12=$qi[12]; $q13=$qi[13];

        foreach ($probes as $ci) {
            $startBlk = $offsets[$ci];
            $endBlk = $offsets[$ci + 1];

            for ($bi = $startBlk; $bi < $endBlk; $bi++) {
                // 1 unpack/bloco: 112 int16 (8 vetores × 14 dims, AoS por vetor).
                $arr = unpack('s112', $blocks, $bi * 224);
                $vBase = $bi * 8;

                for ($s = 0; $s < 8; $s++) {
                    $b = $s * 14 + 1; // 1-indexed
                    $a0 = $arr[$b] - $q0;
                    $a1 = $arr[$b+1] - $q1;
                    $a2 = $arr[$b+2] - $q2;
                    $a3 = $arr[$b+3] - $q3;
                    $a4 = $arr[$b+4] - $q4;
                    $a5 = $arr[$b+5] - $q5;
                    $a6 = $arr[$b+6] - $q6;
                    $a7 = $arr[$b+7] - $q7;
                    $d = $a0*$a0+$a1*$a1+$a2*$a2+$a3*$a3
                       + $a4*$a4+$a5*$a5+$a6*$a6+$a7*$a7;
                    if ($d >= $worstVal) {
                        continue;
                    }
                    $a8 = $arr[$b+8] - $q8;
                    $a9 = $arr[$b+9] - $q9;
                    $a10 = $arr[$b+10] - $q10;
                    $a11 = $arr[$b+11] - $q11;
                    $a12 = $arr[$b+12] - $q12;
                    $a13 = $arr[$b+13] - $q13;
                    $d += $a8*$a8+$a9*$a9+$a10*$a10+$a11*$a11
                        + $a12*$a12+$a13*$a13;
                    if ($d >= $worstVal) {
                        continue;
                    }

                    // Insere no top-5 e re-encontra o pior.
                    $top5d[$worstIdx] = $d;
                    $top5l[$worstIdx] = ord($labels[$vBase + $s]);

                    $w = $top5d[0]; $wi = 0;
                    if ($top5d[1] > $w) { $w = $top5d[1]; $wi = 1; }
                    if ($top5d[2] > $w) { $w = $top5d[2]; $wi = 2; }
                    if ($top5d[3] > $w) { $w = $top5d[3]; $wi = 3; }
                    if ($top5d[4] > $w) { $w = $top5d[4]; $wi = 4; }
                    $worstIdx = $wi;
                    $worstVal = $w;
                }
            }
        }

        $count = 0;
        for ($j = 0; $j < 5; $j++) {
            if ($top5l[$j] === 1) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Aquece JIT/cache: gera 500 queries pseudo-aleatórias e processa
     * antes de /ready ficar 200.
     */
    public static function warmup(): void
    {
        $state = 0x12345678;
        for ($q = 0; $q < 500; $q++) {
            $vec = [];
            for ($i = 0; $i < 14; $i++) {
                $state = ($state * 1664525 + 1013904223) & 0xFFFFFFFF;
                $vec[$i] = (($state >> 8) & 0xFFFFFF) / (1 << 24);
            }
            self::fraudCount($vec);
        }
    }
}
