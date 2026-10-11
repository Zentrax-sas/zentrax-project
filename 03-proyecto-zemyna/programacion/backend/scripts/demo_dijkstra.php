<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/Dijkstra.php';

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    $graph = GrafoDijkstra::fromFile(__DIR__ . '/../data/grafos/f5-demo-v1.json');
    $result = (new Dijkstra())->shortestPath($graph, $argv[1] ?? 'A', $argv[2] ?? 'E');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
