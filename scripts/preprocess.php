<?php
/**
 * Streaming gz JSON-array parser.
 *
 * O arquivo de entrada é um único array JSON gigante de objetos
 * `{"vector":[14 floats],"label":"legit"|"fraud"}` (~300 MB descomprimidos,
 * 3 M registros). Não cabe carregar tudo na memória, então tokenizamos por
 * registro: lemos o gz em blocos, encontramos o próximo "}" balanceado e
 * `json_decode` apenas o registro.
 *
 * Saída: arquivo binário compacto que o build_index.php carrega via mmap-style
 *   "REFS"  + n:u32 +
 *   labels[n]:u8 +
 *   vectors[n*14]:f32 (AoS)
 *
 * Uso: php scripts/preprocess.php resources/references.json.gz data/refs.bin
 */
declare(strict_types=1);

ini_set('memory_limit', '512M');

[$argv0, $in, $out] = array_pad($argv, 3, null);
if ($in === null || $out === null) {
    fwrite(STDERR, "usage: preprocess.php <refs.json.gz> <refs.bin>\n");
    exit(1);
}

$gz = gzopen($in, 'rb');
if ($gz === false) {
    fwrite(STDERR, "open failed: $in\n");
    exit(1);
}

$tmp = $out . '.tmp';
$fh = fopen($tmp, 'wb');
if ($fh === false) {
    fwrite(STDERR, "open failed: $tmp\n");
    exit(1);
}

// Reservamos o cabeçalho ("REFS" + n:u32). Preencheremos n no final.
fwrite($fh, str_repeat("\x00", 8));

$labels = '';   // bytes
$vectors = '';  // packed f32

$buf = '';
$nRecords = 0;
$t0 = hrtime(true);

// Pula o '[' inicial.
while (true) {
    $chunk = gzread($gz, 65536);
    if ($chunk === false || $chunk === '') {
        fwrite(STDERR, "empty stream\n");
        exit(1);
    }
    $buf .= $chunk;
    $p = strpos($buf, '[');
    if ($p !== false) {
        $buf = substr($buf, $p + 1);
        break;
    }
}

while (true) {
    // Garante chunks grandes o bastante pra encontrar um "{" e seu "}" par.
    while (strlen($buf) < 4096) {
        $chunk = gzread($gz, 65536);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $buf .= $chunk;
    }

    // Acha próximo "{"
    $start = strpos($buf, '{');
    if ($start === false) {
        break;
    }
    // Acha próximo "}" — como nossos registros não têm objetos aninhados,
    // o primeiro "}" depois de "{" é o fim do registro.
    $end = strpos($buf, '}', $start);
    if ($end === false) {
        // precisa mais bytes
        $chunk = gzread($gz, 65536);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $buf .= $chunk;
        continue;
    }

    $rec = substr($buf, $start, $end - $start + 1);
    $buf = substr($buf, $end + 1);

    $obj = json_decode($rec, true);
    if (!is_array($obj) || !isset($obj['vector'], $obj['label'])) {
        fwrite(STDERR, "bad record at #$nRecords: $rec\n");
        exit(1);
    }

    $v = $obj['vector'];
    if (count($v) !== 14) {
        fwrite(STDERR, "bad vector len at #$nRecords\n");
        exit(1);
    }

    $labels .= chr($obj['label'] === 'fraud' ? 1 : 0);
    $vectors .= pack('f14', ...$v);
    $nRecords++;

    // Flush incremental pra não estourar memória
    if (($nRecords & 0xFFFF) === 0) {
        fwrite($fh, $vectors);
        $vectors = '';
        if (($nRecords & 0xFFFFF) === 0) {
            $sec = (hrtime(true) - $t0) / 1e9;
            fwrite(STDERR, sprintf("  parsed %d records (%.1fs)\n", $nRecords, $sec));
        }
    }
}

if ($vectors !== '') {
    fwrite($fh, $vectors);
}

// Volta no header e escreve "REFS" + n.
fseek($fh, 0);
fwrite($fh, "REFS" . pack('V', $nRecords));
fclose($fh);
gzclose($gz);

// labels vão como segundo arquivo (concatenamos no final)
// — na verdade vamos juntar tudo em um único arquivo:
//   header (8B) + labels (nB) + vectors (n*56B)
// Atualmente escrevemos header (8B) + vectors. Precisamos inserir os labels
// entre o header e os vectors. Em arquivos grandes (~170 MB), reescrever é
// caro mas inevitável; vamos fazer streaming: read + write.
fwrite(STDERR, "reordering output (inserting labels)...\n");
$src = fopen($tmp, 'rb');
$dst = fopen($out, 'wb');
fwrite($dst, "REFS" . pack('V', $nRecords));
fwrite($dst, $labels);
unset($labels);
fseek($src, 8); // pula header
while (!feof($src)) {
    $c = fread($src, 1 << 20);
    if ($c === false || $c === '') break;
    fwrite($dst, $c);
}
fclose($src);
fclose($dst);
unlink($tmp);

$totSec = (hrtime(true) - $t0) / 1e9;
fwrite(STDERR, sprintf(
    "[preprocess] %d records, %.1f MB written in %.1fs\n",
    $nRecords,
    filesize($out) / 1048576,
    $totSec
));
