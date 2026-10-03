<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../controllers/AsignacionVehiculoController.php';
require_once __DIR__.'/../controllers/VehiculoController.php';
require_once __DIR__.'/../models/Recorrido.php';
require_once __DIR__.'/fixtures/asignacion_vehiculo.php';

class AsignacionVehiculoTest extends TestCase
{
    protected PDO $db;
    private array $session;
    protected function connect(): PDO { return new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
    protected function schema(): void {
        $this->db->exec("CREATE TABLE centro(id_centro INTEGER PRIMARY KEY,nombre TEXT,direccion TEXT);
            CREATE TABLE usuario(id_usuario INTEGER PRIMARY KEY,nombre TEXT,apellido TEXT,email TEXT,contrasena TEXT,fecha_registro TEXT,id_centro INTEGER,activo TEXT DEFAULT 'Activo');
            CREATE TABLE rol(id_rol INTEGER PRIMARY KEY,nombre TEXT);
            CREATE TABLE permiso(id_permiso INTEGER PRIMARY KEY,nombre TEXT);
            CREATE TABLE rol_permiso(id_rol INTEGER,id_permiso INTEGER);
            CREATE TABLE usuario_rol(id_usuario INTEGER,id_rol INTEGER,sector TEXT,fecha_desde TEXT,fecha_hasta TEXT);
            CREATE TABLE cuadrilla(id_cuadrilla INTEGER PRIMARY KEY,nombre TEXT,turno TEXT,id_centro INTEGER);
            CREATE TABLE usuario_cuadrilla(id_usuario_cuadrilla INTEGER PRIMARY KEY,id_usuario INTEGER,id_cuadrilla INTEGER,fecha_inicio TEXT,fecha_fin TEXT,id_usuario_asigna INTEGER);
            CREATE TABLE tipo_residuo(id_tipo_residuo INTEGER PRIMARY KEY,nombre TEXT);
            CREATE TABLE vehiculo(id_vehiculo INTEGER PRIMARY KEY AUTOINCREMENT,id_tipo_residuo INTEGER,matricula TEXT UNIQUE,marca TEXT,modelo TEXT,capacidad_carga NUMERIC,estado TEXT,activo INTEGER DEFAULT 1,funcion_operativa TEXT CHECK(funcion_operativa IS NULL OR funcion_operativa IN ('REGULAR','APOYO')));
            CREATE TABLE usa(id_usa INTEGER PRIMARY KEY,id_cuadrilla INTEGER,id_vehiculo INTEGER);
            CREATE TABLE ruta(id_ruta INTEGER PRIMARY KEY,nombre TEXT,zona TEXT);
            CREATE TABLE recorrido(id_recorrido INTEGER PRIMARY KEY,fecha_inicio TEXT,fecha_fin TEXT,estado TEXT,id_ruta INTEGER,id_usuario_inicio INTEGER,id_usuario_fin INTEGER);
            CREATE TABLE participa(id_participa INTEGER PRIMARY KEY,id_usa INTEGER,id_recorrido INTEGER,hora_inicio TEXT,hora_fin TEXT);
            CREATE TABLE atencion_contenedor(id_atencion_contenedor INTEGER PRIMARY KEY,id_recorrido INTEGER,id_contenedor INTEGER,id_usuario INTEGER,fecha_atencion TEXT);
            CREATE TABLE contenedor(id_contenedor INTEGER PRIMARY KEY,id_ruta INTEGER,codigo TEXT,direccion TEXT,latitud NUMERIC,longitud NUMERIC,activo INTEGER);");
        createAsignacionVehiculoFixture($this->db);
    }
    protected function seed(): void {
        $this->db->exec("INSERT INTO centro(id_centro,nombre,direccion) VALUES(1,'Test','Test');
            INSERT INTO usuario(id_usuario,nombre,apellido,email,contrasena,fecha_registro,id_centro) VALUES
            (1,'Gestion','Test','uno@test.invalid','x','2020-01-01',1),(2,'Operario','Test','dos@test.invalid','x','2020-01-01',1),(3,'Otro','Test','tres@test.invalid','x','2020-01-01',1),(4,'TI','Test','ti@test.invalid','x','2020-01-01',1);
            INSERT INTO rol(id_rol,nombre) VALUES(1,'RESPONSABLE_SECTORIAL'),(2,'OPERARIO'),(3,'ADMINISTRADOR_TI'),(4,'ADMINISTRATIVO_OPERATIVO');
            INSERT INTO permiso(id_permiso,nombre) VALUES(1,'asignacion_vehiculo.consultar'),(2,'asignacion_vehiculo.modificar'),(3,'recorrido.consultar'),(4,'recorrido.operar');
            INSERT INTO rol_permiso VALUES(1,1),(1,2),(2,3),(2,4);
            INSERT INTO usuario_rol(id_usuario,id_rol,sector,fecha_desde) VALUES(1,1,'OPERACIONES','2020-01-01'),(2,2,'OPERACIONES','2020-01-01'),(3,2,'OPERACIONES','2020-01-01'),(4,3,'TI','2020-01-01');
            INSERT INTO cuadrilla(id_cuadrilla,nombre,turno,id_centro) VALUES(1,'Uno','Matutino',1),(2,'Dos','Matutino',1),(3,'Vacia','Matutino',1);
            INSERT INTO usuario_cuadrilla(id_usuario,id_cuadrilla,fecha_inicio,id_usuario_asigna) VALUES(2,1,'2020-01-01',1),(3,2,'2020-01-01',1);
            INSERT INTO tipo_residuo(id_tipo_residuo,nombre) VALUES(1,'Test');
            INSERT INTO vehiculo(id_vehiculo,id_tipo_residuo,matricula,marca,modelo,capacidad_carga,estado,funcion_operativa) VALUES
            (1,1,'V1','Test','Test',10,'Disponible','REGULAR'),(2,1,'V2','Test','Test',10,'Disponible','APOYO'),(3,1,'V3','Test','Test',10,'Disponible','REGULAR');
            INSERT INTO usa(id_usa,id_cuadrilla,id_vehiculo) VALUES(1,1,1),(2,1,2),(3,2,1),(4,2,2);
            INSERT INTO ruta(id_ruta,nombre,zona) VALUES(1,'Test','Test');");
    }
    protected function setUp(): void {
        $this->session=$_SESSION ?? []; $_SESSION=['usuario'=>['id_usuario'=>1]];
        $this->db=$this->connect(); $this->schema(); $this->seed();
    }
    protected function tearDown(): void { $_SESSION=$this->session; }
    protected function request(array $body): array { return (new AsignacionVehiculoController($this->db))->handle('POST',$body); }
    protected function open(int $squad=1,int $vehicle=1): array { return $this->request(['accion'=>'abrir','id_cuadrilla'=>$squad,'id_vehiculo'=>$vehicle]); }
    protected function change(int $id,int $vehicle=2): array { return $this->request(['accion'=>'cambiar','id_cuadrilla'=>1,'id_vehiculo'=>$vehicle,'id_asignacion_vehiculo'=>$id,'motivo'=>'Cambio autorizado']); }
    protected function close(int $id): array { return $this->request(['accion'=>'cerrar','id_cuadrilla'=>1,'id_asignacion_vehiculo'=>$id,'motivo'=>'Fin de utilizacion']); }
    public function testAperturaCambioCierreConservanHistoria(): void {
        $start=(new DateTimeImmutable('now',new DateTimeZone('America/Montevideo')))->format('Y-m-d H:i:s');
        $a=$this->open(); $this->assertSame(200,$a['statusCode']); $id=$a['data']['id_asignacion_vehiculo'];
        $old=$this->db->query('SELECT * FROM asignacion_vehiculo_operativa')->fetch(PDO::FETCH_ASSOC);
        $this->assertGreaterThanOrEqual($start,$old['fecha_inicio']);
        $this->assertLessThanOrEqual((new DateTimeImmutable('now',new DateTimeZone('America/Montevideo')))->format('Y-m-d H:i:s'),$old['fecha_inicio']);
        $b=$this->change($id); $this->assertSame(200,$b['statusCode']); $new=$b['data']['id_asignacion_vehiculo']; $this->assertNotSame($id,$new);
        $closed=$this->db->query('SELECT * FROM asignacion_vehiculo_operativa WHERE id_asignacion_vehiculo='.$id)->fetch(PDO::FETCH_ASSOC);
        foreach(['id_cuadrilla','id_vehiculo','fecha_inicio','id_usuario_asigna'] as $key) $this->assertSame($old[$key],$closed[$key]);
        $this->assertSame(1,(int)$closed['id_usuario_finaliza']); $this->assertNotNull($closed['fecha_fin']);
        $this->assertSame(200,$this->close($new)['statusCode']); $this->assertSame(409,$this->close($new)['statusCode']);
        $this->assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM asignacion_vehiculo_operativa WHERE fecha_fin IS NULL')->fetchColumn());
        $data=(new AsignacionVehiculoController($this->db))->handle('GET',['id_cuadrilla'=>1]);
        $this->assertCount(2,$data['data']['historial']); $this->assertNull($data['data']['actual']);
        $this->assertSame('Cambio autorizado',$closed['motivo_cierre']);
    }
    public static function exclusions(): array { return [
        ['DELETE FROM usa WHERE id_cuadrilla=1 AND id_vehiculo=1'],
        ['INSERT INTO usa(id_cuadrilla,id_vehiculo) VALUES(1,1)'],
        ['UPDATE vehiculo SET activo=0 WHERE id_vehiculo=1'],
        ["UPDATE vehiculo SET estado='En Mantenimiento' WHERE id_vehiculo=1"],
        ['UPDATE vehiculo SET funcion_operativa=NULL WHERE id_vehiculo=1'],
        ["UPDATE usuario SET activo='Inactivo' WHERE id_usuario=2"],
        ["UPDATE usuario_rol SET sector='TI' WHERE id_usuario=2"],
        ["UPDATE usuario_rol SET fecha_hasta='2020-01-02' WHERE id_usuario=2"],
        ["UPDATE usuario_cuadrilla SET fecha_fin='2020-01-02',id_usuario_asigna=1 WHERE id_usuario=2"],
    ]; }
    /** @dataProvider exclusions */
    public function testRechazaRecursosNoElegibles(string $sql): void {
        // MariaDB exige actor de cierre para la pertenencia.
        if (str_contains($sql,'UPDATE usuario_cuadrilla') && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') $sql=str_replace('id_usuario_asigna=1','id_usuario_finaliza=1',$sql);
        $this->db->exec($sql); $this->assertSame(409,$this->open()['statusCode']);
        $this->assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM asignacion_vehiculo_operativa')->fetchColumn());
    }
    public function testUnicidadesYRollbackCambio(): void {
        $id=$this->open()['data']['id_asignacion_vehiculo'];
        $this->assertSame(409,$this->open(1,2)['statusCode']); $this->assertSame(409,$this->open(2,1)['statusCode']);
        $this->assertSame(200,$this->open(2,2)['statusCode']); $this->assertSame(409,$this->change($id)['statusCode']);
        $this->assertNull($this->db->query('SELECT fecha_fin FROM asignacion_vehiculo_operativa WHERE id_asignacion_vehiculo='.$id)->fetchColumn());
    }
    public static function invalidBodies(): array { return [
        [['id_usuario'=>2]], [['fecha_inicio'=>'2020-01-01']], [['id_cuadrilla'=>0]], [['id_vehiculo'=>[]]], [['accion'=>'editar']],
    ]; }
    /** @dataProvider invalidBodies */
    public function testIdentidadesYCamposManipulados(array $extra): void {
        $this->assertSame(400,$this->request(array_replace(['accion'=>'abrir','id_cuadrilla'=>1,'id_vehiculo'=>1],$extra))['statusCode']);
    }
    public function testSesionPermisosTiYMotivo(): void {
        $_SESSION=[]; $this->assertSame(401,$this->open()['statusCode']);
        $_SESSION=['usuario'=>['id_usuario'=>4,'roles'=>['ADMINISTRADOR_TI']]]; $this->assertSame(403,$this->open()['statusCode']);
        $_SESSION=['usuario'=>['id_usuario'=>1]]; $id=$this->open()['data']['id_asignacion_vehiculo'];
        foreach(['',str_repeat('x',151),[]] as $reason) $this->assertSame(400,$this->request(['accion'=>'cerrar','id_cuadrilla'=>1,'id_asignacion_vehiculo'=>$id,'motivo'=>$reason])['statusCode']);
        $this->db->exec("UPDATE usuario SET activo='Inactivo' WHERE id_usuario=1"); $this->assertSame(401,$this->close($id)['statusCode']);
    }
    public static function functions(): array { return [['REGULAR'],['APOYO'],[null]]; }
    /** @dataProvider functions */
    public function testCrudFuncion($function): void {
        $c=new VehiculoController($this->db); $body=['matricula'=>'NEW','marca'=>'Test','modelo'=>'Test','capacidad_carga'=>10,'estado'=>'Disponible','id_tipo_residuo'=>1,'funcion_operativa'=>$function];
        $this->assertSame(201,$c->create($body)['statusCode']);
        $this->assertSame($function,$this->db->query("SELECT funcion_operativa FROM vehiculo WHERE matricula='NEW'")->fetchColumn());
        $body['id_vehiculo']=(int)$this->db->query("SELECT id_vehiculo FROM vehiculo WHERE matricula='NEW'")->fetchColumn(); $body['funcion_operativa']='APOYO';
        $this->assertSame(200,$c->update($body)['statusCode']); $this->assertSame(200,$c->delete($body['id_vehiculo'])['statusCode']);
    }
    public function testPoliticaVehiculoAbiertoYContratoLegacy(): void {
        $id=$this->open()['data']['id_asignacion_vehiculo']; $c=new VehiculoController($this->db);
        $body=['id_vehiculo'=>1,'matricula'=>'V1','marca'=>'Test','modelo'=>'Test','capacidad_carga'=>10,'estado'=>'Disponible','id_tipo_residuo'=>1,'funcion_operativa'=>'REGULAR'];
        foreach([['funcion_operativa'=>null],['funcion_operativa'=>'APOYO'],['estado'=>'En Mantenimiento']] as $change) $this->assertSame(409,$c->update(array_replace($body,$change))['statusCode']);
        $this->assertSame(409,$c->delete(1)['statusCode']);
        unset($body['funcion_operativa']); $this->assertSame(200,$c->update($body)['statusCode']);
        $this->assertSame('REGULAR',$this->db->query('SELECT funcion_operativa FROM vehiculo WHERE id_vehiculo=1')->fetchColumn());
        $this->assertSame(400,$c->update($body+['funcion_operativa'=>'regular'])['statusCode']);
        $this->assertSame(200,$this->close($id)['statusCode']); $this->assertSame(200,$c->update($body+['funcion_operativa'=>'APOYO'])['statusCode']);
        $this->assertSame(200,$this->open()['statusCode']);
        $this->assertSame(409,$c->update($body+['funcion_operativa'=>'REGULAR'])['statusCode']);
    }
    protected function trip(int $use=1): void {
        $this->db->exec("INSERT INTO recorrido(id_recorrido,fecha_inicio,estado,id_ruta) VALUES(1,'2020-01-01','En Proceso',1);
            INSERT INTO participa(id_usa,id_recorrido,hora_inicio) VALUES($use,1,'08:00:00');");
    }
    public function testCoherenciaRecorridosYFinalizacionIndependiente(): void {
        $this->trip(); $this->assertSame(409,$this->open(1,2)['statusCode']); $this->assertSame(409,$this->open(2,1)['statusCode']);
        $id=$this->open()['data']['id_asignacion_vehiculo'];
        (new RecoleccionOperativa($this->db))->operar(2,1,'finalizar',null);
        $this->assertNull($this->db->query('SELECT fecha_fin FROM asignacion_vehiculo_operativa')->fetchColumn());
        $this->assertSame(200,$this->change($id)['statusCode']);
        $r=new Recorrido($this->db); $r->id_recorrido=1; $r->fecha_inicio='2020-01-01'; $r->estado='Pendiente'; $r->id_ruta=1;
        try { $r->update(); $this->fail('Debe rechazar reactivacion incompatible.'); } catch(DomainException $e) { $this->assertSame(409,$e->getCode()); }
    }
    public function testRecursosInexistentesYCambioMismoVehiculo(): void {
        $this->assertSame(404,$this->open(999,1)['statusCode']);
        $this->assertSame(404,$this->open(1,999)['statusCode']);
        $this->assertSame(409,$this->open(3,1)['statusCode']);
        $id=$this->open()['data']['id_asignacion_vehiculo'];
        $this->assertSame(409,$this->change($id,1)['statusCode']);
        $this->assertSame(409,$this->close($id+100)['statusCode']);
    }
    public function testRecorridoNuevoEInicioRevalidanUtilizacion(): void {
        $this->assertSame(200,$this->open(1,2)['statusCode']);
        $this->db->exec("INSERT INTO recorrido(id_recorrido,fecha_inicio,estado,id_ruta) VALUES(1,'2020-01-01','Pendiente',1)");
        $op=new RecoleccionOperativa($this->db);
        try { $op->asignarRecorrido(1,1,1,1); $this->fail('Debe rechazar vehiculo incompatible.'); }
        catch(DomainException $e) { $this->assertSame(409,$e->getCode()); }
        $this->assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM participa')->fetchColumn());
        $this->db->exec("INSERT INTO participa(id_usa,id_recorrido,hora_inicio) VALUES(1,1,'08:00:00')");
        try { $op->operar(2,1,'iniciar',null); $this->fail('Debe rechazar inicio incompatible.'); }
        catch(DomainException $e) { $this->assertSame(409,$e->getCode()); }
        $assignment=(int)$this->db->query('SELECT id_asignacion_vehiculo FROM asignacion_vehiculo_operativa WHERE fecha_fin IS NULL')->fetchColumn();
        try { $op->validarOpcionF3(1,$assignment,1,1,true); $this->fail('F3 debe rechazar opcion contradictoria.'); }
        catch(DomainException $e) { $this->assertSame(409,$e->getCode()); }
    }
}
