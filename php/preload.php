<?php
declare(strict_types=1);

/**
 * Pré-carregado pelo OPcache. Garante que Json/Vector/Data/Knn fiquem
 * compilados no SHM antes do primeiro request — sem custo de require em
 * runtime, sem redundância entre workers.
 */

$libDir = is_dir(__DIR__ . '/lib') ? __DIR__ . '/lib' : __DIR__ . '/../lib';

require_once $libDir . '/Vector.php';
require_once $libDir . '/Data.php';
require_once $libDir . '/Knn.php';

opcache_compile_file($libDir . '/Vector.php');
opcache_compile_file($libDir . '/Data.php');
opcache_compile_file($libDir . '/Knn.php');
