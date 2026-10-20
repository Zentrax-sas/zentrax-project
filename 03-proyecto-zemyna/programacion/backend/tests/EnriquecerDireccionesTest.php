<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../database/scripts/enriquecer_direcciones_contenedores.php';

final class EnriquecerDireccionesTest extends TestCase {
    private function row(): array {
        return ['id_contenedor' => 7, 'codigo' => 'IDM-123', 'direccion' => 'Ubicación importada IdM (sin dirección catastral) — X: 568763.75766979, Y: 6145387.033279', 'latitud' => '-34.8', 'longitud' => '-56.2'];
    }
    private function geo(?string $address): Geocodificacion {
        $geo = $this->getMockBuilder(Geocodificacion::class)->disableOriginalConstructor()->onlyMethods(['resolver'])->getMock();
        $geo->method('resolver')->willReturn(['source' => 'cache', 'data' => ['direccion' => $address], 'requests' => 0, 'error' => null]);
        return $geo;
    }
    private function statement(): PDOStatement {
        $stmt = $this->getMockBuilder(PDOStatement::class)->disableOriginalConstructor()->getMock();
        $stmt->method('bindValue')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        return $stmt;
    }
    public function testArgumentosYValoresInvalidos(): void {
        $this->assertSame(['limit' => 20, 'start-id' => 1, 'dry-run' => false], opcionesEnriquecimiento(['batch']));
        $this->assertSame(['limit' => 10, 'start-id' => 42, 'dry-run' => true], opcionesEnriquecimiento(['batch', '--dry-run', '--limit=10', '--start-id', '42']));
        foreach ([['--limit=0'], ['--limit=-1'], ['--start-id=hola'], ['--limit'], ['--all']] as $args) {
            try { opcionesEnriquecimiento(array_merge(['batch'], $args)); $this->fail('Debio rechazar argumentos'); }
            catch (InvalidArgumentException $error) { $this->assertNotEmpty($error->getMessage()); }
        }
    }
    public function testDryRunNoActualizaNiCreaBackup(): void {
        $db = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->getMock();
        $select = $this->statement(); $select->method('fetchAll')->willReturn([$this->row()]);
        $select->expects($this->exactly(3))->method('bindValue')->with($this->anything(), $this->callback(function ($value) {
            if (is_string($value)) $this->assertSame('Ubicación importada IdM (sin dirección catastral)%', $value);
            return true;
        }), $this->anything());
        $db->expects($this->once())->method('prepare')->willReturnCallback(function ($sql) use ($select) {
            $this->assertStringContainsString('direccion LIKE :placeholder', $sql);
            $this->assertStringContainsString('latitud <> 0 AND longitud <> 0', $sql);
            $this->assertStringContainsString('latitud BETWEEN -90 AND 90', $sql);
            $this->assertStringContainsString('longitud BETWEEN -180 AND 180', $sql);
            $this->assertStringContainsString('ORDER BY id_contenedor ASC LIMIT :limit', $sql);
            return $select;
        });
        $db->expects($this->never())->method('beginTransaction');
        $geo = $this->getMockBuilder(Geocodificacion::class)->disableOriginalConstructor()->onlyMethods(['resolver', 'findCache', 'saveCache'])->getMock();
        $geo->expects($this->never())->method('resolver');
        $geo->expects($this->never())->method('saveCache');
        $geo->expects($this->once())->method('findCache')->willReturn(['direccion' => '123, Miguel Martínez, Buceo, Montevideo, Uruguay', 'barrio' => 'Buceo', 'localidad' => 'Montevideo']);
        $result = enriquecerDirecciones($db, $geo, ['limit' => 10, 'start-id' => 1, 'dry-run' => true], __DIR__ . '/no-crear');
        $this->assertSame(1, $result['valid_street']);
        $this->assertSame(0, $result['updated']);
        $this->assertNull($result['backup']);
        $this->assertSame($this->row()['direccion'], $result['rows'][0]['original']);
        $this->assertSame('-34.8', $result['rows'][0]['lat']);
        $this->assertSame('-56.2', $result['rows'][0]['lon']);
        $this->assertSame('cache', $result['rows'][0]['source']);
        $this->assertSame('would_update', $result['rows'][0]['action']);
    }
    public function testDryRunSinCacheNoConsultaApiNiEscribe(): void {
        foreach ([null, ['direccion' => 'Buceo, Montevideo, Uruguay']] as $cache) {
            $db = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->getMock();
            $select = $this->statement(); $select->method('fetchAll')->willReturn([$this->row()]);
            $db->expects($this->once())->method('prepare')->willReturn($select);
            $db->expects($this->never())->method('beginTransaction');
            $geo = $this->getMockBuilder(Geocodificacion::class)->disableOriginalConstructor()->onlyMethods(['resolver', 'findCache', 'saveCache'])->getMock();
            $geo->expects($this->never())->method('resolver');
            $geo->expects($this->never())->method('saveCache');
            $geo->expects($this->once())->method('findCache')->willReturn($cache);
            $result = enriquecerDirecciones($db, $geo, ['limit' => 500, 'start-id' => 124, 'dry-run' => true], __DIR__ . '/no-crear');
            $this->assertSame(1, $result['requires_api']);
            $this->assertSame(0, $result['external_requests']);
            $this->assertSame(0, $result['without_street']);
            $this->assertSame(0, $result['updated']);
            $this->assertSame('would_query_api', $result['rows'][0]['action']);
        }
    }
    public function testCandidatoVacioOMarcadorNuncaActualiza(): void {
        foreach (['', '   ', 'sin dirección catastral', $this->row()['direccion']] as $address) {
            $db = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->getMock();
            $select = $this->statement(); $select->method('fetchAll')->willReturn([$this->row()]);
            $db->method('prepare')->willReturn($select);
            $db->expects($this->never())->method('beginTransaction');
            $result = enriquecerDirecciones($db, $this->geo($address), ['limit' => 20, 'start-id' => 4, 'dry-run' => false], __DIR__ . '/no-crear');
            $this->assertSame(1, $result['without_street']);
            $this->assertSame(0, $result['updated']);
        }
    }
    public function testSinViaNoActualiza(): void {
        $db = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->getMock();
        $select = $this->statement(); $select->method('fetchAll')->willReturn([$this->row()]);
        $db->method('prepare')->willReturn($select);
        $db->expects($this->never())->method('beginTransaction');
        $result = enriquecerDirecciones($db, $this->geo(null), ['limit' => 20, 'start-id' => 1, 'dry-run' => false], __DIR__ . '/no-crear');
        $this->assertSame(1, $result['without_street']); $this->assertSame(0, $result['updated']);
    }
    public function testActualizacionConBackupPrevioYComparacionOriginal(): void {
        $this->comprobarUpdate(1);
    }
    public function testRowCountCeroSeOmiteSinReintentar(): void {
        $this->comprobarUpdate(0);
    }
    private function comprobarUpdate(int $affected): void {
        $dir = sys_get_temp_dir() . '/zemyna-backup-test-' . bin2hex(random_bytes(6));
        $backup = $dir . '/contenedores_direccion_reverse_backup.sql';
        $db = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->getMock();
        $select = $this->statement(); $select->method('fetchAll')->willReturn([$this->row()]);
        $locked = $this->statement(); $locked->method('fetch')->willReturn($this->row());
        $update = $this->getMockBuilder(PDOStatement::class)->disableOriginalConstructor()->getMock();
        $update->expects($this->once())->method('execute')->willReturnCallback(function ($params) use ($backup) {
            $this->assertFileExists($backup);
            $sql = file_get_contents($backup);
            $this->assertStringContainsString(bin2hex($this->row()['direccion']), $sql);
            $this->assertStringContainsString('id_contenedor=7', $sql);
            $this->assertSame($this->row()['direccion'], $params[':direccion_original']);
            $this->assertArrayNotHasKey(':placeholder', $params);
            return true;
        });
        $update->method('rowCount')->willReturn($affected);
        $db->method('prepare')->willReturnCallback(function ($sql) use ($select, $locked, $update) {
            if (str_starts_with($sql, 'UPDATE')) {
                $this->assertStringContainsString('BINARY direccion=:direccion_original', $sql);
                $this->assertStringNotContainsString('latitud=', $sql);
                return $update;
            }
            return str_contains($sql, 'FOR UPDATE') ? $locked : $select;
        });
        $db->expects($this->once())->method('commit')->willReturn(true);
        try {
            $result = enriquecerDirecciones($db, $this->geo('Miguel Martínez 123'), ['limit' => 20, 'start-id' => 1, 'dry-run' => false], $dir);
            $this->assertSame($affected, $result['updated']);
            $this->assertSame(1 - $affected, $result['skipped_changed']);
            $this->assertSame($affected ? 'updated' : 'skipped_changed', $result['rows'][0]['action']);
            $this->assertSame(8, $result['next_start_id']);
        } finally { if (is_file($backup)) unlink($backup); if (is_dir($dir)) rmdir($dir); }
    }
    public function testNoSobrescribeDireccionCambiadaDuranteConsulta(): void {
        $db = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->getMock();
        $select = $this->statement(); $select->method('fetchAll')->willReturn([$this->row()]);
        $locked = $this->statement(); $row = $this->row(); $row['direccion'] = 'Direccion editada';
        $locked->method('fetch')->willReturn($row);
        $db->method('prepare')->willReturnCallback(fn($sql) => str_contains($sql, 'FOR UPDATE') ? $locked : $select);
        $db->expects($this->never())->method('commit');
        $db->expects($this->once())->method('rollBack')->willReturn(true);
        $result = enriquecerDirecciones($db, $this->geo('Miguel Martínez'), ['limit' => 20, 'start-id' => 1, 'dry-run' => false], __DIR__ . '/no-crear');
        $this->assertSame(1, $result['conflicts']); $this->assertSame(0, $result['updated']);
    }
}
