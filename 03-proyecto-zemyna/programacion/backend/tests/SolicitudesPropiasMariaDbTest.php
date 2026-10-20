<?php
require_once __DIR__.'/fixtures/mariadb_sql.php';
require_once __DIR__.'/SolicitudesPropiasTest.php';
/** Servidor TEMP descartable obligatorio; jamás usa configuración de la BD habitual. */
final class SolicitudesPropiasMariaDbTest extends SolicitudesPropiasTest {
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
    private function start(array $body,int $actor=2,bool $admin=false): array {
        $p=proc_open([PHP_BINARY,__DIR__.'/fixtures/f63_worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);$this->assertIsResource($p);fwrite($pipes[0],json_encode(['database'=>$this->database,'body'=>$body,'actor'=>$actor,'admin'=>$admin]));fclose($pipes[0]);stream_set_timeout($pipes[1],20);$this->assertSame("READY\n",fgets($pipes[1]));return [$p,$pipes];
    }
    private function finish(array $w): array {[$p,$pipes]=$w;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$this->assertSame(0,proc_close($p),$err);return json_decode($out,true,512,JSON_THROW_ON_ERROR);}
    private function waiting(int $count): void {
        $deadline=microtime(true)+8;do{$n=(int)$this->db->query("SELECT COUNT(*) FROM information_schema.INNODB_TRX t JOIN information_schema.PROCESSLIST p ON p.ID=t.trx_mysql_thread_id WHERE t.trx_state='LOCK WAIT' AND p.DB=".$this->db->quote($this->database))->fetchColumn();if($n===$count)break;usleep(20000);}while(microtime(true)<$deadline);$this->assertSame($count,$n);
    }
    private function cleanup(array $workers): void {if($this->db->inTransaction())$this->db->rollBack();foreach($workers as [$p,$pipes]){proc_terminate($p);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($p);}}

    public function testTwoOperatorsAcceptSameAttempt(): void {
        $this->prepared();$this->db->exec("INSERT INTO usuario_cuadrilla(id_usuario,id_cuadrilla,fecha_inicio,id_usuario_asigna) VALUES(1,1,'2020-01-01',1)");$body=$this->ownBody('aceptar_solicitud');$this->db->beginTransaction();$this->db->query('SELECT * FROM solicitud WHERE id_solicitud=1 FOR UPDATE')->fetchAll();$workers=[];
        try{$workers[]=$this->start($body);$workers[]=$this->start($body,1);$this->waiting(2);$this->db->commit();$codes=[];foreach($workers as $w)$codes[]=$this->finish($w)['statusCode'];$workers=[];sort($codes);$this->assertSame([200,409],$codes);$this->assertCount(1,$this->state()[1]);$this->assertSame('Aceptada',$this->state()[1][0]['estado']);}finally{$this->cleanup($workers);}
    }
    /** @dataProvider racingChanges */
    public function testRevalidationAfterResourcePermissionOrMembershipWait(string $sql,int $status): void {
        $this->prepared();$body=$this->ownBody('aceptar_solicitud');$before=$this->state();$this->db->beginTransaction();$this->db->query('SELECT * FROM solicitud WHERE id_solicitud=1 FOR UPDATE')->fetchAll();$workers=[];
        try{$workers[]=$this->start($body);$this->waiting(1);$this->other()->exec($sql);$this->db->commit();$r=$this->finish($workers[0]);$workers=[];$this->assertSame($status,$r['statusCode']);$this->assertSame($before,$this->state());}finally{$this->cleanup($workers);}
    }
    public static function racingChanges(): array {return [
        ["UPDATE asignacion_vehiculo_operativa SET fecha_fin=NOW(),id_usuario_finaliza=1,motivo_cierre='Test' WHERE id_asignacion_vehiculo=1",409],
        ["UPDATE vehiculo SET activo=0 WHERE id_vehiculo=1",409],['DELETE FROM usa WHERE id_usa=1',409],['DELETE FROM rol_permiso WHERE id_permiso=23',403],
        ["UPDATE usuario_cuadrilla SET fecha_fin=NOW(),id_usuario_finaliza=1 WHERE id_usuario=2",409]
    ];}
    public function testAcceptanceAgainstAdministrativeReassignment(): void {
        $this->prepared();$body=$this->ownBody('aceptar_solicitud');$_SESSION=['usuario'=>['id_usuario'=>1]];$admin=$this->body('reasignar',1,['id_cuadrilla'=>2,'id_asignacion_vehiculo'=>2,'id_usa'=>2,'motivo'=>'Cambio']);
        $this->db->beginTransaction();$this->db->query('SELECT * FROM solicitud WHERE id_solicitud=1 FOR UPDATE')->fetchAll();$workers=[];
        try{$workers[]=$this->start($body);$workers[]=$this->start($admin,1,true);$this->waiting(2);$this->db->commit();$a=$this->finish($workers[0]);$b=$this->finish($workers[1]);$workers=[];
            $this->assertContains([$a['statusCode'],$b['statusCode']],[[200,409],[404,200]]);$state=$this->state();$open=array_values(array_filter($state[1],fn($a)=>$a['fecha_cierre']===null));$this->assertCount(1,$open);$this->assertSame((int)$state[0][0]['id_cuadrilla'],(int)$open[0]['id_cuadrilla']);
        }finally{$this->cleanup($workers);}
    }
    public function testStartAgainstAdministrativeCancellation(): void {
        $this->prepared();$this->assertSame(200,$this->op()->put($this->ownBody('aceptar_solicitud'))['statusCode']);$body=$this->ownBody('iniciar_atencion_solicitud');$_SESSION=['usuario'=>['id_usuario'=>1]];$admin=$this->body('cancelar',1,['motivo'=>'Cambio']);
        $this->db->beginTransaction();$this->db->query('SELECT * FROM solicitud WHERE id_solicitud=1 FOR UPDATE')->fetchAll();$workers=[];
        try{$workers[]=$this->start($body);$workers[]=$this->start($admin,1,true);$this->waiting(2);$this->db->commit();$a=$this->finish($workers[0]);$b=$this->finish($workers[1]);$workers=[];
            $this->assertContains([$a['statusCode'],$b['statusCode']],[[200,409],[404,200]]);$state=$this->state();$this->assertContains($state[0][0]['estado'],['En atención','Cancelada']);$this->assertCount(1,$state[1]);
        }finally{$this->cleanup($workers);}
    }
    public function testLockTimeoutReturnsConflictAndRollsBack(): void {
        $this->prepared();$body=$this->ownBody('aceptar_solicitud');$before=$this->state();$this->db->beginTransaction();$this->db->query('SELECT * FROM solicitud WHERE id_solicitud=1 FOR UPDATE')->fetchAll();$workers=[];
        try{$workers[]=$this->start($body);$this->waiting(1);$r=$this->finish($workers[0]);$workers=[];$this->assertSame(409,$r['statusCode']);$this->db->rollBack();$this->assertSame($before,$this->state());}finally{$this->cleanup($workers);}
    }
    public function testDeadlockVictimReturnsConflictAndRollsBack(): void {
        $this->prepared();$body=$this->ownBody('aceptar_solicitud');$before=$this->state();$this->db->beginTransaction();
        // El gate escribe varias filas para que InnoDB prefiera la transacción del operario como víctima.
        $this->db->exec("UPDATE usuario SET apellido='Gate' WHERE id_usuario IN (1,2,3,4)");$workers=[];
        // La puerta de continuidad añade locks al operario. Mantener mayor peso
        // en el competidor para comprobar la traducción 409 del endpoint víctima.
        $this->db->exec("UPDATE permiso SET descripcion='Gate' WHERE id_permiso IN (1,2,3,4,5,6,7,21,22,23)");
        try{$workers[]=$this->start($body);$this->waiting(1);$this->db->query('SELECT * FROM solicitud WHERE id_solicitud=1 FOR UPDATE')->fetchAll();$this->db->commit();$r=$this->finish($workers[0]);$workers=[];$this->assertSame(409,$r['statusCode']);$this->assertSame($before,$this->state());
        }finally{$this->cleanup($workers);}
    }
    public function testRollbackAfterAttemptUpdate(): void {
        $this->prepared();$this->assertSame(200,$this->op()->put($this->ownBody('aceptar_solicitud'))['statusCode']);$body=$this->ownBody('iniciar_atencion_solicitud');$before=$this->state();
        $this->db->exec("CREATE TRIGGER fail_f63 BEFORE UPDATE ON solicitud FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Private infrastructure detail'");$r=$this->op()->put($body);$this->assertSame(500,$r['statusCode']);$this->assertStringNotContainsString('Private',$r['message']);$this->assertSame($before,$this->state());$this->assertFalse($this->db->inTransaction());
    }
}
