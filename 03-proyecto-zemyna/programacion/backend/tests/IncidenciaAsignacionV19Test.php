<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../controllers/IncidenciaController.php';
require_once __DIR__.'/fixtures/asignacion_vehiculo.php';
require_once __DIR__.'/fixtures/atencion_incidencia.php';

/** Solo datos aislados; misma lógica y fixture base para SQLite y MariaDB. */
class IncidenciaAsignacionV19Test extends TestCase
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
            CREATE TABLE usuario_cuadrilla(id_usuario_cuadrilla INTEGER PRIMARY KEY,id_usuario INTEGER,id_cuadrilla INTEGER,fecha_inicio TEXT,fecha_fin TEXT,id_usuario_asigna INTEGER,id_usuario_finaliza INTEGER);
            CREATE TABLE tipo_residuo(id_tipo_residuo INTEGER PRIMARY KEY,nombre TEXT);
            CREATE TABLE vehiculo(id_vehiculo INTEGER PRIMARY KEY,matricula TEXT,marca TEXT,modelo TEXT,capacidad_carga NUMERIC,id_tipo_residuo INTEGER,estado TEXT,activo INTEGER DEFAULT 1,funcion_operativa TEXT);
            CREATE TABLE usa(id_usa INTEGER PRIMARY KEY,id_cuadrilla INTEGER,id_vehiculo INTEGER);
            CREATE TABLE ruta(id_ruta INTEGER PRIMARY KEY,nombre TEXT,zona TEXT);
            CREATE TABLE recorrido(id_recorrido INTEGER PRIMARY KEY,id_ruta INTEGER,fecha_inicio TEXT,fecha_fin TEXT,estado TEXT);
            CREATE TABLE participa(id_participa INTEGER PRIMARY KEY,id_usa INTEGER,id_recorrido INTEGER,hora_inicio TEXT,hora_fin TEXT);
            CREATE TABLE incidencia(id_incidencia INTEGER PRIMARY KEY,tracking_number TEXT,descripcion TEXT,fecha_reporte TEXT,estado TEXT,prioridad TEXT,tipo_problema TEXT,id_cuadrilla INTEGER,id_ruta INTEGER);");
        createAsignacionVehiculoFixture($this->db); createAttentionFixture($this->db);
    }
    protected function seed(): void {
        $this->db->exec("INSERT INTO centro(id_centro,nombre,direccion) VALUES(1,'Test','Test');
            INSERT INTO usuario(id_usuario,nombre,apellido,email,contrasena,fecha_registro,id_centro) VALUES
            (1,'Gestion','Test','uno@test.invalid','x','2020-01-01',1),(2,'Integrante','Test','dos@test.invalid','x','2020-01-01',1),(3,'Otro','Test','tres@test.invalid','x','2020-01-01',1),(4,'TI','Test','ti@test.invalid','x','2020-01-01',1);
            INSERT INTO rol(id_rol,nombre) VALUES(1,'ADMINISTRATIVO_OPERATIVO'),(2,'ADMINISTRADOR_TI');
            INSERT INTO permiso(id_permiso,nombre) VALUES(1,'incidencia.consultar'),(2,'incidencia.modificar'),(3,'recorrido.consultar'),(4,'recorrido.operar'),(5,'incidencia.operar'),(6,'asignacion_vehiculo.consultar'),(7,'asignacion_vehiculo.modificar');
            INSERT INTO rol_permiso VALUES(1,1),(1,2),(1,3),(1,4),(1,5),(1,6),(1,7);
            INSERT INTO usuario_rol(id_usuario,id_rol,sector,fecha_desde) VALUES(1,1,'OPERACIONES','2020-01-01'),(2,1,'OPERACIONES','2020-01-01'),(3,1,'OPERACIONES','2020-01-01'),(4,2,'TI','2020-01-01');
            INSERT INTO cuadrilla(id_cuadrilla,nombre,turno,id_centro) VALUES(1,'Uno','Matutino',1),(2,'Dos','Matutino',1);
            INSERT INTO usuario_cuadrilla(id_usuario,id_cuadrilla,fecha_inicio,id_usuario_asigna) VALUES(2,1,'2020-01-01',1),(3,2,'2020-01-01',1);
            INSERT INTO tipo_residuo(id_tipo_residuo,nombre) VALUES(1,'Test');
            INSERT INTO vehiculo(id_vehiculo,matricula,marca,modelo,capacidad_carga,id_tipo_residuo,estado,funcion_operativa) VALUES
            (1,'TEST1','Test','Test',1,1,'Disponible','REGULAR'),(2,'TEST2','Test','Test',1,1,'En Servicio','APOYO');
            INSERT INTO usa(id_usa,id_cuadrilla,id_vehiculo) VALUES(1,1,1),(2,2,2),(3,1,2);
            INSERT INTO ruta(id_ruta,nombre,zona) VALUES(1,'Test','Test');
            INSERT INTO recorrido(id_recorrido,id_ruta,fecha_inicio,estado) VALUES(1,1,'2020-01-01','Pendiente');
            INSERT INTO participa(id_participa,id_usa,id_recorrido,hora_inicio) VALUES(1,1,1,'08:00:00');
            INSERT INTO asignacion_vehiculo_operativa(id_asignacion_vehiculo,id_cuadrilla,id_vehiculo,fecha_inicio,id_usuario_asigna) VALUES(1,1,1,'2020-01-01',1),(2,2,2,'2020-01-01',1);
            INSERT INTO incidencia(id_incidencia,tracking_number,descripcion,fecha_reporte,estado,prioridad,tipo_problema,id_ruta) VALUES(1,'INC-TEST1','Test','2020-01-01','Pendiente','Alta','Contenedor Desbordado',1);");
    }
    protected function setUp(): void { $this->session=$_SESSION??[];$_SESSION=['usuario'=>['id_usuario'=>1]];$this->db=$this->connect();$this->schema();$this->seed(); }
    protected function tearDown(): void { if(isset($this->db)&&$this->db->inTransaction())$this->db->rollBack();$_SESSION=$this->session; }
    protected function payload(bool $support=false): array { return ['accion'=>'asignar','id_incidencia'=>1,'id_cuadrilla'=>$support?2:1,'id_asignacion_vehiculo'=>$support?2:1,'id_usa'=>$support?2:1,'id_recorrido'=>$support?null:1,'cuadrilla_esperada'=>null,'estado_esperado'=>'Pendiente']; }
    protected function request(array $body): array { return (new IncidenciaController($this->db))->assignAdministrative($body); }
    protected function state(): array { return [$this->db->query('SELECT * FROM incidencia ORDER BY id_incidencia')->fetchAll(PDO::FETCH_ASSOC),$this->db->query('SELECT * FROM atencion_incidencia ORDER BY id_atencion_incidencia')->fetchAll(PDO::FETCH_ASSOC)]; }
    public function testRegularYApoyoContratoYPersistencia(): void {
        $options=(new IncidenciaController($this->db))->getAssignmentOptions();$this->assertSame(200,$options['statusCode']);$this->assertCount(2,$options['data']);
        $support=array_values(array_filter($options['data'],fn($o)=>$o['funcion_operativa']==='APOYO'))[0];
        foreach(['id_recorrido','estado_recorrido','ruta_nombre'] as $key)$this->assertNull($support[$key]);
        $this->assertSame('En Servicio',$support['estado_vehiculo']);
        $trips=$this->db->query('SELECT * FROM recorrido')->fetchAll(PDO::FETCH_ASSOC);$links=$this->db->query('SELECT * FROM participa')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame(200,$this->request($this->payload())['statusCode']);
        $this->assertSame(409,$this->request($this->payload()+[])['statusCode']);
        $body=$this->payload(true);$body['cuadrilla_esperada']=1;$this->assertSame(200,$this->request($body)['statusCode']);
        $this->assertSame('Interrumpida',$this->state()[1][0]['estado']);$this->assertSame('Asignada',$this->state()[1][1]['estado']);
        $clear=['accion'=>'asignar','id_incidencia'=>1,'id_cuadrilla'=>null,'cuadrilla_esperada'=>2,'estado_esperado'=>'Pendiente'];$this->assertSame(200,$this->request($clear)['statusCode']);
        $this->assertSame($trips,$this->db->query('SELECT * FROM recorrido')->fetchAll(PDO::FETCH_ASSOC));$this->assertSame($links,$this->db->query('SELECT * FROM participa')->fetchAll(PDO::FETCH_ASSOC));
    }
    /** @dataProvider unavailable */
    public function testOpcionesObsoletasNoAlteranIncidenciaNiAtencion(bool $support,string $sql): void {
        $this->db->exec($sql);$before=$this->state();$this->assertSame(409,$this->request($this->payload($support))['statusCode']);$this->assertSame($before,$this->state());$this->assertFalse($this->db->inTransaction());
        $options=(new IncidenciaController($this->db))->getAssignmentOptions();$this->assertSame(200,$options['statusCode']);$this->assertNotContains($support?2:1,array_column($options['data'],'id_cuadrilla'));
    }
    public static function unavailable(): array {
        $out=[];foreach([false,true] as $support){$id=$support?2:1;$prefix=$support?'apoyo ':'regular ';
            foreach(['sin V19'=>"DELETE FROM asignacion_vehiculo_operativa WHERE id_cuadrilla=$id",'cerrada'=>"UPDATE asignacion_vehiculo_operativa SET fecha_fin='2026-01-01',id_usuario_finaliza=1,motivo_cierre='Test' WHERE id_cuadrilla=$id",'inactivo'=>"UPDATE vehiculo SET activo=0 WHERE id_vehiculo=$id",'mantenimiento'=>"UPDATE vehiculo SET estado='En Mantenimiento' WHERE id_vehiculo=$id",'funcion NULL'=>"UPDATE vehiculo SET funcion_operativa=NULL WHERE id_vehiculo=$id",'sin usa'=>"DELETE FROM participa WHERE id_usa=$id; DELETE FROM usa WHERE id_usa=$id",'usa duplicada'=>"INSERT INTO usa(id_usa,id_cuadrilla,id_vehiculo) VALUES(9,$id,$id)",'sin integrantes'=>"UPDATE usuario_cuadrilla SET fecha_fin='2026-01-01',id_usuario_finaliza=1 WHERE id_cuadrilla=$id"]as$name=>$sql)$out[$prefix.$name]=[$support,$sql];
        }
        $out['regular sin recorrido']=[false,'DELETE FROM participa'];
        $out['regular finalizado']=[false,"UPDATE recorrido SET estado='Finalizado'"];
        $out['regular otro vehiculo']=[false,'UPDATE participa SET id_usa=3'];
        $out['regular participacion cerrada']=[false,"UPDATE participa SET hora_fin='12:00:00'"];
        $out['apoyo recorrido contradictorio']=[true,'UPDATE participa SET id_usa=3'];
        $out['apoyo recorrido ambiguo']=[true,"UPDATE participa SET id_usa=2;INSERT INTO participa(id_participa,id_usa,id_recorrido) VALUES(2,2,1)"];
        return $out;
    }
    public function testVersionCambioStaleYContratoManipulado(): void {
        $this->db->exec("UPDATE asignacion_vehiculo_operativa SET fecha_fin='2026-01-01',id_usuario_finaliza=1,motivo_cierre='Test' WHERE id_asignacion_vehiculo=1;
            INSERT INTO asignacion_vehiculo_operativa(id_asignacion_vehiculo,id_cuadrilla,id_vehiculo,fecha_inicio,id_usuario_asigna) VALUES(3,1,1,'2026-01-01',1)");
        $before=$this->state();$this->assertSame(409,$this->request($this->payload())['statusCode']);$this->assertSame($before,$this->state());
        foreach(['id_vehiculo'=>1,'id_usuario'=>2,'fecha_inicio'=>'2020-01-01']as$k=>$v)$this->assertSame(400,$this->request($this->payload()+[$k=>$v])['statusCode']);
        $body=$this->payload();unset($body['id_asignacion_vehiculo']);$this->assertSame(400,$this->request($body)['statusCode']);
    }
    public function testAutorizacionRealSinBypassTiNiCache(): void {
        $_SESSION=['usuario'=>['id_usuario'=>4,'roles'=>['ADMINISTRADOR_TI'],'autorizaciones'=>[['permiso'=>'incidencia.modificar','sector'=>'OPERACIONES']]]];
        $c=new IncidenciaController($this->db);$this->assertSame(403,$c->getAssignmentOptions()['statusCode']);$this->assertFalse($c->canAssignOperational());
        foreach([null,1,2]as$s){$body=$this->payload();$body['id_cuadrilla']=$s;$this->assertSame(403,$c->assignAdministrative($body)['statusCode']);}
        $_SESSION=['usuario'=>['id_usuario'=>1]];$this->db->exec('DELETE FROM rol_permiso WHERE id_permiso=2');$this->assertSame(403,$c->getAssignmentOptions()['statusCode']);$this->assertSame(403,$this->request($this->payload())['statusCode']);
    }
    public function testApoyoRecorridoCoherenteOpcionalYNoExclusividadIncidencia(): void {
        $this->db->exec('UPDATE participa SET id_usa=2');$this->assertSame(200,$this->request($this->payload(true))['statusCode']);
        $this->db->exec("INSERT INTO incidencia(id_incidencia,tracking_number,descripcion,fecha_reporte,estado,prioridad,tipo_problema,id_ruta) VALUES(2,'INC-TEST2','Test','2020-01-01','Pendiente','Alta','Contenedor Desbordado',1)");
        $body=$this->payload(true);$body['id_incidencia']=2;unset($body['id_recorrido']);$this->assertSame(200,$this->request($body)['statusCode']);
    }

    public function testTiConPermisosRealesEnOperacionesPuedeAsignarSinCache(): void {
        $this->db->exec("UPDATE usuario_rol SET sector='OPERACIONES' WHERE id_usuario=4; INSERT INTO rol_permiso VALUES(2,1),(2,2)");
        $_SESSION=['usuario'=>['id_usuario'=>4,'roles'=>['ADMINISTRADOR_TI'],'autorizaciones'=>[]]];
        $this->assertSame(200,(new IncidenciaController($this->db))->getAssignmentOptions()['statusCode']);
        $this->assertSame(200,$this->request($this->payload(true))['statusCode']);
    }

    /** @dataProvider invalidActors */
    public function testActorRevocadoNoPuedeAsignar(string $sql,int $status): void {
        $this->db->exec($sql);$before=$this->state();$this->assertSame($status,$this->request($this->payload())['statusCode']);$this->assertSame($before,$this->state());
    }
    public static function invalidActors(): array { return [["UPDATE usuario SET activo='Inactivo' WHERE id_usuario=1",401],["UPDATE usuario_rol SET sector='TI' WHERE id_usuario=1",403],["UPDATE usuario_rol SET fecha_hasta='2020-01-01' WHERE id_usuario=1",403],['DELETE FROM rol_permiso WHERE id_permiso=1',403]]; }
}
