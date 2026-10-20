<?php
require_once __DIR__.'/fixtures/mariadb_sql.php';
require_once __DIR__.'/SolicitudesPropiasTest.php';
require_once __DIR__.'/../controllers/UsuarioController.php';
require_once __DIR__.'/../controllers/UsuarioRolController.php';
/** Servidor TEMP descartable obligatorio; jamás usa configuración de la BD habitual. */
final class ContinuidadSolicitudOperativaMariaDbTest extends SolicitudesPropiasTest {
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
        $p=proc_open([PHP_BINARY,__DIR__.'/fixtures/f63c2_worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);$this->assertIsResource($p);fwrite($pipes[0],json_encode(['database'=>$this->database,'kind'=>$kind,'body'=>$body]));fclose($pipes[0]);stream_set_timeout($pipes[1],20);$this->assertSame("READY\n",fgets($pipes[1]));return [$p,$pipes];
    }
    private function finish(array $w): array {[$p,$pipes]=$w;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$this->assertSame(0,proc_close($p),$err);return json_decode($out,true,512,JSON_THROW_ON_ERROR);}
    private function waiting(int $count): void {
        $deadline=microtime(true)+8;do{$n=(int)$this->db->query("SELECT COUNT(*) FROM information_schema.INNODB_TRX t JOIN information_schema.PROCESSLIST p ON p.ID=t.trx_mysql_thread_id WHERE t.trx_state='LOCK WAIT' AND p.DB=".$this->db->quote($this->database))->fetchColumn();if($n===$count)break;usleep(20000);}while(microtime(true)<$deadline);$this->assertSame($count,$n);
    }
    private function cleanup(array $workers): void {if($this->db->inTransaction())$this->db->rollBack();foreach($workers as [$p,$pipes]){proc_terminate($p);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($p);}}


    private function work(bool $start=true): void {
        $this->prepared();$this->assertSame(200,$this->op()->put($this->ownBody('aceptar_solicitud'))['statusCode']);
        if($start)$this->assertSame(200,$this->op()->put($this->ownBody('iniciar_atencion_solicitud'))['statusCode']);
    }
    private function userBody(array $extra=[]): array {return array_merge(['id_usuario'=>2,'nombre'=>'Integrante','apellido'=>'Test','email'=>'dos@test.invalid','telefono'=>'099111111','id_centro'=>1,'activo'=>'Activo'],$extra);}
    private function member(): int {return (int)$this->db->query('SELECT id_usuario_cuadrilla FROM usuario_cuadrilla WHERE id_usuario=2 AND fecha_fin IS NULL')->fetchColumn();}
    private function leave(): array {try{(new RecoleccionOperativa($this->db))->asignar(1,2,null,'finalizar_pertenencia',$this->member());return ['statusCode'=>200];}catch(Throwable $e){return ContinuidadSolicitudOperativa::error($e);}}
    private function relief(): void {
        $member=(int)$this->db->query('SELECT id_usuario_cuadrilla FROM usuario_cuadrilla WHERE id_usuario=3 AND fecha_fin IS NULL')->fetchColumn();
        $result=(new RecoleccionOperativa($this->db))->asignar(1,3,1,'trasladar',$member);
        $this->assertSame(1,(int)$result['pertenencia']['id_cuadrilla']);
    }
    private function recoverBody(): array {$_SESSION=['usuario'=>['id_usuario'=>1]];return $this->body('recuperar',1,['estado_operativo_esperado'=>'En atención','motivo'=>'Sin personal habilitado comprobado']);}
    public function testC2LastMemberBlockedAndInitiatorCanLeaveWithRelief(): void {
        $this->work();$before=$this->state();$this->assertSame(409,$this->leave()['statusCode']);$this->assertSame($before,$this->state());$this->assertGreaterThan(0,$this->member());
        try{(new RecoleccionOperativa($this->db))->asignar(1,2,2,'trasladar',$this->member());$this->fail('El último habilitado no puede trasladarse.');}
        catch(DomainException $e){$this->assertSame(409,$e->getCode());}
        $this->assertSame(1,(int)$this->db->query('SELECT id_cuadrilla FROM usuario_cuadrilla WHERE id_usuario=2 AND fecha_fin IS NULL')->fetchColumn());
        $this->assertSame(1,(int)$this->db->query('SELECT COUNT(*) FROM usuario_cuadrilla WHERE id_usuario=2')->fetchColumn());
        $this->relief();$this->assertSame(200,$this->leave()['statusCode']);$_SESSION=['usuario'=>['id_usuario'=>3]];
        $this->assertSame(200,$this->op()->put($this->ownBody('finalizar_atencion_solicitud'))['statusCode']);
        $a=$this->state()[1][0];$this->assertSame(2,(int)$a['id_usuario_inicia']);$this->assertSame(3,(int)$a['id_usuario_cierra']);
    }
    public function testC2OrdinaryDeactivateAndRolePathsRollback(): void {
        $this->work();$u=new UsuarioController($this->db);$before=$this->db->query('SELECT * FROM usuario WHERE id_usuario=2')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(409,$u->update($this->userBody(['activo'=>'Inactivo']))['statusCode']);$this->assertSame($before,$this->db->query('SELECT * FROM usuario WHERE id_usuario=2')->fetch(PDO::FETCH_ASSOC));
        $today=substr(SolicitudOperativa::now(),0,10);
        $this->db->exec("INSERT INTO sector(nombre,descripcion) VALUES('TI','Test'),('OPERACIONES','Test')");
        foreach([['id_rol'=>2,'sector'=>'TI','fecha_desde'=>$today],['id_rol'=>1,'sector'=>'OPERACIONES','fecha_desde'=>'2020-01-01','fecha_hasta'=>'2020-01-02']] as $change)
            $this->assertSame(409,$u->update($this->userBody($change))['statusCode']);
        // Rama de reemplazo en el mismo día, conservando fechas válidas.
        $this->db->exec("UPDATE usuario_rol SET fecha_desde='$today' WHERE id_usuario=2");
        $this->assertSame(409,$u->update($this->userBody(['id_rol'=>2,'sector'=>'TI','fecha_desde'=>$today]))['statusCode']);
        $role=(int)$this->db->query('SELECT id_usuario_rol FROM usuario_rol WHERE id_usuario=2')->fetchColumn();
        $this->db->exec("UPDATE usuario_rol SET fecha_desde='2020-01-01' WHERE id_usuario=2");
        $this->assertSame(409,(new UsuarioRolController($this->db))->finalizar(['id_usuario_rol'=>$role,'fecha_hasta'=>'2020-01-02'])['statusCode']);
        $this->assertNull($this->db->query("SELECT fecha_hasta FROM usuario_rol WHERE id_usuario=2")->fetchColumn());$this->assertFalse($this->db->inTransaction());
    }
    public function testC2AssignedAcceptedAndFinalizedDoNotBlockDeparture(): void {
        $this->work(false);$this->assertSame(200,$this->leave()['statusCode']);
    }
    public function testC2ReliefMustBeStartedActiveAndHaveAllPermissions(): void {
        $this->work();$this->relief();
        foreach(["UPDATE usuario SET activo='Inactivo' WHERE id_usuario=3","UPDATE usuario_cuadrilla SET fecha_inicio='2099-01-01' WHERE id_usuario=3 AND fecha_fin IS NULL","UPDATE usuario_rol SET fecha_hasta='2020-01-02' WHERE id_usuario=3","UPDATE usuario_rol SET id_rol=2,sector='TI' WHERE id_usuario=3"] as $sql){
            $this->db->exec($sql);$this->assertSame(409,$this->leave()['statusCode']);
            $this->db->exec("UPDATE usuario SET activo='Activo' WHERE id_usuario=3;UPDATE usuario_cuadrilla SET fecha_inicio='2020-01-01' WHERE id_usuario=3 AND fecha_fin IS NULL;UPDATE usuario_rol SET id_rol=1,sector='OPERACIONES',fecha_hasta=NULL WHERE id_usuario=3");
        }
        $this->db->exec("INSERT INTO rol(id_rol,nombre) VALUES(3,'RELEVO_SIN_SOLICITUD');INSERT INTO rol_permiso(id_rol,id_permiso) VALUES(3,3),(3,4);UPDATE usuario_rol SET id_rol=3 WHERE id_usuario=3");
        $this->assertSame(409,$this->leave()['statusCode']);$this->db->exec('UPDATE usuario_rol SET id_rol=1 WHERE id_usuario=3');
        $this->assertSame(200,$this->leave()['statusCode']);
    }
    public function testC2SecuritySuspensionAllowsRecoveryAndPreservesHistory(): void {
        $this->work();$before=$this->state()[1][0];$this->assertSame(200,(new UsuarioController($this->db))->delete(2)['statusCode']);
        $body=$this->recoverBody();$detail=$this->c()->get(['id'=>1])['data'];$this->assertTrue($detail['capacidades']['recuperar']);
        foreach(['cancelar','reasignar','desasignar'] as $key)$this->assertFalse($detail['capacidades'][$key]);
        $this->assertSame(200,$this->c()->put($body)['statusCode']);[$s,$a]=$this->state();$this->assertSame('Pendiente',$s[0]['estado']);$this->assertNull($s[0]['id_cuadrilla']);
        foreach(['fecha_finalizacion','fecha_cancelacion','id_usuario_cancela','motivo_cancelacion'] as $key)$this->assertNull($s[0][$key]);
        $this->assertSame('Interrumpida',$a[0]['estado']);$this->assertSame(1,(int)$a[0]['id_usuario_cierra']);$this->assertNotNull($a[0]['fecha_cierre']);$this->assertSame($body['motivo'],$a[0]['motivo_cierre']);
        foreach(['id_asignacion_vehiculo','id_usuario_asigna','fecha_asignacion','id_usuario_acepta','fecha_aceptacion','id_usuario_inicia','fecha_inicio'] as $key)$this->assertSame($before[$key],$a[0][$key]);
        $this->assertSame(409,$this->c()->put($body)['statusCode']);
    }
    public function testC2RecoveryDeniedWithoutObjectiveCauseAndNoTiBypass(): void {
        $this->work();$before=$this->state();$body=$this->recoverBody();$this->assertSame(409,$this->c()->put($body)['statusCode']);$this->assertSame($before,$this->state());
        $_SESSION=['usuario'=>['id_usuario'=>4]];$this->assertSame(403,$this->c()->put($body)['statusCode']);
        $_SESSION=['usuario'=>['id_usuario'=>1]];
        foreach([['motivo'=>''],['id_usuario'=>4],['fecha_cierre'=>'2020-01-01'],['estado_operativo_esperado'=>'Aceptada']] as $bad)$this->assertSame(400,$this->c()->put(array_merge($body,$bad))['statusCode']);
        $this->assertSame($before,$this->state());
    }
    public function testC2AutomaticExpiryPermitsRecoveryButNeverRetainsAuthorization(): void {
        $this->work();$this->db->exec("UPDATE usuario_rol SET fecha_hasta='2020-01-02' WHERE id_usuario=2");
        $this->assertSame(403,$this->op()->get([])['statusCode']);$this->assertSame(200,$this->c()->put($this->recoverBody())['statusCode']);
    }
    public function testC2OrdinaryRoleAndUserChangesAllowEligibleRelief(): void {
        $this->work();$this->relief();$this->db->exec("INSERT INTO sector(nombre,descripcion) VALUES('TI','Test')");
        $today=substr(SolicitudOperativa::now(),0,10);
        $this->assertSame(200,(new UsuarioController($this->db))->update($this->userBody(['id_rol'=>2,'sector'=>'TI','fecha_desde'=>$today]))['statusCode']);
        $this->assertSame(200,(new UsuarioController($this->db))->update($this->userBody(['activo'=>'Inactivo']))['statusCode']);
        $role=(int)$this->db->query('SELECT id_usuario_rol FROM usuario_rol WHERE id_usuario=3')->fetchColumn();
        $this->assertSame(409,(new UsuarioRolController($this->db))->finalizar(['id_usuario_rol'=>$role,'fecha_hasta'=>'2020-01-02'])['statusCode']);
        $this->assertSame(409,$this->c()->put($this->recoverBody())['statusCode']);
    }
    public function testC2InclusiveExpiryAndAdministrativePermissionRevalidated(): void {
        $this->work();$role=(int)$this->db->query('SELECT id_usuario_rol FROM usuario_rol WHERE id_usuario=2')->fetchColumn();
        $this->assertSame(200,(new UsuarioRolController($this->db))->finalizar(['id_usuario_rol'=>$role,'fecha_hasta'=>substr(SolicitudOperativa::now(),0,10)])['statusCode']);
        $this->assertSame(409,$this->c()->put($this->recoverBody())['statusCode']);
        $this->assertSame(200,(new UsuarioController($this->db))->delete(2)['statusCode']);$body=$this->recoverBody();$before=$this->state();
        $this->db->exec("UPDATE usuario_rol SET fecha_hasta='2020-01-02' WHERE id_usuario=1");
        $this->assertSame(403,$this->c()->put($body)['statusCode']);$this->assertSame($before,$this->state());
    }
    public function testC2RecoverySecondWriteFailureRollsBackSafely(): void {
        $this->work();$this->assertSame(200,(new UsuarioController($this->db))->delete(2)['statusCode']);$body=$this->recoverBody();$before=$this->state();
        $this->db->exec("CREATE TRIGGER fail_f63 BEFORE UPDATE ON solicitud FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Secret SQL personal data'");
        $r=$this->c()->put($body);$this->assertSame(500,$r['statusCode']);$this->assertStringNotContainsString('Secret',$r['message']);$this->assertSame($before,$this->state());$this->assertFalse($this->db->inTransaction());
    }
    public function testC2InconsistentStartedWorkNeverMeansNoProtection(): void {
        $this->work();$body=$this->recoverBody();$this->db->exec('DELETE FROM atencion_solicitud WHERE id_solicitud=1');$before=$this->state();
        $r=(new UsuarioController($this->db))->update($this->userBody(['activo'=>'Inactivo']));$this->assertSame(409,$r['statusCode']);
        $this->assertSame(409,$this->c()->put($body)['statusCode']);$this->assertSame($before,$this->state());$this->assertCount(0,$this->state()[1]);
    }
    public function testC2UserAndRecoveryLockTimeoutRollbackWithSafe409(): void {
        $this->work();$this->assertSame(200,(new UsuarioController($this->db))->delete(2)['statusCode']);$body=$this->recoverBody();
        $before=[$this->state(),$this->db->query('SELECT * FROM usuario ORDER BY id_usuario')->fetchAll(PDO::FETCH_ASSOC)];
        $this->db->beginTransaction();$this->db->query('SELECT * FROM cuadrilla ORDER BY id_cuadrilla FOR UPDATE')->fetchAll();$w=[];
        try{$w[]=$this->start('user',$this->userBody(['activo'=>'Inactivo']));$w[]=$this->start('recover',$body);$this->waiting(2);
            foreach($w as $worker){$r=$this->finish($worker);$this->assertSame(409,$r['statusCode']);$this->assertStringNotContainsString('SQL',$r['message']);}$w=[];
            $this->db->rollBack();$this->assertSame($before,[$this->state(),$this->db->query('SELECT * FROM usuario ORDER BY id_usuario')->fetchAll(PDO::FETCH_ASSOC)]);$this->assertFalse($this->db->inTransaction());
        }finally{$this->cleanup($w);}
    }
    public function testC2RealHttpUserRolesSuspensionAndRecoveryCsrf(): void {
        $this->work();$this->db->exec("INSERT INTO sector(nombre,descripcion) VALUES('TI','Test');INSERT INTO permiso(nombre) VALUES('usuario.modificar'),('usuario.asignar_rol'),('usuario.suspender');INSERT INTO rol_permiso SELECT 1,id_permiso FROM permiso WHERE nombre LIKE 'usuario.%';INSERT INTO usuario_rol(id_usuario,id_rol,sector,fecha_desde) VALUES(1,1,'TI','2020-01-01')");
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$this->assertIsResource($socket);$address=stream_socket_get_name($socket,false);fclose($socket);
        $environment=getenv();$environment['ZEMYNA_F63_HTTP_DATABASE']=$this->database;
        $p=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/fixtures/f63c2_http_router.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$environment);$this->assertIsResource($p);fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        try {
            $ready=false;$deadline=microtime(true)+8;do{$probe=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($probe){fclose($probe);$ready=true;break;}usleep(20000);}while(microtime(true)<$deadline);$this->assertTrue($ready);
            $request=function(string $path,string $method,array $body,int $actor,int $status,bool $csrf=false)use($address): array {
                $headers=['Content-Type: application/json','X-Test-Actor: '.$actor];if($csrf)$headers[]='X-CSRF-Token: test-token';
                $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>json_encode($body),'ignore_errors'=>true,'timeout'=>10]]);
                $raw=file_get_contents('http://'.$address.$path,false,$context);$this->assertStringContainsString(' '.$status.' ',$http_response_header[0]);
                if($path==='/solicitudes')$this->assertContains('Cache-Control: no-store',$http_response_header);
                return json_decode($raw,true,512,JSON_THROW_ON_ERROR);
            };
            $request('/usuarios','PUT',$this->userBody(['activo'=>'Inactivo']),1,409);
            $role=(int)$this->db->query('SELECT id_usuario_rol FROM usuario_rol WHERE id_usuario=2')->fetchColumn();
            $request('/roles','PUT',['id_usuario_rol'=>$role,'fecha_hasta'=>'2020-01-02'],1,409);
            $request('/usuarios','DELETE',['id_usuario'=>2],3,403);
            $request('/usuarios','DELETE',['id_usuario'=>2],1,200);
            $body=$this->recoverBody();
            $request('/solicitudes','PUT',$body,1,403);$request('/solicitudes','PUT',$body,4,403,true);$request('/solicitudes','PUT',$body,0,401,true);
            $request('/solicitudes','PUT',$body,1,200,true);$request('/solicitudes','PUT',$body,1,409,true);
        }finally{proc_terminate($p);fclose($pipes[1]);fclose($pipes[2]);proc_close($p);}
    }
    /** @dataProvider changes */
    public function testC2StartAgainstOrdinaryRevocation(string $kind): void {
        $this->work(false);$start=$this->ownBody('iniciar_atencion_solicitud');
        $b=match($kind){'user'=>$this->userBody(['activo'=>'Inactivo']),'membership'=>['pertenencia'=>$this->member()],'role'=>['id_usuario_rol'=>(int)$this->db->query('SELECT id_usuario_rol FROM usuario_rol WHERE id_usuario=2')->fetchColumn(),'fecha_hasta'=>'2020-01-02']};
        $this->db->beginTransaction();$this->db->query('SELECT * FROM cuadrilla ORDER BY id_cuadrilla FOR UPDATE')->fetchAll();$w=[];
        try{$w[]=$this->start('start',$start);$w[]=$this->start($kind,$b);$this->waiting(2);$this->db->commit();$a=$this->finish($w[0]);$b=$this->finish($w[1]);$w=[];
            $this->assertContains($a['statusCode'],[200,401,403,404,409]);$this->assertContains($b['statusCode'],[200,409]);$this->assertFalse($a['statusCode']===200 && $b['statusCode']===200);
            $this->db->beginTransaction();$this->assertSame($this->state()[0][0]['estado']==='En atención'?1:0,(new ContinuidadSolicitudOperativa($this->db))->eligible(1));$this->db->rollBack();
        }finally{$this->cleanup($w);}
    }
    public static function changes(): array {return [['user'],['membership'],['role']];}
    public function testC2FinishAgainstRecoveryAndSuspension(): void {
        $this->work();$finish=$this->ownBody('finalizar_atencion_solicitud');$recover=$this->recoverBody();
        $this->db->beginTransaction();$this->db->query('SELECT * FROM cuadrilla ORDER BY id_cuadrilla FOR UPDATE')->fetchAll();$w=[];
        try{$w[]=$this->start('finish',$finish);$w[]=$this->start('recover',$recover);$w[]=$this->start('suspend',['id_usuario'=>2]);$this->waiting(3);$this->db->commit();$responses=[];foreach($w as $worker)$responses[]=$this->finish($worker);$w=[];
            $this->assertContains($responses[0]['statusCode'],[200,401,409]);$this->assertContains($responses[1]['statusCode'],[200,409]);$this->assertSame(200,$responses[2]['statusCode']);
            $this->assertFalse($responses[0]['statusCode']===200 && $responses[1]['statusCode']===200);[$s,$a]=$this->state();$this->assertContains($s[0]['estado'],['Finalizada','Pendiente','En atención']);
            $this->assertCount(1,$a);if($s[0]['estado']==='Finalizada')$this->assertSame('Finalizada',$a[0]['estado']);if($s[0]['estado']==='Pendiente')$this->assertSame('Interrumpida',$a[0]['estado']);
        }finally{$this->cleanup($w);}
    }
    public function testC2FinishAgainstLastMemberDeparture(): void {
        $this->work();$body=$this->ownBody('finalizar_atencion_solicitud');$member=$this->member();
        $this->db->beginTransaction();$this->db->query('SELECT * FROM cuadrilla ORDER BY id_cuadrilla FOR UPDATE')->fetchAll();$w=[];
        try{$w[]=$this->start('finish',$body);$w[]=$this->start('membership',['pertenencia'=>$member]);$this->waiting(2);$this->db->commit();$a=$this->finish($w[0]);$b=$this->finish($w[1]);$w=[];
            $this->assertSame(200,$a['statusCode']);$this->assertContains($b['statusCode'],[200,409]);$this->assertSame('Finalizada',$this->state()[0][0]['estado']);$this->assertSame('Finalizada',$this->state()[1][0]['estado']);
        }finally{$this->cleanup($w);}
    }
    public function testC2RecoveryAgainstRestorationOfExpiredRole(): void {
        $this->work();$this->db->exec("UPDATE usuario_rol SET fecha_hasta='2020-01-02' WHERE id_usuario=2");$body=$this->recoverBody();
        $role=['id_usuario'=>2,'id_rol'=>1,'sector'=>'OPERACIONES','fecha_desde'=>substr(SolicitudOperativa::now(),0,10)];
        $this->db->beginTransaction();$this->db->query('SELECT * FROM cuadrilla ORDER BY id_cuadrilla FOR UPDATE')->fetchAll();$w=[];
        try{$w[]=$this->start('recover',$body);$w[]=$this->start('role_create',$role);$this->waiting(2);$this->db->commit();$a=$this->finish($w[0]);$b=$this->finish($w[1]);$w=[];
            $this->assertContains($a['statusCode'],[200,409]);$this->assertSame(201,$b['statusCode']);
            $this->assertSame($a['statusCode']===200?'Pendiente':'En atención',$this->state()[0][0]['estado']);
        }finally{$this->cleanup($w);}
    }
}
