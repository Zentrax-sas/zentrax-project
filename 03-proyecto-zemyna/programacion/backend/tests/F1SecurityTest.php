<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../controllers/IncidenciaController.php';
require_once __DIR__.'/../controllers/SolicitudController.php';
require_once __DIR__.'/../controllers/FotoUploadController.php';
require_once __DIR__.'/fixtures/incidencia_upload_token.php';

/** Contrato real sobre SQL aislado; heredado por MariaDB. */
class F1SecurityTest extends TestCase {
    protected PDO $db;
    protected string $directory;
    protected array $file;
    protected function connect(): PDO { return new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
    protected function schema(): void {
        $this->db->exec("PRAGMA foreign_keys=ON;
            CREATE TABLE centro(id_centro INTEGER PRIMARY KEY,nombre TEXT,direccion TEXT);
            CREATE TABLE usuario(id_usuario INTEGER PRIMARY KEY,nombre TEXT,apellido TEXT,email TEXT,contrasena TEXT,fecha_registro TEXT,id_centro INTEGER,activo TEXT DEFAULT 'Activo');
            CREATE TABLE rol(id_rol INTEGER PRIMARY KEY,nombre TEXT);
            CREATE TABLE permiso(id_permiso INTEGER PRIMARY KEY,nombre TEXT);
            CREATE TABLE rol_permiso(id_rol INTEGER,id_permiso INTEGER);
            CREATE TABLE usuario_rol(id_usuario INTEGER,id_rol INTEGER,sector TEXT,fecha_desde TEXT,fecha_hasta TEXT);
            CREATE TABLE tipo_residuo(id_tipo_residuo INTEGER PRIMARY KEY,nombre TEXT);
            CREATE TABLE ruta(id_ruta INTEGER PRIMARY KEY,nombre TEXT,zona TEXT);
            CREATE TABLE contenedor(id_contenedor INTEGER PRIMARY KEY,codigo TEXT,capacidad NUMERIC,direccion TEXT,latitud NUMERIC,longitud NUMERIC,estado TEXT,activo INTEGER DEFAULT 1,id_tipo_residuo INTEGER,id_ruta INTEGER);
            CREATE TABLE incidencia(id_incidencia INTEGER PRIMARY KEY AUTOINCREMENT,tracking_number TEXT UNIQUE,
                descripcion TEXT,fecha_reporte TEXT,estado TEXT,prioridad TEXT,tipo_problema TEXT,
                id_contenedor INTEGER,id_ruta INTEGER,id_cuadrilla INTEGER,id_usuario INTEGER REFERENCES usuario(id_usuario),
                latitud NUMERIC,longitud NUMERIC,fecha_resolucion TEXT DEFAULT NULL);
            CREATE TABLE foto(id_foto INTEGER PRIMARY KEY AUTOINCREMENT,fecha TEXT,url TEXT,id_incidencia INTEGER REFERENCES incidencia(id_incidencia));
            CREATE TABLE solicitud(id_solicitud INTEGER PRIMARY KEY AUTOINCREMENT,tracking_number TEXT UNIQUE,fecha TEXT,
                descripcion TEXT,direccion TEXT,estado TEXT,id_tipo_residuo INTEGER REFERENCES tipo_residuo(id_tipo_residuo),email TEXT,telefono TEXT,tipo_solicitud TEXT);");
        createUploadTokenFixture($this->db);
    }
    protected function seed(): void {
        $this->db->exec("INSERT INTO centro(id_centro,nombre,direccion) VALUES(1,'Test','Test');
            INSERT INTO usuario(id_usuario,nombre,apellido,email,contrasena,fecha_registro,id_centro) VALUES
                (1,'Test','Uno','uno@test.invalid','x','2020-01-01',1),(2,'Test','Dos','dos@test.invalid','x','2020-01-01',1);
            INSERT INTO rol(id_rol,nombre) VALUES(1,'OPERARIO'),(2,'ADMINISTRADOR_TI');
            INSERT INTO permiso(id_permiso,nombre) VALUES(1,'incidencia.adjuntar_evidencia');
            INSERT INTO rol_permiso VALUES(1,1);
            INSERT INTO usuario_rol(id_usuario,id_rol,sector,fecha_desde) VALUES(1,1,'OPERACIONES','2020-01-01');
            INSERT INTO tipo_residuo(id_tipo_residuo,nombre) VALUES(1,'Test');
            INSERT INTO ruta(id_ruta,nombre,zona) VALUES(1,'Test','Test');
            INSERT INTO contenedor(id_contenedor,codigo,capacidad,direccion,latitud,longitud,estado,id_tipo_residuo,id_ruta)
                VALUES(1,'TEST',1,'Test',-34.9,-56.2,'Disponible',1,1);");
    }
    protected function setUp(): void {
        $this->db=$this->connect(); $this->schema();
        $this->db->beginTransaction();
        try {$this->seed();$this->db->commit();}
        catch(Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
        $this->directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'zemyna_f1_'.bin2hex(random_bytes(8));
        mkdir($this->directory,0700);
        $path=$this->directory.DIRECTORY_SEPARATOR.'input.png';
        file_put_contents($path,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aH1sAAAAASUVORK5CYII='));
        $this->file=['error'=>UPLOAD_ERR_OK,'size'=>filesize($path),'name'=>'image.png','tmp_name'=>$path];
    }
    protected function tearDown(): void {
        if(isset($this->db)&&$this->db->inTransaction())$this->db->rollBack();
        if(isset($this->directory)&&is_dir($this->directory)){foreach(glob($this->directory.DIRECTORY_SEPARATOR.'*')?:[]as$f)if(is_file($f))unlink($f);rmdir($this->directory);}
    }
    protected function incident(array $extra=[], ?int $issuer=null): array {
        $controller=new IncidenciaController($this->db);
        $body=array_replace(['descripcion'=>'Prueba F1','tipo_problema'=>'Contenedor Desbordado','id_contenedor'=>1],$extra);
        $result=$issuer===null?$controller->create($body):$controller->createCrew($body,$issuer);
        $this->assertSame(201,$result['statusCode'],json_encode($result)); return $result['data'];
    }
    protected function upload(array $body, ?int $actor=null, ?array $file=null, ?Closure $move=null): array {
        // Sólo la prueba copia archivos locales. Producción usa move_uploaded_file.
        return (new FotoUploadController($this->db,$this->directory,$move??static fn($a,$b)=>copy($a,$b)))->upload($body,$file??$this->file,$actor);
    }
    protected function grantRow(): array { return $this->db->query('SELECT * FROM incidencia_upload_token')->fetch(PDO::FETCH_ASSOC); }
    protected function assertUnused(): void { $this->assertNull($this->grantRow()['fecha_consumo']); $this->assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM foto')->fetchColumn()); }

    /** @dataProvider reservedFields */
    public function testPublicIgnoresEveryReservedField(string $state,?string $priority): void {
        $before=IncidenciaUploadToken::now()->format('Y-m-d H:i:s');
        $data=$this->incident(['estado'=>$state,'prioridad'=>$priority,'fecha_reporte'=>'2099-01-01',
            'id_incidencia'=>999,'tracking_number'=>'CLIENT','id_usuario'=>2,'id_cuadrilla'=>999,
            'fecha_resolucion'=>'2000-01-01','id_usuario_registra'=>2,'fecha_inicio'=>'2000-01-01',
            'latitud'=>-34,'longitud'=>-56,'direccion'=>'Compatibilidad']);
        $row=$this->db->query('SELECT * FROM incidencia')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('Pendiente',$row['estado']);$this->assertSame('Media',$row['prioridad']);
        foreach(['id_usuario','id_cuadrilla','fecha_resolucion','latitud','longitud']as$key)$this->assertNull($row[$key]);
        $this->assertGreaterThanOrEqual($before,$row['fecha_reporte']);
        $this->assertLessThanOrEqual(IncidenciaUploadToken::now()->format('Y-m-d H:i:s'),$row['fecha_reporte']);
        $this->assertNotSame(999,(int)$row['id_incidencia']);$this->assertMatchesRegularExpression('/^INC-\d{4}-[A-F0-9]{5}$/D',$data['tracking_number']);
        $grant=$this->grantRow();$this->assertSame(32,strlen($grant['token_hash']));$this->assertSame(hash('sha256',$data['upload_token'],true),$grant['token_hash']);
        $this->assertStringNotContainsString($data['upload_token'],json_encode(array_map(fn($v)=>is_string($v)?bin2hex($v):$v,$grant)));
        $this->assertSame(600,strtotime($grant['fecha_expiracion'])-strtotime($grant['fecha_creacion']));
    }
    public static function reservedFields(): array { return [['Resuelta','Alta'],['En Proceso','Baja'],['INVALID',null]]; }

    public function testCrewKeepsPointAndSessionIdentity(): void {
        $before=IncidenciaUploadToken::now()->format('Y-m-d H:i:s');
        $data=$this->incident(['id_contenedor'=>null,'latitud'=>-34.9,'longitud'=>-56.2,
            'estado'=>'Resuelta','prioridad'=>'Alta','fecha_reporte'=>'2099-01-01','id_usuario'=>2,'id_cuadrilla'=>99],1);
        $row=$this->db->query('SELECT * FROM incidencia')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1,(int)$row['id_usuario']);$this->assertSame(1,(int)$this->grantRow()['id_usuario_emisor']);
        $this->assertSame('Pendiente',$row['estado']);$this->assertSame('Media',$row['prioridad']);
        $this->assertNull($row['id_cuadrilla']);$this->assertNull($row['fecha_resolucion']);
        $this->assertEquals(-34.9,$row['latitud']);$this->assertEquals(-56.2,$row['longitud']);
        $this->assertGreaterThanOrEqual($before,$row['fecha_reporte']);
        $this->assertSame(201,$this->upload($data,1)['statusCode']);
    }
    /** @dataProvider solicitudStates */
    public function testSolicitudIgnoresStateDateAndTracking(string $state): void {
        $before=IncidenciaUploadToken::now()->format('Y-m-d H:i:s');
        $result=(new SolicitudController($this->db))->create($this->solicitudBody()+['estado'=>$state,'fecha'=>'2099-01-01','tracking_number'=>'CLIENT']);
        $this->assertSame(201,$result['statusCode']);$row=$this->db->query('SELECT * FROM solicitud')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('Pendiente',$row['estado']);$this->assertGreaterThanOrEqual($before,$row['fecha']);
        $this->assertMatchesRegularExpression('/^REF-\d{4}-[A-F0-9]{5}$/D',$row['tracking_number']);
    }
    public static function solicitudStates(): array {return [['Finalizada'],['Programada'],['Cancelada']];}
    private function solicitudBody(): array {return ['descripcion'=>'Test','direccion'=>'Test','id_tipo_residuo'=>1,'email'=>'test@test.invalid','telefono'=>'099111111','tipo_solicitud'=>'Reciclables'];}
    public function testSolicitudRejectsNonexistentCatalogEntry(): void {
        $body=$this->solicitudBody();$body['id_tipo_residuo']=999;
        $this->assertSame(400,(new SolicitudController($this->db))->create($body)['statusCode']);
        $this->assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM solicitud')->fetchColumn());
    }
    /** @dataProvider missingCapabilities */
    public function testIdTrackingAndSessionDoNotAuthorize(array $body): void {
        $this->incident();$this->assertSame(400,$this->upload($body,1)['statusCode']);$this->assertUnused();
    }
    public static function missingCapabilities(): array {return [[['id_incidencia'=>1]],[['tracking_number'=>'INC-2026-ABCDE']],[['id_incidencia'=>1,'upload_token'=>['bad']]]];}
    public function testUnknownCapabilityRejected(): void {$data=$this->incident();$data['upload_token']=str_repeat('f',64);$this->assertSame(403,$this->upload($data)['statusCode']);$this->assertUnused();}
    public function testWrongIncidentRejected(): void {$data=$this->incident();$data['id_incidencia']=999;$this->assertSame(403,$this->upload($data)['statusCode']);$this->assertUnused();}
    public function testExpiredRejected(): void {
        $data=$this->incident();$this->db->exec("UPDATE incidencia_upload_token SET fecha_creacion='2000-01-01 00:00:00',fecha_expiracion='2000-01-01 00:10:00'");
        $this->assertSame(403,$this->upload($data)['statusCode']);$this->assertUnused();
    }
    public function testDeletedIncidentRejected(): void {
        $data=$this->incident();$this->db->exec('DELETE FROM incidencia');
        $this->assertSame(403,$this->upload($data)['statusCode']);$this->assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM foto')->fetchColumn());
    }
    public function testAnonymousSuccessReplayAndHistoricalRead(): void {
        $data=$this->incident();$result=$this->upload($data);$this->assertSame(201,$result['statusCode']);
        $this->assertSame($data['id_incidencia'],$result['data']['id_incidencia']);$this->assertIsInt($result['data']['id_foto']);
        $photo=(new Foto($this->db))->findById($result['data']['id_foto']);
        $this->assertSame($data['id_incidencia'],(int)$photo['id_incidencia']);
        $this->assertNotNull(FotoStorage::resolveExistingPath($this->directory,$photo['url']));
        $this->assertNotNull($this->grantRow()['fecha_consumo']);$this->assertSame(409,$this->upload($data)['statusCode']);
        $this->assertSame(1,(int)$this->db->query('SELECT COUNT(*) FROM foto')->fetchColumn());
    }
    public function testPhotoDeletionDoesNotRenewCapability(): void {
        $data=$this->incident();$this->assertSame(201,$this->upload($data)['statusCode']);$this->db->exec('DELETE FROM foto');
        $this->assertNull($this->grantRow()['id_foto']);$this->assertNotNull($this->grantRow()['fecha_consumo']);$this->assertSame(409,$this->upload($data)['statusCode']);
    }
    /** @dataProvider invalidFiles */
    public function testInvalidUploadDoesNotConsume(string $kind,int $code): void {
        $data=$this->incident();$file=$this->file;
        if($kind==='mime')file_put_contents($file['tmp_name'],'not an image');
        if($kind==='extension')$file['name']='image.jpg';
        if($kind==='size')$file['size']=FotoStorage::MAX_FILE_SIZE+1;
        if($kind==='actual size')file_put_contents($file['tmp_name'],str_repeat('x',FotoStorage::MAX_FILE_SIZE+1));
        if($kind==='partial')$file['error']=UPLOAD_ERR_PARTIAL;
        $this->assertSame($code,$this->upload($data,null,$file)['statusCode']);$this->assertUnused();
    }
    public static function invalidFiles(): array {return [['mime',400],['extension',400],['size',413],['actual size',413],['partial',400]];}
    public function testMoveFailureDoesNotConsume(): void {
        $data=$this->incident();$this->assertSame(500,$this->upload($data,null,null,static fn()=>false)['statusCode']);$this->assertUnused();
        $this->assertSame(201,$this->upload($data)['statusCode']);
    }
    protected function failPhoto(): void {$this->db->exec("CREATE TRIGGER fail_photo BEFORE INSERT ON foto BEGIN SELECT RAISE(ABORT,'Test'); END");}
    protected function failGrant(): void {$this->db->exec("CREATE TRIGGER fail_grant BEFORE INSERT ON incidencia_upload_token BEGIN SELECT RAISE(ABORT,'Test'); END");}
    protected function failConsume(): void {$this->db->exec("CREATE TRIGGER fail_consume BEFORE UPDATE ON incidencia_upload_token BEGIN SELECT RAISE(ABORT,'Test'); END");}
    public function testPhotoPersistenceFailureRollsBackAndCleansFile(): void {
        $data=$this->incident();$this->failPhoto();$this->assertSame(500,$this->upload($data)['statusCode']);$this->assertUnused();
        $this->assertCount(1,glob($this->directory.DIRECTORY_SEPARATOR.'*'));$this->db->exec('DROP TRIGGER fail_photo');
        $this->assertSame(201,$this->upload($data)['statusCode']);
    }
    public function testGrantFailureRollsBackIncident(): void {
        $this->failGrant();$result=(new IncidenciaController($this->db))->create(['descripcion'=>'Test','tipo_problema'=>'Contenedor Desbordado','id_contenedor'=>1]);
        $this->assertSame(500,$result['statusCode']);$this->assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM incidencia')->fetchColumn());
        $this->assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM incidencia_upload_token')->fetchColumn());
    }
    public function testConsumeFailureRollsBackPhotoAndCleansFile(): void {
        $data=$this->incident();$this->failConsume();
        $this->assertSame(500,$this->upload($data)['statusCode']);$this->assertUnused();
        $this->assertCount(1,glob($this->directory.DIRECTORY_SEPARATOR.'*'));
        $this->db->exec('DROP TRIGGER fail_consume');$this->assertSame(201,$this->upload($data)['statusCode']);
    }
    public function testInvalidMimeCanBeRetriedWithSameCapability(): void {
        $data=$this->incident();$bad=$this->file;$bad['name']='image.jpg';
        $this->assertSame(400,$this->upload($data,null,$bad)['statusCode']);$this->assertUnused();
        $this->assertSame(201,$this->upload($data)['statusCode']);
    }
    /** @dataProvider issuerChanges */
    public function testBoundGrantRequiresSameActiveUserAndCurrentPermission(string $change): void {
        $data=$this->incident([],1);$actor=1;
        if($change==='other')$actor=2;if($change==='anonymous')$actor=null;
        if($change==='revoked')$this->db->exec('DELETE FROM rol_permiso');
        if($change==='inactive')$this->db->exec("UPDATE usuario SET activo='Inactivo' WHERE id_usuario=1");
        if($change==='expired role')$this->db->exec("UPDATE usuario_rol SET fecha_hasta='2020-01-02'");
        if($change==='future role')$this->db->exec("UPDATE usuario_rol SET fecha_desde='2099-01-01'");
        if($change==='sector')$this->db->exec("UPDATE usuario_rol SET sector='TI'");
        if($change==='TI bypass'){$this->db->exec('DELETE FROM rol_permiso');$this->db->exec("UPDATE usuario_rol SET id_rol=2,sector='TI'");}
        $this->assertSame(403,$this->upload($data,$actor)['statusCode']);$this->assertUnused();
    }
    public static function issuerChanges(): array {return array_map(fn($s)=>[$s],['other','anonymous','revoked','inactive','expired role','future role','sector','TI bypass']);}
}
