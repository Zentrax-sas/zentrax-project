<?php
require_once __DIR__.'/F1SecurityTest.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/fixtures/F1HttpSandbox.php';

/** Nunca admite un destino externo: crea y elimina únicamente su BD aleatoria. */
final class F1SecurityMariaDbTest extends F1SecurityTest {
    private static ?PDO $shared=null;
    private static ?string $name=null;
    private static bool $installed=false;
    protected function connect(): PDO {
        if(getenv('ZEMYNA_F1_MARIADB')!=='1')$this->markTestSkipped('MariaDB F1 aislada no habilitada.');
        if(self::$shared)return self::$shared;
        $db=$this->other(false);self::$name='zemyna_f1_test_'.bin2hex(random_bytes(6));
        $db->exec('CREATE DATABASE '.self::$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $db->exec('USE '.self::$name);self::$shared=$db;
        fwrite(STDERR,'F1 isolated database: '.self::$name."\n");return $db;
    }
    private function other(bool $select=true): PDO {
        return new PDO('mysql:host='.(getenv('DB_HOST')?:'localhost').($select?';dbname='.self::$name:'').';charset=utf8mb4',getenv('DB_USER')?:'root',getenv('DB_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    }
    protected function schema(): void {
        if(!self::$name || !preg_match('/^zemyna_f1_test_[a-f0-9]{12}$/D',self::$name) || $this->db->query('SELECT DATABASE()')->fetchColumn()!==self::$name)throw new RuntimeException('Destino aislado incorrecto.');
        if(!self::$installed){$this->db->exec(file_get_contents(__DIR__.'/../../base-datos/database/sql/schema.sql'));self::$installed=true;return;}
        foreach(['fail_photo','fail_grant','fail_consume']as$trigger)$this->db->exec('DROP TRIGGER IF EXISTS '.$trigger);
        $this->db->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->db->beginTransaction();
        try{foreach($this->db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)as$table){if(!preg_match('/^[a-z_]+$/D',$table))throw new RuntimeException('Tabla inesperada.');$this->db->exec('DELETE FROM `'.$table.'`');}$this->db->commit();}
        catch(Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
        finally{$this->db->exec('SET FOREIGN_KEY_CHECKS=1');}
    }
    protected function failPhoto(): void {$this->db->exec("CREATE TRIGGER fail_photo BEFORE INSERT ON foto FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test'");}
    protected function failGrant(): void {$this->db->exec("CREATE TRIGGER fail_grant BEFORE INSERT ON incidencia_upload_token FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test'");}
    protected function failConsume(): void {$this->db->exec("CREATE TRIGGER fail_consume BEFORE UPDATE ON incidencia_upload_token FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test'");}
    public static function tearDownAfterClass(): void {
        if(self::$shared&&self::$name&&preg_match('/^zemyna_f1_test_[a-f0-9]{12}$/D',self::$name))self::$shared->exec('DROP DATABASE '.self::$name);
        self::$shared=null;self::$name=null;self::$installed=false;
    }
    private function startWorker(array $data): array {
        $process=proc_open([PHP_BINARY,__DIR__.'/fixtures/f1_upload_worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $this->assertIsResource($process);fwrite($pipes[0],json_encode(['database'=>self::$name,'body'=>$data,'file'=>$this->file,'directory'=>$this->directory]));fclose($pipes[0]);
        stream_set_timeout($pipes[1],20);$this->assertSame("READY\n",fgets($pipes[1]));return[$process,$pipes];
    }
    private function finishWorker(array $worker): array {
        [$process,$pipes]=$worker;$body=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        $this->assertSame(0,proc_close($process),$error);return json_decode($body,true,512,JSON_THROW_ON_ERROR);
    }
    public function testTwoRealConcurrentUploadsOnlyOneCommits(): void {
        $data=$this->incident();$this->db->beginTransaction();
        $stmt=$this->db->prepare('SELECT id_incidencia FROM incidencia WHERE id_incidencia=? FOR UPDATE');$stmt->execute([$data['id_incidencia']]);$stmt->fetchAll();
        $workers=[];
        try {
            $workers[]=$this->startWorker($data);$workers[]=$this->startWorker($data);
            $deadline=microtime(true)+8;$waiting=0;
            do{$waiting=(int)$this->db->query("SELECT COUNT(*) FROM information_schema.INNODB_TRX t JOIN information_schema.PROCESSLIST p ON p.ID=t.trx_mysql_thread_id WHERE t.trx_state='LOCK WAIT' AND p.DB=".$this->db->quote(self::$name))->fetchColumn();if($waiting===2)break;usleep(20000);}while(microtime(true)<$deadline);
            $this->assertSame(2,$waiting,'Ambas conexiones deben esperar el bloqueo real.');$this->db->commit();
            $results=array_map(fn($worker)=>$this->finishWorker($worker),$workers);$workers=[];
            $codes=array_column($results,'statusCode');sort($codes);$this->assertSame([201,409],$codes);
            $this->assertSame(1,(int)$this->db->query('SELECT COUNT(*) FROM foto')->fetchColumn());
            $this->assertNotNull($this->grantRow()['fecha_consumo']);$this->assertCount(2,glob($this->directory.DIRECTORY_SEPARATOR.'*'));
        } finally {
            if($this->db->inTransaction())$this->db->rollBack();
            foreach($workers as[$process,$pipes]){proc_terminate($process);foreach($pipes as$pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}
        }
    }
    public function testMigrationIdempotentAndNoBackfill(): void {
        $this->incident();$before=$this->grantRow();
        $this->db->exec("INSERT INTO incidencia(tracking_number,descripcion,fecha_reporte,estado,prioridad,tipo_problema,id_contenedor)
            VALUES('INC-HISTORICA','Test','2020-01-01','Pendiente','Media','Contenedor Desbordado',1)");
        $migration=file_get_contents(__DIR__.'/../../base-datos/database/sql/migration_v20_incidencia_upload_token.sql');
        $this->db->exec($migration);$this->db->exec($migration);$this->assertSame($before,$this->grantRow());
        $this->assertSame(1,(int)$this->db->query('SELECT COUNT(*) FROM incidencia_upload_token')->fetchColumn());
    }
    public function testRealHttpPublicCrewUploadAndHistoricalRead(): void {
        $http=new F1HttpSandbox(self::$name);
        try {
            $body=['descripcion'=>'Prueba HTTP aislada','tipo_problema'=>'Contenedor Desbordado','id_contenedor'=>1,
                'estado'=>'Resuelta','prioridad'=>'Alta','fecha_reporte'=>'2099-01-01','id_usuario'=>2,'id_cuadrilla'=>999];
            $created=$http->request('POST','/api/incidencias.php',json_encode($body),['Content-Type: application/json']);
            $this->assertSame(201,$created['status'],preg_replace('/[a-f0-9]{64}/','[redacted]',$created['body']));$this->assertContains('Cache-Control: no-store',$created['headers']);
            $data=json_decode($created['body'],true,512,JSON_THROW_ON_ERROR)['data'];
            $uploaded=$http->multipart(['id_incidencia'=>$data['id_incidencia'],'upload_token'=>$data['upload_token']],file_get_contents($this->file['tmp_name']));
            $this->assertSame(201,$uploaded['status']);
            $uploadedJson=json_decode($uploaded['body'],true);
            $this->assertIsArray($uploadedJson,preg_replace('/[a-f0-9]{64}/','[redacted]',$uploaded['body']));
            $this->assertArrayHasKey('data',$uploadedJson,preg_replace('/[a-f0-9]{64}/','[redacted]',$uploaded['body']));
            $this->assertArrayHasKey('id_foto',$uploadedJson['data']);$photo=$uploadedJson['data']['id_foto'];
            $this->assertSame(409,$http->multipart(['id_incidencia'=>$data['id_incidencia'],'upload_token'=>$data['upload_token']],file_get_contents($this->file['tmp_name']))['status']);
            $this->assertSame(400,$http->multipart(['id_incidencia'=>$data['id_incidencia']],file_get_contents($this->file['tmp_name']))['status']);
            $cookie=$http->session(1,['incidencia.crear','incidencia.consultar','incidencia.adjuntar_evidencia']);
            $historical=$http->request('GET','/api/foto.php?id='.$photo,'',['Cookie: '.$cookie]);
            $this->assertSame(200,$historical['status'],$historical['body']);$this->assertSame(file_get_contents($this->file['tmp_name']),$historical['body']);
            $record=(new Foto($this->db))->findById($photo);
            $stmt=$this->db->prepare('UPDATE foto SET url=? WHERE id_foto=?');
            $stmt->execute(['http://localhost/legacy/uploads/incidencias/'.$record['url'],$photo]);
            $this->assertSame(200,$http->request('GET','/api/foto.php?id='.$photo,'',['Cookie: '.$cookie])['status']);
            $body['id_contenedor']=null;$body['latitud']=-34.9;$body['longitud']=-56.2;
            $crew=$http->request('POST','/api/incidencias.php?view=crew',json_encode($body),['Content-Type: application/json','Cookie: '.$cookie]);
            $this->assertSame(201,$crew['status']);$crewData=json_decode($crew['body'],true)['data'];
            $fields=['id_incidencia'=>$crewData['id_incidencia'],'upload_token'=>$crewData['upload_token']];
            $this->assertSame(403,$http->multipart($fields,file_get_contents($this->file['tmp_name']))['status']);
            $this->assertSame(403,$http->multipart($fields,file_get_contents($this->file['tmp_name']),$http->session(2))['status']);
            $this->assertSame(201,$http->multipart($fields,file_get_contents($this->file['tmp_name']),$cookie)['status']);
            $row=$this->db->query('SELECT * FROM incidencia WHERE id_incidencia='.(int)$crewData['id_incidencia'])->fetch(PDO::FETCH_ASSOC);
            $this->assertSame(1,(int)$row['id_usuario']);$this->assertSame('Pendiente',$row['estado']);$this->assertSame('Media',$row['prioridad']);$this->assertNull($row['id_cuadrilla']);
            $tracking=$http->request('GET','/api/incidencias.php?tracking_number='.$data['tracking_number']);
            $this->assertSame(200,$tracking['status']);$this->assertStringNotContainsString('upload_token',$tracking['body']);
            $solicitud=['descripcion'=>'Test','direccion'=>'Test','email'=>'test@test.invalid','telefono'=>'099111111','tipo_solicitud'=>'Reciclables','id_tipo_residuo'=>1,'captcha_respuesta'=>7,'estado'=>'Finalizada','fecha'=>'2099-01-01'];
            $response=$http->request('POST','/api/solicitud.php',json_encode($solicitud),['Content-Type: application/json','Cookie: '.$http->session(null)]);
            $this->assertSame(201,$response['status']);$this->assertSame('Pendiente',$this->db->query('SELECT estado FROM solicitud')->fetchColumn());
        } finally {$http->close();}
    }
}
