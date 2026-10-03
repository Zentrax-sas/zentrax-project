<?php
require_once __DIR__.'/AsignacionVehiculoTest.php';
require_once __DIR__.'/../config/database.php';

/** Optativa: solo escribe en una base nueva cuyo nombre genera este test. */
final class AsignacionVehiculoMariaDbTest extends AsignacionVehiculoTest
{
    private ?string $database=null;
    private static ?PDO $shared=null;
    private static ?string $sharedName=null;
    private static bool $installed=false;
    private string $sqlRoot=__DIR__.'/../../base-datos/database/sql/';
    protected function connect(): PDO {
        if (getenv('ZEMYNA_V19_MARIADB')!=='1') $this->markTestSkipped('MariaDB V19 aislada no habilitada.');
        if (self::$shared) { $this->database=self::$sharedName; return self::$shared; }
        $db=new PDO('mysql:host='.(getenv('DB_HOST')?:'localhost').';charset=utf8mb4',getenv('DB_USER')?:'root',getenv('DB_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->database='zemyna_v19_test_'.bin2hex(random_bytes(6));
        $db->exec('CREATE DATABASE '.$this->database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $db->exec('USE '.$this->database);
        fwrite(STDERR,"V19 isolated database: {$this->database}\n");
        self::$shared=$db; self::$sharedName=$this->database;
        return $db;
    }
    protected function schema(): void {
        if (!self::$installed) { $this->db->exec(file_get_contents($this->sqlRoot.'schema.sql')); self::$installed=true; return; }
        // Solo la base aleatoria creada por esta clase. Nunca admite nombre externo.
        if ($this->db->query('SELECT DATABASE()')->fetchColumn()!==self::$sharedName) throw new RuntimeException('Base aislada incorrecta.');
        $this->db->exec('DROP TRIGGER IF EXISTS fallo_v19');
        $tables=$this->db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $this->db->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->db->beginTransaction();
        try { foreach($tables as $table) { if (!preg_match('/^[a-z_]+$/D',$table)) throw new RuntimeException('Tabla inesperada.'); $this->db->exec('DELETE FROM `'.$table.'`'); } $this->db->commit(); }
        finally { if ($this->db->inTransaction()) $this->db->rollBack(); $this->db->exec('SET FOREIGN_KEY_CHECKS=1'); }
    }
    protected function seed(): void {
        $this->db->beginTransaction();
        try { parent::seed(); $this->db->commit(); }
        catch(Throwable $e) { $this->db->rollBack(); throw $e; }
    }
    protected function tearDown(): void {
        if (isset($this->db) && $this->db->inTransaction()) $this->db->rollBack();
        parent::tearDown();
    }
    public static function tearDownAfterClass(): void {
        if (self::$shared && self::$sharedName && preg_match('/^zemyna_v19_test_[a-f0-9]{12}$/D',self::$sharedName)) self::$shared->exec('DROP DATABASE '.self::$sharedName);
        self::$shared=null; self::$sharedName=null; self::$installed=false;
    }
    private function reject(string $sql): void {
        try { $this->db->exec($sql); $this->fail('La base debio rechazar la escritura.'); }
        catch(PDOException $e) { $this->assertNotEmpty($e->errorInfo); }
    }
    public function testMigracionV18AV19ReejecutableYConstraintsReales(): void {
        $this->db->exec('DROP TABLE asignacion_vehiculo_operativa');
        $this->db->exec('ALTER TABLE vehiculo DROP CONSTRAINT chk_vehiculo_funcion');
        $this->db->exec('ALTER TABLE vehiculo DROP COLUMN funcion_operativa');
        $this->db->exec(file_get_contents($this->sqlRoot.'migration_v19_asignacion_vehiculo.sql'));
        $this->assertSame(3,(int)$this->db->query('SELECT COUNT(*) FROM vehiculo WHERE funcion_operativa IS NULL')->fetchColumn());
        $this->assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM asignacion_vehiculo_operativa')->fetchColumn());
        $this->db->exec("UPDATE vehiculo SET funcion_operativa='REGULAR' WHERE id_vehiculo=1");
        $id=$this->open()['data']['id_asignacion_vehiculo'];
        $this->db->exec(file_get_contents($this->sqlRoot.'migration_v19_asignacion_vehiculo.sql'));
        $this->assertSame(1,(int)$this->db->query('SELECT COUNT(*) FROM asignacion_vehiculo_operativa')->fetchColumn());
        $this->assertSame('REGULAR',$this->db->query('SELECT funcion_operativa FROM vehiculo WHERE id_vehiculo=1')->fetchColumn());
        foreach(['regular','APOYO ','','OTRO'] as $bad) $this->reject('UPDATE vehiculo SET funcion_operativa='.$this->db->quote($bad).' WHERE id_vehiculo=2');
        $this->reject("INSERT INTO asignacion_vehiculo_operativa(id_cuadrilla,id_vehiculo,fecha_inicio,id_usuario_asigna) VALUES(1,2,NOW(),1)");
        $this->reject("INSERT INTO asignacion_vehiculo_operativa(id_cuadrilla,id_vehiculo,fecha_inicio,id_usuario_asigna) VALUES(2,1,NOW(),1)");
        $this->reject("INSERT INTO asignacion_vehiculo_operativa(id_cuadrilla,id_vehiculo,fecha_inicio,id_usuario_asigna) VALUES(999,2,NOW(),1)");
        $this->reject("UPDATE asignacion_vehiculo_operativa SET fecha_fin='2000-01-01',id_usuario_finaliza=1,motivo_cierre='Fin' WHERE id_asignacion_vehiculo=$id");
        $this->reject("UPDATE asignacion_vehiculo_operativa SET fecha_fin=NOW() WHERE id_asignacion_vehiculo=$id");
        $this->assertSame(0,(int)$this->db->query("SELECT COUNT(*) FROM rol_permiso rp JOIN rol r ON r.id_rol=rp.id_rol JOIN permiso p ON p.id_permiso=rp.id_permiso WHERE r.nombre='ADMINISTRADOR_TI' AND p.nombre LIKE 'asignacion_vehiculo.%'")->fetchColumn());
    }
    private function race(array $bodies, bool $vehicleGate=false): array {
        $workers=[]; $this->db->beginTransaction();
        $this->db->query($vehicleGate?'SELECT id_vehiculo FROM vehiculo WHERE id_vehiculo=1 FOR UPDATE':'SELECT id_usuario FROM usuario WHERE id_usuario=1 FOR UPDATE')->fetchAll();
        try {
            foreach($bodies as $body) {
                $process=proc_open([PHP_BINARY,__DIR__.'/fixtures/vehiculo_worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
                $this->assertIsResource($process); fwrite($pipes[0],json_encode(['database'=>$this->database,'body'=>$body])); fclose($pipes[0]);
                stream_set_timeout($pipes[1],15); $workers[]=[$process,$pipes]; $this->assertSame("READY\n",fgets($pipes[1]));
            }
            $deadline=microtime(true)+8;
            do {
                $waiting=(int)$this->db->query("SELECT COUNT(*) FROM information_schema.INNODB_TRX t JOIN information_schema.PROCESSLIST p ON p.ID=t.trx_mysql_thread_id WHERE t.trx_state='LOCK WAIT' AND p.DB=".$this->db->quote($this->database))->fetchColumn();
                if ($waiting===count($bodies)) break;
                usleep(20000);
            } while(microtime(true)<$deadline);
            $this->assertSame(count($bodies),$waiting,'Conexiones independientes en competencia real.');
            $this->db->commit(); $statuses=[];
            foreach($workers as [$process,$pipes]) {
                $result=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
                $this->assertSame(0,proc_close($process),$errors); $statuses[]=json_decode($result,true,512,JSON_THROW_ON_ERROR)['statusCode'];
            }
            $workers=[]; sort($statuses); return $statuses;
        } finally {
            if ($this->db->inTransaction()) $this->db->rollBack();
            foreach($workers as [$process,$pipes]) { proc_terminate($process); foreach($pipes as $p) if(is_resource($p)) fclose($p); proc_close($process); }
        }
    }
    public function testDosAperturasMismaCuadrilla(): void {
        $a=['accion'=>'abrir','id_cuadrilla'=>1,'id_vehiculo'=>1];
        $this->assertSame([200,409],$this->race([$a,array_replace($a,['id_vehiculo'=>2])]));
    }
    public function testDosAperturasMismoVehiculo(): void {
        $a=['accion'=>'abrir','id_cuadrilla'=>1,'id_vehiculo'=>1];
        $this->assertSame([200,409],$this->race([$a,array_replace($a,['id_cuadrilla'=>2])]));
    }
    public function testCambiosConcurrentes(): void {
        $id=$this->open()['data']['id_asignacion_vehiculo'];
        $a=['accion'=>'cambiar','id_cuadrilla'=>1,'id_vehiculo'=>2,'id_asignacion_vehiculo'=>$id,'motivo'=>'Cambio'];
        $this->assertSame([200,409],$this->race([$a,$a]));
        $this->assertSame(2,(int)$this->db->query('SELECT COUNT(*) FROM asignacion_vehiculo_operativa')->fetchColumn());
    }
    public function testCierresConcurrentes(): void {
        $id=$this->open()['data']['id_asignacion_vehiculo'];
        $a=['accion'=>'cerrar','id_cuadrilla'=>1,'id_asignacion_vehiculo'=>$id,'motivo'=>'Fin'];
        $this->assertSame([200,409],$this->race([$a,$a]));
    }
    public function testCambioRevierteCierreSiFallaInsercion(): void {
        $id=$this->open()['data']['id_asignacion_vehiculo'];
        $this->db->exec("CREATE TRIGGER fallo_v19 BEFORE INSERT ON asignacion_vehiculo_operativa FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fallo controlado'");
        $this->assertSame(503,$this->change($id)['statusCode']);
        $this->assertNull($this->db->query('SELECT fecha_fin FROM asignacion_vehiculo_operativa')->fetchColumn());
        $this->assertSame(1,(int)$this->db->query('SELECT COUNT(*) FROM asignacion_vehiculo_operativa')->fetchColumn());
    }
    public function testAperturaContraMantenimiento(): void {
        $a=['accion'=>'abrir','id_cuadrilla'=>1,'id_vehiculo'=>1];
        $b=['fixture_vehicle_body'=>['id_vehiculo'=>1,'matricula'=>'V1','marca'=>'Test','modelo'=>'Test','capacidad_carga'=>10,'estado'=>'En Mantenimiento','id_tipo_residuo'=>1,'funcion_operativa'=>'REGULAR']];
        $this->assertSame([200,409],$this->race([$a,$b],true));
        $this->assertSame(0,(int)$this->db->query("SELECT COUNT(*) FROM asignacion_vehiculo_operativa a JOIN vehiculo v ON v.id_vehiculo=a.id_vehiculo WHERE a.fecha_fin IS NULL AND v.estado='En Mantenimiento'")->fetchColumn());
    }
}
