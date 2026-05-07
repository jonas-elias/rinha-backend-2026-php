<?php
declare(strict_types=1);
require '/app/lib/Data.php';
require '/app/lib/Vector.php';
require '/app/lib/Knn.php';

\App\Data::init();
echo "OK n=" . \App\Data::$n . " k=" . \App\Data::$k . "\n";

$vec = array_fill(0, 14, 0.5);
$result = \App\Knn::fraudCount($vec);
echo "smoke fraudCount([0.5*14]) = $result\n";
assert($result >= 0 && $result <= 5, "fraudCount out of range");
echo "sanity PASSED\n";
