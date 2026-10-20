<?php
require_once __DIR__.'/fixtures/mariadb_sql.php';
require_once __DIR__.'/SolicitudesPropiasTest.php';
require_once __DIR__.'/../controllers/AsignacionVehiculoController.php';
require_once __DIR__.'/../controllers/VehiculoController.php';
require_once __DIR__.'/../controllers/UsaController.php';
/** Servidor TEMP descartable obligatorio; jamás usa configuración de la BD habitual. */
final class ProteccionSolicitudOperativaMariaDbTest extends SolicitudesPropiasTest {
    private string $database;
    private static ?PDO $shared = null;
    private static ?string $sharedName = null;
    private static bool $installed = false;
    protected function connect(): PDO {
        if(!getenv('ZEMYNA_F63_MARIADB_DSN'))$this->markTestSkipped('MariaDB F6.3 descartable no configurada.');
        if(self::$shared){$this->database=self::$sharedName;return self::$shared;}
        $db=$this->other(false);$dir=str_replace('\\','/',$db->query('SELECT @@datadir')->fetchColumn());
        if(!str_starts_with(strtolower($dir),strtolower(str_replace('\\','/',sys_get_temp_dir()).'/')) || !preg_match('~/zemyna-f6[123]-[a-f0-9]+/data/$~i',$dir))$this->fail('Servidor no descartable rechazado.');
        $this->database='f63_test_'.bin2hex(random_bytes(6));$db->exec('CREATE DATABASE '.$this->database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$db->exec('USE '.$this->database);self::$shared=$db;self::$sharedName=$this->database;return $db;
    }
    private function other(bool $select=true): PDO {
        $db=new PDO(getenv('ZEMYNA_F63_MARIADB_DSN'),'root',getenv('ZEMYNA_F63_MARIADB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);if($select)$db->exec('USE '.$this->database);return $db;
    }
    protected function install(): void {
        if($this->db->query('SELECT DATABASE()')->fetchColumn()!==self::$sharedName || !preg_match('/^f63_test_[a-f0-9]{12}$/D',$this->database))throw new RuntimeException('Base descartable incorrecta.');
        if(!self::$installed){$this->db->exec(mariaDbSqlForPdo(file_get_contents(__DIR__.'/../../base-datos/database/sql/schema.sql')));self::$installed=true;}
        else {
            $this->db->exec('DROP TRIGGER IF EXISTS fail_f63');$this->db->exec('SET FOREIGN_KEY_CHECKS=0');
            try{foreach($this->db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t){if(!preg_match('/^[a-z_]+$/D',$t))throw new RuntimeException('Tabla inesperada');$this->db->exec('DELETE FROM `'.$t.'`');}}
            finally{$this->db->exec('SET FOREIGN_KEY_CHECKS=1');}
        }
        $this->seed();
    }
    public static function tearDownAfterClass(): void {
        if(self::$shared && self::$sharedName && preg_match('/^f63_test_[a-f0-9]{12}$/D',self::$sharedName))self::$shared->exec('DROP DATABASE '.self::$sharedName);
        self::$shared=null;self::$sharedName=null;self::$installed=false;
    }
    private function start(string $kind,array $body): array {
        $p=proc_open([PHP_BINARY,__DIR__.'/fixtures/f63c_worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);$this->assertIsResource($p);fwrite($pipes[0],json_encode(['database'=>$this->database,'kind'=>$kind,'body'=>$body]));fclose($pipes[0]);stream_set_timeout($pipes[1],20);$this->assertSame("READY\n",fgets($pipes[1]));return [$p,$pipes];
    }
    private function finish(array $w): array {[$p,$pipes]=$w;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$this->assertSame(0,proc_close($p),$err);return json_decode($out,true,512,JSON_THROW_ON_ERROR);}
    private function waiting(int $count): void {
        $deadline=microtime(true)+8;do{$n=(int)$this->db->query("SELECT COUNT(*) FROM information_schema.INNODB_TRX t JOIN information_schema.PROCESSLIST p ON p.ID=t.trx_mysql_thread_id WHERE t.trx_state='LOCK WAIT' AND p.DB=".$this->db->quote($this->database))->fetchColumn();if($n===$count)break;usleep(20000);}while(microtime(true)<$deadline);$this->assertSame($count,$n);
    }
    private function cleanup(array $workers): void {if($this->db->inTransaction())$this->db->rollBack();foreach($workers as [$p,$pipes]){proc_terminate($p);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($p);}}


    private function acceptWork(): void {$this->prepared();$this->assertSame(200,$this->op()->put($this->ownBody('aceptar_solicitud'))['statusCode']);}
    private function startWork(): void {$this->acceptWork();$this->assertSame(200,$this->op()->put($this->ownBody('iniciar_atencion_solicitud'))['statusCode']);}
    private function vehicleBody(int $residue=1): array {return ['id_vehiculo'=>1,'matricula'=>'TEST1','marca'=>'Descriptive','modelo'=>'Test','capacidad_carga'=>1,'estado'=>'Disponible','id_tipo_residuo'=>$residue,'funcion_operativa'=>'APOYO'];}
    private function v19(string $action='cerrar'): array {return array_merge(['accion'=>$action,'id_cuadrilla'=>1,'id_asignacion_vehiculo'=>1,'motivo'=>'Test'],$action==='cambiar'?['id_vehiculo'=>2]:[]);}
    private function resource(string $kind,array $body): array {
        $_SESSION=['usuario'=>['id_usuario'=>1]];
        return match($kind){'v19'=>(new AsignacionVehiculoController($this->db))->handle('POST',$body),'vehicle'=>(new VehiculoController($this->db))->update($body),
            'use_delete'=>(new UsaController($this->db))->delete($body['id_usa']),'use_update'=>(new UsaController($this->db))->update($body),'use_create'=>(new UsaController($this->db))->create($body)};
    }
    private function resourceState(): array {return [$this->state(),$this->db->query('SELECT * FROM asignacion_vehiculo_operativa ORDER BY id_asignacion_vehiculo')->fetchAll(PDO::FETCH_ASSOC),$this->db->query('SELECT * FROM vehiculo ORDER BY id_vehiculo')->fetchAll(PDO::FETCH_ASSOC),$this->db->query('SELECT * FROM usa ORDER BY id_usa')->fetchAll(PDO::FETCH_ASSOC)];}
    public function testStartedWorkProtectsClosureAndReplacementAndCompletionReleases(): void {
        $this->startWork();$before=$this->resourceState();
        foreach(['cerrar','cambiar'] as $a){$r=$this->resource('v19',$this->v19($a));$this->assertSame(409,$r['statusCode']);$this->assertStringContainsString('solicitud especial',$r['message']);$this->assertSame($before,$this->resourceState());$this->assertFalse($this->db->inTransaction());}
        $_SESSION=['usuario'=>['id_usuario'=>2]];$this->assertSame(200,$this->op()->put($this->ownBody('finalizar_atencion_solicitud'))['statusCode']);
        $this->db->exec("INSERT INTO tipo_residuo(id_tipo_residuo,nombre) VALUES(2,'Otro')");
        $this->assertSame(200,$this->resource('vehicle',$this->vehicleBody(2))['statusCode']);
        $this->assertSame(200,$this->resource('use_delete',['id_usa'=>1])['statusCode']);
        $this->assertSame(200,$this->resource('v19',$this->v19())['statusCode']);
    }
    public function testAssignedAndAcceptedDoNotAddClosureOrResidueOrUseRestrictions(): void {
        $this->prepared();$this->assertSame(200,$this->resource('v19',$this->v19())['statusCode']);
        // Otra instalacion de fixture se usa en cada caso, para no recrear historial.
    }
    public function testAcceptedCanCloseAndOtherResourceChangesRemainAvailable(): void {
        $this->acceptWork();$this->db->exec("INSERT INTO tipo_residuo(id_tipo_residuo,nombre) VALUES(2,'Otro')");$this->assertSame(200,$this->resource('vehicle',$this->vehicleBody(2))['statusCode']);$this->assertSame(200,$this->resource('use_delete',['id_usa'=>1])['statusCode']);$this->assertSame(200,$this->resource('v19',$this->v19())['statusCode']);
    }
    /** @dataProvider unstartedStates */
    public function testAssignedAndAcceptedAllowVehicleReplacement(string $state): void {
        if($state==='Aceptada')$this->acceptWork();else $this->prepared();
        $_SESSION=['usuario'=>['id_usuario'=>1]];
        $this->assertSame(200,(new AsignacionVehiculoController($this->db))->handle('POST',['accion'=>'cerrar','id_cuadrilla'=>2,'id_asignacion_vehiculo'=>2,'motivo'=>'Free destination'])['statusCode']);
        $this->assertSame(200,$this->resource('v19',$this->v19('cambiar'))['statusCode']);
    }
    public static function unstartedStates(): array {return [['Asignada'],['Aceptada']];}
    public function testStartedVehicleResidueBlockedAndDescriptiveChangeAllowed(): void {
        $this->startWork();$this->db->exec("INSERT INTO tipo_residuo(id_tipo_residuo,nombre) VALUES(2,'Otro')");$before=$this->resourceState();$r=$this->resource('vehicle',$this->vehicleBody(2));$this->assertSame(409,$r['statusCode']);$this->assertSame($before,$this->resourceState());
        $this->assertSame(200,$this->resource('vehicle',$this->vehicleBody())['statusCode']);$this->assertSame('Descriptive',$this->db->query('SELECT marca FROM vehiculo WHERE id_vehiculo=1')->fetchColumn());
        $this->assertSame(409,(new VehiculoController($this->db))->delete(1)['statusCode']);
        foreach(['funcion_operativa'=>'REGULAR','estado'=>'En Mantenimiento'] as $key=>$value){$b=$this->vehicleBody();$b[$key]=$value;$this->assertSame(409,$this->resource('vehicle',$b)['statusCode']);}
    }
    public function testUseOldNewPairsAndDirectModelCreateProtected(): void {
        $this->startWork();$before=$this->resourceState();
        foreach([['use_delete',['id_usa'=>1]],['use_update',['id_usa'=>1,'id_cuadrilla'=>2,'id_vehiculo'=>1]],['use_update',['id_usa'=>3,'id_cuadrilla'=>1,'id_vehiculo'=>1]],['use_create',['id_cuadrilla'=>1,'id_vehiculo'=>1]]] as [$kind,$b]){$this->assertSame(409,$this->resource($kind,$b)['statusCode']);$this->assertSame($before,$this->resourceState());}
        $u=new Usa($this->db);$u->id_cuadrilla=1;$u->id_vehiculo=1;
        try{$u->create();$this->fail('Duplicate should be blocked');}catch(DomainException $e){$this->assertSame(409,$e->getCode());}$this->assertFalse($this->db->inTransaction());
        $this->assertSame(200,$this->resource('use_update',['id_usa'=>1,'id_cuadrilla'=>1,'id_vehiculo'=>1])['statusCode']);
        $this->assertSame(201,$this->resource('use_create',['id_cuadrilla'=>2,'id_vehiculo'=>1])['statusCode']);
    }
    /** @dataProvider inconsistencies */
    public function testInconsistentWorkFailsClosed(string $sql): void {
        $this->startWork();$this->db->exec($sql);$before=$this->resourceState();
        foreach([['v19',$this->v19()],['vehicle',$this->vehicleBody()],['use_delete',['id_usa'=>1]]] as [$kind,$b]){
            $r=$this->resource($kind,$b);$this->assertSame(409,$r['statusCode']);$this->assertStringContainsString('inconsistente',$r['message']);$this->assertSame($before,$this->resourceState());
        }
    }
    public static function inconsistencies(): array {return [["UPDATE solicitud SET estado='Programada' WHERE id_solicitud=1"],["UPDATE solicitud SET id_cuadrilla=2 WHERE id_solicitud=1"],["UPDATE atencion_solicitud SET estado='Aceptada',fecha_inicio=NULL,id_usuario_inicia=NULL WHERE id_solicitud=1"],['DELETE FROM atencion_solicitud WHERE id_solicitud=1']];}
    /** @dataProvider competingResources */
    public function testStartAgainstResourceMutationIsSerialized(string $kind,string $gate): void {
        $this->acceptWork();$body=$this->ownBody('iniciar_atencion_solicitud');$this->db->exec("INSERT INTO tipo_residuo(id_tipo_residuo,nombre) VALUES(2,'Otro')");
        $change=match($kind){'v19'=>$this->v19(),'vehicle'=>$this->vehicleBody(2),'use_update'=>['id_usa'=>1,'id_cuadrilla'=>2,'id_vehiculo'=>1]};
        $this->db->beginTransaction();$this->db->query($gate)->fetchAll();$workers=[];
        try{$workers[]=$this->start('start',$body);$workers[]=$this->start($kind,$change);$this->waiting(2);$this->db->commit();$a=$this->finish($workers[0]);$b=$this->finish($workers[1]);$workers=[];
            $codes=[$a['statusCode'],$b['statusCode']];sort($codes);$this->assertSame([200,409],$codes);
            $s=$this->state();if($s[0][0]['estado']==='En atención'){
                $this->assertNull($this->db->query('SELECT fecha_fin FROM asignacion_vehiculo_operativa WHERE id_asignacion_vehiculo=1')->fetchColumn());$this->assertSame(1,(int)$this->db->query('SELECT id_tipo_residuo FROM vehiculo WHERE id_vehiculo=1')->fetchColumn());$this->assertSame(1,(int)$this->db->query('SELECT COUNT(*) FROM usa WHERE id_cuadrilla=1 AND id_vehiculo=1')->fetchColumn());
            }else{$this->assertSame('Aceptada',$s[1][0]['estado']);$this->assertNull($s[1][0]['fecha_inicio']);}
        }finally{$this->cleanup($workers);}
    }
    public static function competingResources(): array {return [['v19','SELECT * FROM asignacion_vehiculo_operativa WHERE id_asignacion_vehiculo=1 FOR UPDATE'],['vehicle','SELECT * FROM vehiculo WHERE id_vehiculo=1 FOR UPDATE'],['use_update','SELECT * FROM vehiculo WHERE id_vehiculo=1 FOR UPDATE']];}
    public function testUseWriteFailureRollsBackAndDoesNotLeakInfrastructure(): void {
        $this->acceptWork();$before=$this->resourceState();$this->db->exec("CREATE TRIGGER fail_f63 BEFORE UPDATE ON usa FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Secret SQL details'");
        $r=$this->resource('use_update',['id_usa'=>1,'id_cuadrilla'=>2,'id_vehiculo'=>1]);$this->assertSame(500,$r['statusCode']);$this->assertStringNotContainsString('Secret',$r['message']);$this->assertSame($before,$this->resourceState());$this->assertFalse($this->db->inTransaction());
    }
    public function testUseLockTimeoutHasSafe409AndCompleteRollback(): void {
        $this->acceptWork();$before=$this->resourceState();$this->db->beginTransaction();$this->db->query('SELECT * FROM vehiculo WHERE id_vehiculo=1 FOR UPDATE')->fetchAll();$workers=[];
        try{$workers[]=$this->start('use_update',['id_usa'=>1,'id_cuadrilla'=>2,'id_vehiculo'=>1]);$this->waiting(1);$r=$this->finish($workers[0]);$workers=[];$this->assertSame(409,$r['statusCode']);$this->assertStringNotContainsString('SQL',$r['message']);$this->db->rollBack();$this->assertSame($before,$this->resourceState());}
        finally{$this->cleanup($workers);}
    }
}
