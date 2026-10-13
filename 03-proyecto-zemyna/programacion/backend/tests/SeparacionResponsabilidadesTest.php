<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../controllers/RecoleccionController.php';

final class SeparacionResponsabilidadesTest extends TestCase
{
    private PDO $db;
    private array $previous;
    private bool $started = false;
    private string $savePath;
    protected function setUp(): void {
        $this->savePath = session_save_path();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_save_path(sys_get_temp_dir()); session_start(); $this->started = true;
        }
        $this->previous = $_SESSION ?? [];
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE usuario(id_usuario INTEGER,nombre TEXT,apellido TEXT,activo TEXT,email TEXT,telefono TEXT);
            CREATE TABLE rol(id_rol INTEGER,nombre TEXT);
            CREATE TABLE usuario_rol(id_usuario INTEGER,id_rol INTEGER,sector TEXT,fecha_desde TEXT,fecha_hasta TEXT);
            CREATE TABLE permiso(id_permiso INTEGER,nombre TEXT); CREATE TABLE rol_permiso(id_rol INTEGER,id_permiso INTEGER);
            CREATE TABLE cuadrilla(id_cuadrilla INTEGER,nombre TEXT,turno TEXT);
            CREATE TABLE usuario_cuadrilla(id_usuario_cuadrilla INTEGER,id_usuario INTEGER,id_cuadrilla INTEGER,fecha_inicio TEXT,fecha_fin TEXT,id_usuario_asigna INTEGER,id_usuario_finaliza INTEGER);
            INSERT INTO usuario VALUES(1,'Gestor','Prueba','Activo','privado@test.invalid','privado'),
                (2,'Tecnico','Prueba','Activo','privado@test.invalid','privado'),
                (3,'Elegible','Prueba','Activo','privado@test.invalid','privado'),
                (4,'Inspector','Prueba','Activo','privado@test.invalid','privado'),
                (5,'OtroSector','Prueba','Activo','privado@test.invalid','privado'),
                (6,'Suspendido','Prueba','Inactivo','privado@test.invalid','privado'),
                (7,'Futuro','Prueba','Activo','privado@test.invalid','privado');
            INSERT INTO rol VALUES(1,'ADMINISTRATIVO_OPERATIVO'),(2,'ADMINISTRADOR_TI'),(3,'OPERARIO'),(4,'INSPECTOR');
            INSERT INTO permiso VALUES(1,'recorrido.consultar'),(2,'cuadrilla.consultar'),(3,'cuadrilla.modificar'),(4,'recorrido.operar'),(5,'usuario.consultar'),(6,'usuario.modificar'),(7,'usuario.asignar_rol');
            INSERT INTO rol_permiso VALUES(1,1),(1,2),(1,3),(2,1),(2,5),(2,6),(2,7),(3,1),(3,2),(3,4),(4,1),(4,2);
            INSERT INTO usuario_rol VALUES(1,1,'OPERACIONES','2020-01-01',NULL),(2,2,'TI','2020-01-01',NULL),
                (3,3,'OPERACIONES','2020-01-01',NULL),(4,4,'INSPECCION','2020-01-01',NULL),
                (5,3,'MANTENIMIENTO','2020-01-01',NULL),(6,3,'OPERACIONES','2020-01-01',NULL),(7,3,'OPERACIONES','2999-01-01',NULL);
            INSERT INTO cuadrilla VALUES(10,'Destino','Matutino');
            INSERT INTO usuario_cuadrilla VALUES(1,3,10,'2020-01-01',NULL,1,NULL);");
        AuthRequestContext::$connection = $this->db;
        AuthRequestContext::$resolver = null;
        $_SESSION = ['usuario' => ['id_usuario' => 1, 'roles' => ['ADMINISTRADOR_TI']]];
    }
    protected function tearDown(): void {
        AuthRequestContext::$connection = null;
        AuthRequestContext::$resolver = null;
        $_SESSION = $this->previous;
        if ($this->started) { session_destroy(); session_save_path($this->savePath); }
    }
    private function get(array $query): array { return (new RecoleccionController($this->db))->consultar($query); }
    public function testGestorConsultaSoloElegiblesSinAdministracionDeUsuarios(): void {
        $this->assertFalse(hasEffectivePermission('usuario.consultar',['TI']));
        $flags=$this->get(['view'=>'permisos']);
        $this->assertTrue($flags['data']['modificar_integrantes']);
        $this->assertFalse($flags['data']['usuarios']);
        $r=$this->get(['view'=>'integrantes','id_cuadrilla'=>10]);
        $this->assertSame(200,$r['statusCode']);
        $this->assertSame([3],array_map('intval',array_column($r['data']['elegibles'],'id_usuario')));
        $this->assertSame(['id_usuario','nombre','apellido','pertenencia'],array_keys($r['data']['elegibles'][0]));
        $this->assertSame([],$r['data']['no_elegibles']);
        $serialized=json_encode($r);
        foreach(['email','telefono','privado@test.invalid','Suspendido','OtroSector','Futuro'] as $secret) $this->assertStringNotContainsString($secret,$serialized);
    }
    public function testTecnicoNecesitaPermisoYSectorExplicitos(): void {
        $_SESSION['usuario']['id_usuario']=2;
        foreach(['usuario.consultar','usuario.modificar','usuario.asignar_rol'] as $permission) $this->assertTrue(hasEffectivePermission($permission,['TI']));
        $this->assertFalse(hasEffectivePermission('recorrido.consultar',['OPERACIONES']));
        $this->assertFalse(hasEffectivePermission('usuario.crear',['TI']));
        $this->assertSame(403,$this->get(['view'=>'integrantes','id_cuadrilla'=>10])['statusCode']);
    }
    public function testOperarioInspectorYRevocacionNoAmplianAcceso(): void {
        foreach([3,4] as $actor) {
            $_SESSION['usuario']['id_usuario']=$actor;
            $this->assertSame(403,$this->get(['view'=>'integrantes','id_cuadrilla'=>10])['statusCode']);
            $r=(new RecoleccionController($this->db))->modificar(['accion'=>'asignar','integrante'=>3,'destino'=>10]);
            $this->assertSame(403,$r['statusCode']);
        }
        $_SESSION['usuario']['id_usuario']=1;
        $this->db->exec('DELETE FROM rol_permiso WHERE id_rol=1 AND id_permiso=3');
        $this->assertSame(403,$this->get(['view'=>'integrantes','id_cuadrilla'=>10])['statusCode']);
        $this->assertSame(1,(int)$this->db->query('SELECT COUNT(*) FROM usuario_cuadrilla')->fetchColumn());
    }
}
