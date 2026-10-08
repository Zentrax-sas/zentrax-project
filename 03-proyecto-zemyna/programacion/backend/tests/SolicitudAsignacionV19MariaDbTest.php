<?php
require_once __DIR__.'/SolicitudAdminTest.php';
/** Servidor TEMP descartable obligatorio; jamás usa configuración de la BD habitual. */
final class SolicitudAsignacionV19MariaDbTest extends SolicitudAdminTest {
    private string $database;
    private static ?PDO $shared = null;
    private static ?string $sharedName = null;
    private static bool $installed = false;
    protected function connect(): PDO {
        if(!getenv('ZEMYNA_F62_MARIADB_DSN'))$this->markTestSkipped('MariaDB F6.2 descartable no configurada.');
        if(self::$shared){$this->database=self::$sharedName;return self::$shared;}
        $db=$this->other(false);$dir=str_replace('\\','/',$db->query('SELECT @@datadir')->fetchColumn());
        if(!str_starts_with(strtolower($dir),strtolower(str_replace('\\','/',sys_get_temp_dir()).'/')) || !preg_match('~/zemyna-f6[12]-[a-f0-9]+/data/$~i',$dir))$this->fail('Servidor no descartable rechazado.');
        $this->database='f62_test_'.bin2hex(random_bytes(6));$db->exec('CREATE DATABASE '.$this->database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$db->exec('USE '.$this->database);self::$shared=$db;self::$sharedName=$this->database;return $db;
    }
    private function other(bool $select=true): PDO {
        $db=new PDO(getenv('ZEMYNA_F62_MARIADB_DSN'),'root',getenv('ZEMYNA_F62_MARIADB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);if($select)$db->exec('USE '.$this->database);return $db;
    }
    protected function install(): void {
        if($this->db->query('SELECT DATABASE()')->fetchColumn()!==self::$sharedName || !preg_match('/^f62_test_[a-f0-9]{12}$/D',$this->database))throw new RuntimeException('Base descartable incorrecta.');
        if(!self::$installed){$this->db->exec(file_get_contents(__DIR__.'/../../base-datos/database/sql/schema.sql'));self::$installed=true;}
        else {
            $this->db->exec('DROP TRIGGER IF EXISTS fail_f62');$this->db->exec('SET FOREIGN_KEY_CHECKS=0');
            try{foreach($this->db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t){if(!preg_match('/^[a-z_]+$/D',$t))throw new RuntimeException('Tabla inesperada');$this->db->exec('DELETE FROM `'.$t.'`');}}
            finally{$this->db->exec('SET FOREIGN_KEY_CHECKS=1');}
        }
        $this->seed();
    }
    public static function tearDownAfterClass(): void {
        if(self::$shared && self::$sharedName && preg_match('/^f62_test_[a-f0-9]{12}$/D',self::$sharedName))self::$shared->exec('DROP DATABASE '.self::$sharedName);
        self::$shared=null;self::$sharedName=null;self::$installed=false;
    }
    private function start(array $body): array {
        $p=proc_open([PHP_BINARY,__DIR__.'/fixtures/f62_worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);$this->assertIsResource($p);fwrite($pipes[0],json_encode(['database'=>$this->database,'body'=>$body]));fclose($pipes[0]);stream_set_timeout($pipes[1],20);$this->assertSame("READY\n",fgets($pipes[1]));return [$p,$pipes];
    }
    private function finish(array $w): array {[$p,$pipes]=$w;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$this->assertSame(0,proc_close($p),$err);return json_decode($out,true,512,JSON_THROW_ON_ERROR);}
    private function waiting(int $count): void {
        $deadline=microtime(true)+8;do{$n=(int)$this->db->query("SELECT COUNT(*) FROM information_schema.INNODB_TRX t JOIN information_schema.PROCESSLIST p ON p.ID=t.trx_mysql_thread_id WHERE t.trx_state='LOCK WAIT' AND p.DB=".$this->db->quote($this->database))->fetchColumn();if($n===$count)break;usleep(20000);}while(microtime(true)<$deadline);$this->assertSame($count,$n);
    }
    private function cleanup(array $workers): void {if($this->db->inTransaction())$this->db->rollBack();foreach($workers as [$p,$pipes]){proc_terminate($p);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($p);}}
    public function testConcurrentAssignments(): void {
        $this->confirm();$body=$this->assign();$this->db->beginTransaction();$this->db->query('SELECT * FROM solicitud WHERE id_solicitud=1 FOR UPDATE')->fetchAll();$workers=[];
        try{$workers[]=$this->start($body);$workers[]=$this->start($body);$this->waiting(2);$this->db->commit();$codes=[];foreach($workers as $w)$codes[]=$this->finish($w)['statusCode'];$workers=[];sort($codes);$this->assertSame([200,409],$codes);$this->assertCount(1,$this->state()[1]);}finally{$this->cleanup($workers);}
    }
    /** @dataProvider racingChanges */
    public function testRevalidationAfterWaiting(string $sql,int $status): void {
        $this->confirm();$body=$this->assign();$before=$this->state();$this->db->beginTransaction();$this->db->query('SELECT * FROM solicitud WHERE id_solicitud=1 FOR UPDATE')->fetchAll();$workers=[];
        try{$workers[]=$this->start($body);$this->waiting(1);$this->other()->exec($sql);$this->db->commit();$result=$this->finish($workers[0]);$workers=[];$this->assertSame($status,$result['statusCode']);$this->assertSame($before,$this->state());}finally{$this->cleanup($workers);}
    }
    public static function racingChanges(): array {return [
        ["UPDATE asignacion_vehiculo_operativa SET fecha_fin=NOW(),id_usuario_finaliza=1,motivo_cierre='Test' WHERE id_asignacion_vehiculo=1",409],
        ["UPDATE asignacion_vehiculo_operativa SET fecha_fin=NOW(),id_usuario_finaliza=1,motivo_cierre='Test' WHERE id_asignacion_vehiculo=1;INSERT INTO asignacion_vehiculo_operativa(id_cuadrilla,id_vehiculo,fecha_inicio,id_usuario_asigna) VALUES(1,1,NOW(),1)",409],
        ["UPDATE vehiculo SET funcion_operativa=NULL WHERE id_vehiculo=1",409],['DELETE FROM rol_permiso WHERE id_permiso=22',403],['DELETE FROM usa WHERE id_usa=1',409],
        ["INSERT INTO tipo_residuo(id_tipo_residuo,nombre) VALUES(2,'Otro');UPDATE vehiculo SET id_tipo_residuo=2 WHERE id_vehiculo=1",409]];}
    public function testLockTimeoutReturnsConflictAndRollsBack(): void {
        $this->confirm();$body=$this->assign();$before=$this->state();$this->db->beginTransaction();$this->db->query('SELECT * FROM solicitud WHERE id_solicitud=1 FOR UPDATE')->fetchAll();$workers=[];
        try{$workers[]=$this->start($body);$this->waiting(1);$result=$this->finish($workers[0]);$workers=[];$this->assertSame(409,$result['statusCode']);$this->db->rollBack();$this->assertSame($before,$this->state());}finally{$this->cleanup($workers);}
    }
    public function testRollbackAfterInterrupt(): void {
        $this->confirm();$this->assertSame(200,$this->c()->put($this->assign())['statusCode']);$before=$this->state();
        $this->db->exec("CREATE TRIGGER fail_f62 BEFORE INSERT ON atencion_solicitud FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test'");
        $this->assertSame(500,$this->c()->put($this->body('reasignar',1,['id_cuadrilla'=>2,'id_asignacion_vehiculo'=>2,'id_usa'=>2,'motivo'=>'Cambio']))['statusCode']);$this->assertSame($before,$this->state());$this->assertFalse($this->db->inTransaction());
    }
}
