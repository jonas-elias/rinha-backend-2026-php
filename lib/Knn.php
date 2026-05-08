<?php
declare(strict_types=1);

namespace App;

/**
 * Wrapper PHP para o hot path de k-NN implementado em C (knn.so via FFI).
 *
 * O cálculo de distância, varredura de centroides e blocos int16 são feitos
 * inteiramente em código nativo — sem unpack/array PHP no caminho crítico.
 */
final class Knn
{
    private const FAST_NPROBE = 8;
    private const FULL_NPROBE = 32;

    /**
     * @param array<int,float> $vec 14 floats vindos de Vector::vectorize().
     * @return int 0..5  contagem de vizinhos com label=1 entre os 5 NN.
     */
    public static function fraudCount(array $vec): int
    {
        $q = Data::$cQuery;
        for ($i = 0; $i < 14; $i++) {
            $q[$i] = $vec[$i];
        }

        return Data::$ffi->knn_fraud_count(
            Data::$cCentroids,
            Data::$cBlocks,
            Data::$cLabels,
            Data::$cOffsets,
            $q,
            Data::$k,
            self::FAST_NPROBE,
            self::FULL_NPROBE
        );
    }

    /**
     * Aquece JIT: executa algumas queries antes de /ready retornar 200.
     * Com FFI o JIT tem pouco impacto no C, mas ajuda o código PHP wrapper.
     */
    public static function warmup(): void
    {
        $state = 0x12345678;
        for ($q = 0; $q < 5; $q++) {
            $vec = [];
            for ($i = 0; $i < 14; $i++) {
                $state = ($state * 1664525 + 1013904223) & 0xFFFFFFFF;
                $vec[$i] = (($state >> 8) & 0xFFFFFF) / (1 << 24);
            }
            self::fraudCount($vec);
        }
    }
}
