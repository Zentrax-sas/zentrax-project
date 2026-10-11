<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../services/Dijkstra.php';

final class DijkstraTest extends TestCase
{
    private function demo(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/../data/grafos/f5-demo-v1.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    private function runGraph(array $data, string $from = 'A', string $to = 'E'): array
    {
        return (new Dijkstra())->shortestPath(new GrafoDijkstra($data), $from, $to);
    }
    public function testKnownMinimumAndReconstruction(): void
    {
        $data = $this->demo();
        $result = $this->runGraph($data);
        $this->assertSame(['A', 'B', 'D', 'E'], $result['path']);
        $this->assertSame(6.0, $result['cost']);
        $this->assertTrue($result['reachable']);
        $this->assertSame('f5-demo-v1', $result['graph_version']);
        $this->assertSame($data['cost_unit'], $result['cost_unit']);
        $sum = 0.0;
        $graph = new GrafoDijkstra($data);
        for ($i = 1; $i < count($result['path']); $i++) {
            $sum += $graph->neighbors($result['path'][$i - 1])[$result['path'][$i]];
        }
        $this->assertSame($result['cost'], $sum);
    }
    public function testDirectedEdgesAndDisconnectedDestination(): void
    {
        foreach ([['E', 'A'], ['A', 'F'], ['F', 'A']] as [$from, $to]) {
            $result = $this->runGraph($this->demo(), $from, $to);
            $this->assertFalse($result['reachable']);
            $this->assertNull($result['cost']);
            $this->assertSame([], $result['path']);
        }
        $this->assertSame(4.0, $this->runGraph($this->demo(), 'E', 'D')['cost']);
        $this->assertSame(1.0, $this->runGraph($this->demo(), 'D', 'E')['cost']);
    }
    public function testSameOriginIncludingIsolatedNode(): void
    {
        foreach (['A', 'F'] as $id) {
            $result = $this->runGraph($this->demo(), $id, $id);
            $this->assertTrue($result['reachable']);
            $this->assertSame(0.0, $result['cost']);
            $this->assertSame([$id], $result['path']);
        }
    }
    public function testMissingNodesAreRejected(): void
    {
        foreach ([['unknown', 'E'], ['A', 'unknown'], ['unknown', 'unknown'], ['', 'E']] as [$from, $to]) {
            try { $this->runGraph($this->demo(), $from, $to); $this->fail('Nodo inválido aceptado.'); }
            catch (InvalidArgumentException $error) { $this->assertSame('Nodo inexistente.', $error->getMessage()); }
        }
    }
    public function testInvalidWeightsAreRejected(): void
    {
        foreach ([-1, NAN, INF, -INF, '2', null, true, []] as $weight) {
            $data = $this->demo(); $data['edges'][0]['weight'] = $weight;
            try { new GrafoDijkstra($data); $this->fail('Peso inválido aceptado.'); }
            catch (InvalidArgumentException $error) { $this->assertStringContainsString('número finito', $error->getMessage()); }
        }
    }
    public function testZeroWeightsAndCyclesDoNotBreakPredecessors(): void
    {
        $data = $this->demo();
        $data['edges'] = [
            ['from' => 'A', 'to' => 'B', 'weight' => 0],
            ['from' => 'B', 'to' => 'A', 'weight' => 0],
            ['from' => 'B', 'to' => 'B', 'weight' => 0],
            ['from' => 'B', 'to' => 'C', 'weight' => 0],
            ['from' => 'C', 'to' => 'B', 'weight' => 0],
            ['from' => 'C', 'to' => 'E', 'weight' => 0.5],
            ['from' => 'A', 'to' => 'E', 'weight' => 1],
        ];
        $result = $this->runGraph($data);
        $this->assertSame(['A', 'B', 'C', 'E'], $result['path']);
        $this->assertSame(0.5, $result['cost']);
        $this->assertSame(0.0, $this->runGraph($data, 'A', 'C')['cost']);
    }
    public function testTiesAreReproducibleAndIndependentOfInputOrder(): void
    {
        $data = $this->demo(); $expected = $this->runGraph($data);
        $this->assertSame(['A', 'B', 'D', 'E'], $expected['path']);
        for ($i = 0; $i < count($data['edges']); $i++) {
            $data['edges'][] = array_shift($data['edges']);
            $data['nodes'] = array_reverse($data['nodes']);
            $this->assertSame($expected, $this->runGraph($data));
        }
        $data['edges'] = array_reverse($data['edges']);
        $this->assertSame($expected, $this->runGraph($data));
    }
    public function testDemoJsonMetadataAndOptionalCoordinates(): void
    {
        $graph = GrafoDijkstra::fromFile(__DIR__ . '/../data/grafos/f5-demo-v1.json');
        $this->assertCount(6, $graph->nodeIds());
        $this->assertSame('simulación académica', $this->demo()['classification']);
        $data = $this->demo(); unset($data['nodes'][0]['coordinates']);
        $this->assertSame(6.0, $this->runGraph($data)['cost']);
        $data['edges'] = [];
        $this->assertFalse($this->runGraph($data)['reachable']);
    }
    public function testMalformedAndNonfiniteJsonAreRejected(): void
    {
        foreach (['{', '{"weight":NaN}', '{"weight":Infinity}'] as $json) {
            try { GrafoDijkstra::fromJson($json); $this->fail('JSON inválido aceptado.'); }
            catch (JsonException $error) { $this->assertNotSame('', $error->getMessage()); }
        }
        $json = json_encode($this->demo(), JSON_THROW_ON_ERROR);
        $json = str_replace('"weight":2', '"weight":1e999', $json);
        $this->expectException(InvalidArgumentException::class);
        GrafoDijkstra::fromJson($json);
    }
    public function testInvalidGraphStructuresAreRejected(): void
    {
        $invalid = [];
        $d = $this->demo(); unset($d['version']); $invalid[] = $d;
        $d = $this->demo(); $d['cost_unit'] = ''; $invalid[] = $d;
        $d = $this->demo(); $d['extra'] = true; $invalid[] = $d;
        $d = $this->demo(); $d['nodes'] = []; $invalid[] = $d;
        $d = $this->demo(); $d['nodes'][] = $d['nodes'][0]; $invalid[] = $d;
        $d = $this->demo(); $d['nodes'][0]['id'] = '1'; $invalid[] = $d;
        $d = $this->demo(); $d['nodes'][0]['coordinates']['x'] = INF; $invalid[] = $d;
        $d = $this->demo(); $d['edges'][0]['to'] = 'unknown'; $invalid[] = $d;
        $d = $this->demo(); unset($d['edges'][0]['weight']); $invalid[] = $d;
        $d = $this->demo(); $d['edges'][] = $d['edges'][0]; $invalid[] = $d;
        foreach ($invalid as $data) {
            try { new GrafoDijkstra($data); $this->fail('Grafo inválido aceptado.'); }
            catch (InvalidArgumentException $error) { $this->assertNotSame('', $error->getMessage()); }
        }
        foreach (['[]', '{}', '{"nodes":{},"edges":[]}'] as $json) {
            try { GrafoDijkstra::fromJson($json); $this->fail('Estructura JSON inválida aceptada.'); }
            catch (InvalidArgumentException $error) { $this->assertNotSame('', $error->getMessage()); }
        }
    }
    public function testAccumulatedOverflowIsExplicit(): void
    {
        $data = $this->demo();
        $data['edges'] = [['from' => 'A', 'to' => 'B', 'weight' => PHP_FLOAT_MAX],
            ['from' => 'B', 'to' => 'E', 'weight' => PHP_FLOAT_MAX]];
        $this->expectException(OverflowException::class);
        $this->runGraph($data);
    }
    public function testMissingFileIsExplicit(): void
    {
        $this->expectException(RuntimeException::class);
        GrafoDijkstra::fromFile(__DIR__ . '/f5-does-not-exist.json');
    }
}
