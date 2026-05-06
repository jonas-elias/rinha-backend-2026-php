<?php
/**
 * Pure-PHP k-means + IVF index builder.
 *
 * Algoritmo idêntico ao build_index.rs original:
 *   - kmeans++ init com sample de 50 000 vetores (LCG seed 0xdeadbeef_cafebabe)
 *   - 25 iterações Lloyd full-batch (early-exit se < 0.1% mudam)
 *   - escreve direto IVF6: centroids f32 AoS + offsets u32 + labels u8 +
 *     blocks i16 AoS  (=> consumido por lib/Data.php sem requantize)
 *
 * Multi-process: paraleliza a fase `assign` via pcntl_fork. Cada filho
 * processa um slice de [start..end) do dataset, escreve assignments num
 * arquivo dedicado, parent merge no final.
 *
 * Uso: php scripts/build_index.php <refs.bin> <index.bin> [n_workers]
 */
declare(strict_types=1);

ini_set('memory_limit', '1G');

const D = 14;
const K = 2048;
const N_ITER = 20;
const SAMPLE_INIT = 50_000;
const VECTOR_QUANT_SCALE = 10000.0;
const I16_MAX = 32767;
const I16_MIN = -32768;

[$argv0, $in, $out, $workersArg] = array_pad($argv, 4, null);
if ($in === null || $out === null) {
    fwrite(STDERR, "usage: build_index.php <refs.bin> <index.bin> [n_workers]\n");
    exit(1);
}
$nWorkers = (int) ($workersArg ?? (int) (getenv('IDX_WORKERS') ?: 4));
if ($nWorkers < 1) {
    $nWorkers = 1;
}

if (!extension_loaded('pcntl')) {
    fwrite(STDERR, "pcntl required\n");
    exit(1);
}

// ---- 1. Carrega refs.bin (mmap-style: lemos labels e mantemos vectors em string)

$tStart = hrtime(true);
$refsSize = filesize($in);
fwrite(STDERR, "[build] loading $in ($refsSize bytes)...\n");

$fh = fopen($in, 'rb');
$magic = fread($fh, 4);
if ($magic !== 'REFS') {
    fwrite(STDERR, "bad refs magic\n");
    exit(1);
}
$n = unpack('V', fread($fh, 4))[1];
$labels = fread($fh, $n);                 // n bytes
$vectorsBin = stream_get_contents($fh);   // n*14*4 bytes
fclose($fh);

if (strlen($vectorsBin) !== $n * D * 4) {
    fwrite(STDERR, "vectors size mismatch: " . strlen($vectorsBin) . " vs " . ($n * D * 4) . "\n");
    exit(1);
}
fwrite(STDERR, sprintf("[build] %d vectors loaded in %.1fs\n", $n, (hrtime(true) - $tStart) / 1e9));

// ---- 2. kmeans++ init com sample de 50 000

fwrite(STDERR, "[build] kmeans++ init (sample=" . min($n, SAMPLE_INIT) . ")...\n");
$tInit = hrtime(true);

$sampleSize = min($n, SAMPLE_INIT);
$lcgSeedHi = 0xdeadbeef;
$lcgSeedLo = 0xcafebabe;
$rng = new Lcg($lcgSeedHi, $lcgSeedLo);

// Pré-extrai o sample como flat array de floats (sampleSize * 14)
$sample = [];
for ($i = 0; $i < $sampleSize; $i++) {
    $vi = $rng->nextUsize($n);
    // unpack devolve 1-indexed
    $arr = unpack('f14', $vectorsBin, $vi * D * 4);
    for ($d = 1; $d <= D; $d++) {
        $sample[] = $arr[$d];
    }
}

// Centroides ficam num único array flat (k*14)
$first = $rng->nextUsize($sampleSize);
$centroids = array_fill(0, K * D, 0.0);
for ($d = 0; $d < D; $d++) {
    $centroids[$d] = $sample[$first * D + $d];
}

$minDists = array_fill(0, $sampleSize, INF);

for ($ck = 1; $ck < K; $ck++) {
    $base = ($ck - 1) * D;
    $c0 = $centroids[$base]; $c1 = $centroids[$base+1]; $c2 = $centroids[$base+2];
    $c3 = $centroids[$base+3]; $c4 = $centroids[$base+4]; $c5 = $centroids[$base+5];
    $c6 = $centroids[$base+6]; $c7 = $centroids[$base+7]; $c8 = $centroids[$base+8];
    $c9 = $centroids[$base+9]; $c10 = $centroids[$base+10]; $c11 = $centroids[$base+11];
    $c12 = $centroids[$base+12]; $c13 = $centroids[$base+13];
    $total = 0.0;
    for ($i = 0; $i < $sampleSize; $i++) {
        $b = $i * D;
        $a0=$sample[$b]-$c0; $a1=$sample[$b+1]-$c1; $a2=$sample[$b+2]-$c2;
        $a3=$sample[$b+3]-$c3; $a4=$sample[$b+4]-$c4; $a5=$sample[$b+5]-$c5;
        $a6=$sample[$b+6]-$c6; $a7=$sample[$b+7]-$c7; $a8=$sample[$b+8]-$c8;
        $a9=$sample[$b+9]-$c9; $a10=$sample[$b+10]-$c10; $a11=$sample[$b+11]-$c11;
        $a12=$sample[$b+12]-$c12; $a13=$sample[$b+13]-$c13;
        $dd = $a0*$a0+$a1*$a1+$a2*$a2+$a3*$a3+$a4*$a4+$a5*$a5+$a6*$a6
            +$a7*$a7+$a8*$a8+$a9*$a9+$a10*$a10+$a11*$a11+$a12*$a12+$a13*$a13;
        if ($dd < $minDists[$i]) {
            $minDists[$i] = $dd;
        }
        $total += $minDists[$i];
    }

    // amostra com prob proporcional a min_dist
    $r = $rng->nextF64() * $total;
    $cum = 0.0;
    $chosen = $sampleSize - 1;
    for ($i = 0; $i < $sampleSize; $i++) {
        $cum += $minDists[$i];
        if ($cum >= $r) {
            $chosen = $i;
            break;
        }
    }
    $cb = $ck * D;
    $sb = $chosen * D;
    for ($d = 0; $d < D; $d++) {
        $centroids[$cb + $d] = $sample[$sb + $d];
    }

    if (($ck & 0xFF) === 0) {
        fwrite(STDERR, sprintf("  centroid %d/%d  (%.1fs)\n", $ck, K, (hrtime(true) - $tInit) / 1e9));
    }
}
unset($sample, $minDists);
fwrite(STDERR, sprintf("[build] init done in %.1fs\n", (hrtime(true) - $tInit) / 1e9));

// ---- 3. Lloyd iterations com fork

$assignments = str_repeat("\x00\x00", $n);  // u16 LE * n

for ($iter = 0; $iter < N_ITER; $iter++) {
    $tIter = hrtime(true);

    // 3a) Empacota centroides como string binária pra fork passar barato.
    $centroidsBin = pack('f' . (K * D), ...$centroids);

    // 3b) Fork pool: cada child computa um slice e escreve em arquivo próprio
    //     (assignments parciais + counter de "changed").
    $chunk = (int) ceil($n / $nWorkers);
    $tmpDir = sys_get_temp_dir();
    $childFiles = [];
    $pids = [];

    for ($w = 0; $w < $nWorkers; $w++) {
        $startV = $w * $chunk;
        $endV = min($startV + $chunk, $n);
        $childFiles[$w] = "$tmpDir/asg-$w-" . getmypid() . ".bin";

        $pid = pcntl_fork();
        if ($pid === -1) {
            fwrite(STDERR, "fork failed\n");
            exit(1);
        }
        if ($pid === 0) {
            assignSlice($vectorsBin, $centroidsBin, substr($assignments, $startV * 2, ($endV - $startV) * 2),
                $startV, $endV, $childFiles[$w]);
            exit(0);
        }
        $pids[$w] = $pid;
    }

    // Espera filhos e merge
    $changed = 0;
    for ($w = 0; $w < $nWorkers; $w++) {
        pcntl_waitpid($pids[$w], $status);
        $part = file_get_contents($childFiles[$w]);
        unlink($childFiles[$w]);
        $partChanged = unpack('V', substr($part, 0, 4))[1];
        $partAsg = substr($part, 4);
        $startV = $w * $chunk;
        $assignments = substr_replace($assignments, $partAsg, $startV * 2, strlen($partAsg));
        $changed += $partChanged;
    }

    // 3c) Update centroids — single proc (rápido: 3M passos float).
    updateCentroids($vectorsBin, $assignments, $centroids, $n);

    $sec = (hrtime(true) - $tIter) / 1e9;
    fwrite(STDERR, sprintf(
        "  iter %2d: %5.2f%% changed in %.2fs\n",
        $iter + 1, $changed * 100.0 / $n, $sec
    ));

    // early-exit: < 0.5% changed (k-means já convergiu funcionalmente).
    if ($iter >= 5 && $changed * 200 < $n) {
        fwrite(STDERR, "[build] early-exit (converged)\n");
        break;
    }
}

// ---- 4. Escreve IVF6 direto (centroides AoS + blocks int16 AoS)

fwrite(STDERR, "[build] writing IVF6 to $out...\n");
$tWrite = hrtime(true);

// 4a) Agrupa vetores por centroide
$clusters = array_fill(0, K, []);
for ($i = 0; $i < $n; $i++) {
    $a = ord($assignments[$i * 2]) | (ord($assignments[$i * 2 + 1]) << 8);
    $clusters[$a][] = $i;
}

// 4b) offsets[k+1] em unidades de bloco (8 vetores)
$offsets = [];
$offsets[0] = 0;
for ($ci = 0; $ci < K; $ci++) {
    $sz = count($clusters[$ci]);
    $offsets[$ci + 1] = $offsets[$ci] + intdiv($sz + 7, 8);
}
$totalBlocks = $offsets[K];
$paddedN = $totalBlocks * 8;

// 4c) Constrói labels[paddedN] e blocks[paddedN*14]:i16 AoS
$outLabels = str_repeat("\x00", $paddedN);
$outBlocks = str_repeat("\x00", $paddedN * D * 2);

for ($ci = 0; $ci < K; $ci++) {
    $vecs = $clusters[$ci];
    $cnt = count($vecs);
    $blockBase = $offsets[$ci];
    for ($bk = 0; $bk < intdiv($cnt + 7, 8); $bk++) {
        $vBase = ($blockBase + $bk) * 8;
        for ($slot = 0; $slot < 8; $slot++) {
            $idx = $bk * 8 + $slot;
            if ($idx < $cnt) {
                $vi = $vecs[$idx];
                $vec = unpack('f14', $vectorsBin, $vi * D * 4);
                $byteOff = ($vBase + $slot) * D * 2;
                for ($d = 1; $d <= D; $d++) {
                    $q = (int) round($vec[$d] * VECTOR_QUANT_SCALE);
                    if ($q < I16_MIN) {
                        $q = I16_MIN;
                    } elseif ($q > I16_MAX) {
                        $q = I16_MAX;
                    }
                    $u = $q & 0xFFFF;
                    $outBlocks[$byteOff] = chr($u & 0xFF);
                    $outBlocks[$byteOff + 1] = chr(($u >> 8) & 0xFF);
                    $byteOff += 2;
                }
                $outLabels[$vBase + $slot] = $labels[$vi];
            } else {
                // padding: i16::MAX em todas as 14 dims
                $byteOff = ($vBase + $slot) * D * 2;
                for ($d = 0; $d < D; $d++) {
                    $outBlocks[$byteOff] = "\xFF";
                    $outBlocks[$byteOff + 1] = "\x7F";
                    $byteOff += 2;
                }
            }
        }
    }
}

// 4d) Header + centroides AoS (já estão AoS no nosso array $centroids)
$centroidsBin = pack('f' . (K * D), ...$centroids);
$offsetsBin = pack('V' . (K + 1), ...$offsets);

$dst = fopen($out, 'wb');
fwrite($dst, "IVF6" . pack('VVV', $n, K, D));
fwrite($dst, $centroidsBin);
fwrite($dst, $offsetsBin);
fwrite($dst, $outLabels);
fwrite($dst, $outBlocks);
fclose($dst);

fwrite(STDERR, sprintf(
    "[build] index.bin: %.1f MB (padded_n=%d, total_blocks=%d) in %.1fs\n",
    filesize($out) / 1048576, $paddedN, $totalBlocks, (hrtime(true) - $tWrite) / 1e9
));
fwrite(STDERR, sprintf("[build] TOTAL: %.1fs\n", (hrtime(true) - $tStart) / 1e9));

// =============================================================================
// Helpers
// =============================================================================

/**
 * Atribui cada vetor do slice ao centroide mais próximo. Saída: string binária
 * `[changed:u32 LE][assignments:u16 LE * n_slice]`.
 */
function assignSlice(
    string $vectorsBin,
    string $centroidsBin,
    string $oldAssignments,
    int $startV,
    int $endV,
    string $outFile
): void {
    $k = K;
    $d = D;
    // Desempacota centroides em flat array
    $centroidsArr = unpack('f' . ($k * $d), $centroidsBin);
    $centroids = array_values($centroidsArr);

    $nSlice = $endV - $startV;
    $newAsg = str_repeat("\x00\x00", $nSlice);
    $changed = 0;

    for ($i = 0; $i < $nSlice; $i++) {
        $vi = $startV + $i;
        $arr = unpack('f14', $vectorsBin, $vi * $d * 4);
        $v0=$arr[1]; $v1=$arr[2]; $v2=$arr[3]; $v3=$arr[4]; $v4=$arr[5];
        $v5=$arr[6]; $v6=$arr[7]; $v7=$arr[8]; $v8=$arr[9]; $v9=$arr[10];
        $v10=$arr[11]; $v11=$arr[12]; $v12=$arr[13]; $v13=$arr[14];

        // Upper-bound aperta: começa pelo centroide da iteração anterior.
        $oldA = ord($oldAssignments[$i * 2]) | (ord($oldAssignments[$i * 2 + 1]) << 8);
        $b = $oldA * $d;
        $a0=$centroids[$b]-$v0; $a1=$centroids[$b+1]-$v1;
        $a2=$centroids[$b+2]-$v2; $a3=$centroids[$b+3]-$v3;
        $a4=$centroids[$b+4]-$v4; $a5=$centroids[$b+5]-$v5;
        $a6=$centroids[$b+6]-$v6; $a7=$centroids[$b+7]-$v7;
        $a8=$centroids[$b+8]-$v8; $a9=$centroids[$b+9]-$v9;
        $a10=$centroids[$b+10]-$v10; $a11=$centroids[$b+11]-$v11;
        $a12=$centroids[$b+12]-$v12; $a13=$centroids[$b+13]-$v13;
        $bestDist = $a0*$a0+$a1*$a1+$a2*$a2+$a3*$a3+$a4*$a4+$a5*$a5+$a6*$a6
            +$a7*$a7+$a8*$a8+$a9*$a9+$a10*$a10+$a11*$a11+$a12*$a12+$a13*$a13;
        $bestIdx = $oldA;

        for ($ci = 0; $ci < $k; $ci++) {
            if ($ci === $oldA) continue;
            $b = $ci * $d;
            $a0=$centroids[$b]-$v0; $a1=$centroids[$b+1]-$v1;
            $a2=$centroids[$b+2]-$v2; $a3=$centroids[$b+3]-$v3;
            $a4=$centroids[$b+4]-$v4; $a5=$centroids[$b+5]-$v5;
            $a6=$centroids[$b+6]-$v6; $a7=$centroids[$b+7]-$v7;
            // early exit parcial (8 dims)
            $dd = $a0*$a0+$a1*$a1+$a2*$a2+$a3*$a3+$a4*$a4+$a5*$a5+$a6*$a6+$a7*$a7;
            if ($dd >= $bestDist) {
                continue;
            }
            $a8=$centroids[$b+8]-$v8; $a9=$centroids[$b+9]-$v9;
            $a10=$centroids[$b+10]-$v10; $a11=$centroids[$b+11]-$v11;
            $a12=$centroids[$b+12]-$v12; $a13=$centroids[$b+13]-$v13;
            $dd += $a8*$a8+$a9*$a9+$a10*$a10+$a11*$a11+$a12*$a12+$a13*$a13;
            if ($dd < $bestDist) {
                $bestDist = $dd;
                $bestIdx = $ci;
            }
        }

        if ($oldA !== $bestIdx) {
            $changed++;
        }
        $newAsg[$i * 2] = chr($bestIdx & 0xFF);
        $newAsg[$i * 2 + 1] = chr(($bestIdx >> 8) & 0xFF);
    }

    file_put_contents($outFile, pack('V', $changed) . $newAsg);
}

function updateCentroids(string $vectorsBin, string $assignments, array &$centroids, int $n): void
{
    $k = K;
    $d = D;
    $sums = array_fill(0, $k * $d, 0.0);
    $counts = array_fill(0, $k, 0);

    for ($i = 0; $i < $n; $i++) {
        $a = ord($assignments[$i * 2]) | (ord($assignments[$i * 2 + 1]) << 8);
        $arr = unpack('f14', $vectorsBin, $i * $d * 4);
        $b = $a * $d;
        $sums[$b]    += $arr[1];  $sums[$b+1]  += $arr[2];
        $sums[$b+2]  += $arr[3];  $sums[$b+3]  += $arr[4];
        $sums[$b+4]  += $arr[5];  $sums[$b+5]  += $arr[6];
        $sums[$b+6]  += $arr[7];  $sums[$b+7]  += $arr[8];
        $sums[$b+8]  += $arr[9];  $sums[$b+9]  += $arr[10];
        $sums[$b+10] += $arr[11]; $sums[$b+11] += $arr[12];
        $sums[$b+12] += $arr[13]; $sums[$b+13] += $arr[14];
        $counts[$a]++;
    }
    for ($ci = 0; $ci < $k; $ci++) {
        $c = $counts[$ci];
        if ($c === 0) {
            continue;
        }
        $b = $ci * $d;
        $inv = 1.0 / $c;
        $centroids[$b]    = $sums[$b]    * $inv;
        $centroids[$b+1]  = $sums[$b+1]  * $inv;
        $centroids[$b+2]  = $sums[$b+2]  * $inv;
        $centroids[$b+3]  = $sums[$b+3]  * $inv;
        $centroids[$b+4]  = $sums[$b+4]  * $inv;
        $centroids[$b+5]  = $sums[$b+5]  * $inv;
        $centroids[$b+6]  = $sums[$b+6]  * $inv;
        $centroids[$b+7]  = $sums[$b+7]  * $inv;
        $centroids[$b+8]  = $sums[$b+8]  * $inv;
        $centroids[$b+9]  = $sums[$b+9]  * $inv;
        $centroids[$b+10] = $sums[$b+10] * $inv;
        $centroids[$b+11] = $sums[$b+11] * $inv;
        $centroids[$b+12] = $sums[$b+12] * $inv;
        $centroids[$b+13] = $sums[$b+13] * $inv;
    }
}

/**
 * LCG simples (Numerical Recipes, mod 2^32). Não precisa bater bit-a-bit com
 * o Rust — só precisa dar uma sequência determinística e bem distribuída.
 */
final class Lcg
{
    private int $state;

    public function __construct(int $hi, int $lo)
    {
        $this->state = ($hi ^ $lo) & 0xFFFFFFFF;
        if ($this->state === 0) {
            $this->state = 1;
        }
    }

    public function nextU32(): int
    {
        $this->state = (($this->state * 1664525) + 1013904223) & 0xFFFFFFFF;
        return $this->state;
    }

    public function nextUsize(int $n): int
    {
        return $this->nextU32() % $n;
    }

    public function nextF64(): float
    {
        // 32 bits altos / 2^32
        return $this->nextU32() / 4294967296.0;
    }
}
