<?php
declare(strict_types=1);
require_once __DIR__ . '/GrafoDijkstra.php';

final class Dijkstra
{
    /** Empates: menor identificador pendiente; igualdad no reemplaza predecesor. */
    public function shortestPath(GrafoDijkstra $graph, string $origin, string $destination): array
    {
        $graph->neighbors($origin);
        $graph->neighbors($destination);
        $ids = $graph->nodeIds();
        $distance = array_fill_keys($ids, INF);
        $previous = [];
        $settled = [];
        $distance[$origin] = 0.0;
        while (true) {
            $current = null;
            $best = INF;
            // ids está ordenado: el primer mínimo gana, incluso con pesos cero.
            foreach ($ids as $id) {
                if (!isset($settled[$id]) && $distance[$id] < $best) {
                    $current = $id;
                    $best = $distance[$id];
                }
            }
            if ($current === null) break; // No quedan nodos alcanzables.
            $settled[$current] = true;
            if ($current === $destination) break;
            foreach ($graph->neighbors($current) as $next => $weight) {
                if (isset($settled[$next])) continue;
                $candidate = $best + $weight;
                if (!is_finite($candidate)) throw new OverflowException('El costo acumulado excede el rango numérico.');
                if ($candidate < $distance[$next]) {
                    $distance[$next] = $candidate;
                    $previous[$next] = $current;
                }
            }
        }
        $reachable = isset($settled[$destination]);
        $path = [];
        if ($reachable) {
            $cursor = $destination;
            while (true) {
                $path[] = $cursor;
                if ($cursor === $origin) break;
                $cursor = $previous[$cursor];
            }
            $path = array_reverse($path);
        }
        return ['graph_version' => $graph->version(), 'cost_unit' => $graph->costUnit(),
            'origin' => $origin, 'destination' => $destination, 'reachable' => $reachable,
            'cost' => $reachable ? $distance[$destination] : null, 'path' => $path];
    }
}
