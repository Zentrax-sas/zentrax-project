<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../models/AtencionIncidencia.php';
require_once __DIR__ . '/../controllers/RecoleccionController.php';

/** Optativa. Solo admite servidor descartable bajo TEMP/zemyna-f42-*/
final class AtencionIncidenciaMariaDbTest extends TestCase
{
    private ?PDO $db = null;
    private string $database;
    private string $sqlRoot = __DIR__ . '/../../base-datos/database/sql/';
    protected function setUp(): void {
        $dsn=getenv('ZEMYNA_F42_MARIADB_DSN');
        if (!$dsn) $this->markTestSkipped('MariaDB F4.2 descartable no configurada.');
        $this->db=new PDO($dsn,'root',getenv('ZEMYNA_F42_MARIADB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $datadir=str_replace('\\','/',(string)$this->db->query('SELECT @@datadir')->fetchColumn());
        $temp=rtrim(str_replace('\\','/',sys_get_temp_dir()),'/').'/';
        if (!str_starts_with(strtolower($datadir),strtolower($temp)) || !preg_match('~/zemyna-f42-[a-f0-9]+/data/$~i',$datadir)) {
            $this->db=null;
            $this->fail('Se rechazó el servidor: no es MariaDB temporal F4.2.');
        }
        $this->database='f42_test_'.bin2hex(random_bytes(6));
        $this->db->exec('CREATE DATABASE '.$this->database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->db->exec('USE '.$this->database);
        $this->db->exec(file_get_contents($this->sqlRoot.'schema.sql'));
        $this->db->exec("INSERT INTO centro(id_centro,nombre,direccion) VALUES(1,'Prueba','Temporal');
            INSERT INTO usuario(id_usuario,nombre,apellido,email,contrasena,fecha_registro,id_centro) VALUES
            (1,'Uno','Test','uno@test.invalid','x','2020-01-01',1),(2,'Dos','Test','dos@test.invalid','x','2020-01-01',1),(3,'Tres','Test','tres@test.invalid','x','2020-01-01',1);
            INSERT INTO rol(id_rol,nombre) VALUES(1,'OPERARIO'),(2,'ADMINISTRADOR_TI'),(3,'RESPONSABLE_SECTORIAL'),(4,'ADMINISTRATIVO_OPERATIVO');
            INSERT INTO permiso(nombre) VALUES('recorrido.consultar'),('recorrido.operar');
            INSERT INTO rol_permiso SELECT 1,id_permiso FROM permiso WHERE nombre LIKE 'recorrido.%';
            INSERT INTO usuario_rol(id_usuario,id_rol,sector,fecha_desde) VALUES(1,1,'OPERACIONES','2020-01-01'),(2,1,'OPERACIONES','2020-01-01'),(3,1,'OPERACIONES','2020-01-01');
            INSERT INTO cuadrilla(id_cuadrilla,nombre,turno,id_centro) VALUES(1,'Uno','Matutino',1),(2,'Dos','Matutino',1);
            INSERT INTO usuario_cuadrilla(id_usuario,id_cuadrilla,fecha_inicio,id_usuario_asigna) VALUES(1,1,'2020-01-01',1),(2,1,'2020-01-01',1),(3,2,'2020-01-01',1);
            INSERT INTO ruta(id_ruta,nombre,zona) VALUES(1,'Uno','Test'),(2,'Dos','Test');
            INSERT INTO tipo_residuo(id_tipo_residuo,nombre) VALUES(1,'Test');
            INSERT INTO vehiculo(id_vehiculo,id_tipo_residuo,matricula,marca,modelo,capacidad_carga,estado) VALUES(1,1,'TEST1','Test','Test',1,'Disponible'),(2,1,'TEST2','Test','Test',1,'Disponible');
            INSERT INTO usa(id_usa,id_cuadrilla,id_vehiculo) VALUES(1,1,1),(2,2,2);
            INSERT INTO recorrido(id_recorrido,fecha_inicio,estado,id_ruta) VALUES(1,'2026-09-01','Pendiente',1),(2,'2026-09-01','Pendiente',2);
            INSERT INTO participa(id_usa,id_recorrido,hora_inicio) VALUES(1,1,'08:00:00'),(2,2,'08:00:00');
            INSERT INTO incidencia(id_incidencia,tracking_number,descripcion,fecha_reporte,estado,prioridad,tipo_problema,id_ruta,id_cuadrilla) VALUES
            (1,'INC-TEST1','Prueba','2026-09-01','Pendiente','Alta','Contenedor Desbordado',1,1),
            (2,'INC-TEST2','Resuelta','2026-09-01','Resuelta','Alta','Contenedor Desbordado',1,1),
            (3,'INC-TEST3','Sin asignar','2026-09-01','Pendiente','Alta','Contenedor Desbordado',1,NULL);");
        // Simular instalación v17: ejecutar la creación real de v18, no solo IF NOT EXISTS.
        $this->db->exec('DROP TABLE atencion_incidencia');
        $this->db->exec(file_get_contents($this->sqlRoot.'migration_v18_atencion_incidencia.sql'));
    }
    protected function tearDown(): void {
        if ($this->db && isset($this->database)) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->db->exec('DROP DATABASE '.$this->database);
        }
        $this->db=null;
    }
    private function request(string $action='aceptar_incidencia', string $expected='Asignada', int $user=1): array {
        return ['database'=>$this->database,'user'=>$user,'admin'=>false,'body'=>['accion'=>$action,'id_incidencia'=>1,
            'id_atencion_incidencia'=>(int)$this->db->query('SELECT MAX(id_atencion_incidencia) FROM atencion_incidencia WHERE id_incidencia=1')->fetchColumn(),
            'estado_operativo_esperado'=>$expected]+($action==='rechazar_incidencia'?['motivo'=>'Sin equipo']:[])];
    }
    /** Arrancar dos procesos PHP reales; ambos esperan el bloqueo de la incidencia. */
    private function race(array $requests, bool $membership=false): array {
        $this->db->beginTransaction();
        if ($membership) {
            $this->db->query('SELECT id_usuario FROM usuario WHERE id_usuario=1 FOR UPDATE')->fetchAll();
            $this->db->exec("UPDATE usuario_cuadrilla SET fecha_fin='2026-09-30',id_usuario_finaliza=1 WHERE id_usuario=1 AND fecha_fin IS NULL");
        } else $this->db->query('SELECT id_incidencia FROM incidencia WHERE id_incidencia=1 FOR UPDATE')->fetchAll();
        $workers=[];
        try {
            foreach ($requests as $request) {
                $process=proc_open([PHP_BINARY,__DIR__.'/fixtures/atencion_worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
                $this->assertIsResource($process);
                fwrite($pipes[0],json_encode($request,JSON_THROW_ON_ERROR)); fclose($pipes[0]);
                stream_set_timeout($pipes[1],15);
                $workers[]=[$process,$pipes];
                $this->assertSame("READY\n",fgets($pipes[1]));
            }
            $deadline=microtime(true)+8;
            do {
                $waiting=(int)$this->db->query("SELECT COUNT(*) FROM information_schema.INNODB_TRX t
                    JOIN information_schema.PROCESSLIST p ON p.ID=t.trx_mysql_thread_id
                    WHERE t.trx_state='LOCK WAIT' AND p.DB=".$this->db->quote($this->database))->fetchColumn();
                if ($waiting===count($requests)) break;
                usleep(20000);
            } while (microtime(true)<$deadline);
            $this->assertSame(count($requests),$waiting,'Los procesos deben competir realmente por un bloqueo.');
            $this->db->commit();
            $results=[];
            foreach ($workers as [$process,$pipes]) {
                $output=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                $this->assertSame(0,proc_close($process),$errors);
                $results[]=json_decode($output,true,512,JSON_THROW_ON_ERROR);
            }
            $workers=[];
            return $results;
        } finally {
            if ($this->db->inTransaction()) $this->db->rollBack();
            foreach ($workers as [$process,$pipes]) {
                if (is_resource($process)) proc_terminate($process);
                foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
                if (is_resource($process)) proc_close($process);
            }
        }
    }
    public function testMigracionRealReejecutableYPermisosSinTi(): void {
        $this->db->exec(file_get_contents($this->sqlRoot.'migration_v18_atencion_incidencia.sql'));
        $rows=(new AtencionIncidencia($this->db))->history(1);
        $this->assertCount(1,$rows); $this->assertSame('Migracion',$rows[0]['origen']);
        $this->assertNull($rows[0]['id_usuario_registra']); $this->assertNull($rows[0]['fecha_aceptacion']);
        $this->assertSame(1,(int)$this->db->query('SELECT COUNT(*) FROM atencion_incidencia')->fetchColumn());
        $roles=$this->db->query("SELECT r.nombre FROM rol r JOIN rol_permiso rp ON rp.id_rol=r.id_rol JOIN permiso p ON p.id_permiso=rp.id_permiso WHERE p.nombre='incidencia.operar' ORDER BY r.nombre")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['ADMINISTRATIVO_OPERATIVO','OPERARIO','RESPONSABLE_SECTORIAL'],$roles);
    }
    public function testConstraintsUniqueFkYHitosReales(): void {
        foreach ([
            "INSERT INTO atencion_incidencia(id_incidencia,id_cuadrilla,estado,origen,fecha_registro) VALUES(1,1,'Asignada','Migracion',NOW())",
            "INSERT INTO atencion_incidencia(id_incidencia,id_cuadrilla,estado,origen,fecha_registro) VALUES(999,1,'Asignada','Migracion',NOW())",
            "UPDATE atencion_incidencia SET estado='Finalizada' WHERE id_incidencia=1",
            "UPDATE atencion_incidencia SET estado='Rechazada',fecha_cierre=NOW(),id_usuario_cierra=1,motivo_cierre='' WHERE id_incidencia=1",
            'DELETE FROM incidencia WHERE id_incidencia=1'
        ] as $sql) {
            try { $this->db->exec($sql); $this->fail('La restricción no impidió: '.$sql); }
            catch (PDOException $e) { $this->assertContains((int)$e->errorInfo[1],[1062,1451,1452,4025]); }
        }
    }
    public function testAceptacionesConcurrentes(): void {
        $results=$this->race([$this->request(),$this->request(user:2)]);
        $statuses=array_column($results,'statusCode'); sort($statuses); $this->assertSame([200,409],$statuses);
        $row=(new AtencionIncidencia($this->db))->history(1)[0];
        $winner=$results[0]['statusCode']===200?1:2;
        $this->assertSame($winner,(int)$row['id_usuario_acepta']);
    }
    public function testAceptarRechazarConcurrentes(): void {
        $results=$this->race([$this->request(),$this->request('rechazar_incidencia',user:2)]);
        $statuses=array_column($results,'statusCode'); sort($statuses);
        $this->assertContains($statuses,[[200,409],[200,404]]);
        $row=(new AtencionIncidencia($this->db))->history(1)[0];
        $squad=$this->db->query('SELECT id_cuadrilla FROM incidencia WHERE id_incidencia=1')->fetchColumn();
        if ($row['estado']==='Rechazada') { $this->assertNull($squad); $this->assertNull($row['fecha_aceptacion']); }
        else { $this->assertSame('Aceptada',$row['estado']); $this->assertSame(1,(int)$squad); $this->assertNull($row['fecha_cierre']); }
    }
    public function testReasignacionContraAceptacion(): void {
        $assign=['database'=>$this->database,'user'=>3,'admin'=>true,'body'=>['accion'=>'asignar','id_incidencia'=>1,'id_cuadrilla'=>2,
            'id_recorrido'=>2,'id_usa'=>2,'cuadrilla_esperada'=>1,'estado_esperado'=>'Pendiente']];
        $results=$this->race([$this->request(),$assign]);
        $this->assertSame(200,$results[1]['statusCode']); $this->assertContains($results[0]['statusCode'],[200,404]);
        $rows=(new AtencionIncidencia($this->db))->history(1);
        $this->assertCount(2,$rows); $this->assertSame('Asignada',$rows[0]['estado']); $this->assertSame(2,(int)$rows[0]['id_cuadrilla']);
        $this->assertSame('Interrumpida',$rows[1]['estado']);
    }
    public function testInicioYFinalizacionConcurrentes(): void {
        $service=new AtencionIncidencia($this->db); $id=$this->request()['body']['id_atencion_incidencia'];
        $service->operate(1,1,$id,'Asignada','aceptar_incidencia',null);
        $results=$this->race([$this->request('iniciar_atencion_incidencia','Aceptada'),$this->request('iniciar_atencion_incidencia','Aceptada',2)]);
        $statuses=array_column($results,'statusCode'); sort($statuses); $this->assertSame([200,409],$statuses);
        $results=$this->race([$this->request('finalizar_atencion_incidencia','En atención'),$this->request('finalizar_atencion_incidencia','En atención',2)]);
        $statuses=array_column($results,'statusCode'); sort($statuses); $this->assertSame([200,409],$statuses);
        $row=$service->history(1)[0];
        $this->assertSame('Finalizada',$row['estado']);
        $this->assertSame($row['fecha_cierre'],$this->db->query('SELECT fecha_resolucion FROM incidencia WHERE id_incidencia=1')->fetchColumn());
    }
    public function testCambioDePertenenciaSerializa(): void {
        $results=$this->race([$this->request()],true);
        $this->assertSame(409,$results[0]['statusCode']);
        $this->assertSame('sin_pertenencia',$results[0]['code']);
        $this->assertSame('Asignada',(new AtencionIncidencia($this->db))->history(1)[0]['estado']);
    }

    public function testRollbackRealSiFallaLaSegundaEscritura(): void {
        $before=(new AtencionIncidencia($this->db))->history(1);
        $this->db->exec("CREATE TRIGGER fail_f42 BEFORE UPDATE ON incidencia FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='private f42'");
        $previous=$_SESSION ?? [];
        try {
            $_SESSION=['usuario'=>['id_usuario'=>1]];
            $response=(new RecoleccionController($this->db))->modificar($this->request('rechazar_incidencia')['body']);
            $this->assertSame(503,$response['statusCode']);
            $this->assertStringNotContainsString('private f42',json_encode($response));
            $this->assertSame($before,(new AtencionIncidencia($this->db))->history(1));
            $this->assertSame(1,(int)$this->db->query('SELECT id_cuadrilla FROM incidencia WHERE id_incidencia=1')->fetchColumn());
            $this->assertFalse($this->db->inTransaction());
        } finally { $_SESSION=$previous; }
    }
}
