<?php
declare(strict_types=1);

$libDir = is_dir(__DIR__ . '/lib') ? __DIR__ . '/lib' : __DIR__ . '/../lib';

require_once $libDir . '/Vector.php';
require_once $libDir . '/Data.php';
require_once $libDir . '/Knn.php';

use App\Data;
use App\Vector;
use App\Knn;

$sock = getenv('SOCK') ?: '/run/sock/api1.sock';

if (file_exists($sock)) {
    @unlink($sock);
}

// 1) Carrega o índice quantizado (~45 MB) ANTES de aceitar conexões.
Data::init();

// 2) Aquece o JIT/cache de páginas com 500 queries pseudo-aleatórias.
$tw = hrtime(true);
Knn::warmup();
fwrite(STDERR, sprintf("[server] warmup %.2f ms\n", (hrtime(true) - $tw) / 1e6));

$server = new Swoole\Http\Server($sock, 0, SWOOLE_BASE, SWOOLE_SOCK_UNIX_STREAM);

$server->set([
    'worker_num'        => 4,
    'reactor_num'       => 1,
    'tcp_fastopen'      => true,
    'open_tcp_nodelay'  => true,
    'enable_coroutine'  => false,
    'log_level'         => SWOOLE_LOG_WARNING,
    'http_compression'  => false,
    'send_yield'        => false,
    'open_http2_protocol' => false,
    'max_request'       => 0,
    'max_conn'          => 8192,
    'buffer_output_size' => 2 * 1024 * 1024,
]);

// Mesmos 6 corpos estáticos da versão Rust.
$BODIES = [
    '{"approved":true,"fraud_score":0.0}',
    '{"approved":true,"fraud_score":0.2}',
    '{"approved":true,"fraud_score":0.4}',
    '{"approved":false,"fraud_score":0.6}',
    '{"approved":false,"fraud_score":0.8}',
    '{"approved":false,"fraud_score":1.0}',
];

$server->on('start', function () use ($sock) {
    @chmod($sock, 0666);
    fwrite(STDERR, "[server] listening on $sock\n");
});

$server->on('request', function (Swoole\Http\Request $req, Swoole\Http\Response $resp) use ($BODIES) {
    $uri = $req->server['request_uri'] ?? '';
    $method = $req->server['request_method'] ?? '';

    if ($uri === '/fraud-score' && $method === 'POST') {
        $body = $req->rawContent();
        if ($body === false || $body === '' || $body === null) {
            $resp->status(400);
            $resp->end('');
            return;
        }
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            $resp->status(400);
            $resp->end('');
            return;
        }

        $vec = Vector::vectorize($payload);
        $idx = Knn::fraudCount($vec);

        if ($idx > 5) {
            $idx = 5;
        }
        $resp->header('Content-Type', 'application/json', false);
        $resp->end($BODIES[$idx]);
        return;
    }

    if ($uri === '/ready' && $method === 'GET') {
        $resp->status(200);
        $resp->end('');
        return;
    }

    $resp->status(404);
    $resp->end('');
});

$server->start();
