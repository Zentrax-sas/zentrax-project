<?php
use PHPUnit\Framework\TestCase;

final class MapExperienceTest extends TestCase
{
    public function testComportamientoJavaScriptDeAmbasVistas(): void
    {
        $process = proc_open(['node', '--test', __DIR__ . '/../../frontend/tests/map-experience.test.cjs'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output . $errors);
        $this->assertStringContainsString('# fail 0', $output);
    }
}
