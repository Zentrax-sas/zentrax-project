<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/fixtures/asignacion_vehiculo.php';
require_once __DIR__.'/fixtures/solicitud_admin_base.php';
require_once __DIR__.'/../models/Usuario.php';
require_once __DIR__.'/../controllers/UsuarioController.php';

/** C3: SQLite descartable en memoria, sin configuración ni datos operativos. */
final class ContinuidadRecorridoRegularTest extends TestCase
{
    use SolicitudAdminBase;
    private PDO $db;
    protected function setUp(): void
    {
        $this->db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->schema();$this->seed();
        $this->db->exec("ALTER TABLE recorrido ADD id_usuario_inicio INTEGER;ALTER TABLE recorrido ADD id_usuario_fin INTEGER;
            ALTER TABLE usuario ADD telefono TEXT;
            CREATE TABLE atencion_contenedor(id_atencion_contenedor INTEGER PRIMARY KEY,id_recorrido INTEGER,id_contenedor INTEGER,id_usuario INTEGER,fecha_atencion TEXT);
            CREATE TABLE contenedor(id_contenedor INTEGER PRIMARY KEY,id_ruta INTEGER,codigo TEXT,direccion TEXT,latitud REAL,longitud REAL,activo INTEGER);
            CREATE TABLE solicitud(id_solicitud INTEGER PRIMARY KEY,estado TEXT,id_cuadrilla INTEGER,id_tipo_residuo INTEGER);
            CREATE TABLE atencion_solicitud(id_atencion_solicitud INTEGER PRIMARY KEY,id_solicitud INTEGER,id_cuadrilla INTEGER,id_asignacion_vehiculo INTEGER,estado TEXT,fecha_cierre TEXT);
            UPDATE recorrido SET estado='En Proceso',id_usuario_inicio=2 WHERE id_recorrido=1;
            INSERT INTO contenedor VALUES(1,1,'PENDIENTE',NULL,NULL,NULL,1)");
    }
    private function membership(): int { return (int)$this->db->query('SELECT id_usuario_cuadrilla FROM usuario_cuadrilla WHERE id_usuario=2 AND fecha_fin IS NULL')->fetchColumn(); }
    private function memberChange(string $action): void
    {
        (new RecoleccionOperativa($this->db))->asignar(1,2,$action==='trasladar'?2:null,$action,$this->membership());
    }
    private function denied(callable $write): void
    {
        try {$write();$this->fail('Se permitió una mutación incompatible con C3.');}
        catch(DomainException $e){$this->assertSame(409,$e->getCode());}
        $this->assertFalse($this->db->inTransaction());
    }
    public function testRetiroYTrasladoDelUltimoHabilitadoRechazadosSinHistoriaParcial(): void
    {
        foreach(['finalizar_pertenencia','trasladar'] as $action) {
            $before=$this->db->query('SELECT * FROM usuario_cuadrilla ORDER BY id_usuario_cuadrilla')->fetchAll(PDO::FETCH_ASSOC);
            $this->denied(fn()=>$this->memberChange($action));
            $this->assertSame($before,$this->db->query('SELECT * FROM usuario_cuadrilla ORDER BY id_usuario_cuadrilla')->fetchAll(PDO::FETCH_ASSOC));
        }
    }
    public function testOtroIntegranteSinPermisoNoSirveComoRelevo(): void
    {
        $this->db->exec("INSERT INTO usuario_cuadrilla(id_usuario,id_cuadrilla,fecha_inicio,id_usuario_asigna) VALUES(4,1,'2020-01-01',1)");
        $this->denied(fn()=>$this->memberChange('finalizar_pertenencia'));
    }
    public function testRetiroPermitidoConOtroHabilitadoConservaHistorial(): void
    {
        $this->db->exec('UPDATE usuario_cuadrilla SET id_cuadrilla=1 WHERE id_usuario=3');
        $this->memberChange('finalizar_pertenencia');
        $this->assertNotNull($this->db->query('SELECT fecha_fin FROM usuario_cuadrilla WHERE id_usuario=2')->fetchColumn());
        $this->assertSame(1,(int)$this->db->query('SELECT COUNT(*) FROM usuario_cuadrilla WHERE id_cuadrilla=1 AND fecha_fin IS NULL')->fetchColumn());
    }
    public function testTrasladoPermitidoConRelevo(): void
    {
        $this->db->exec('UPDATE usuario_cuadrilla SET id_cuadrilla=1 WHERE id_usuario=3');
        $this->memberChange('trasladar');
        $this->assertSame(2,(int)$this->db->query('SELECT id_cuadrilla FROM usuario_cuadrilla WHERE id_usuario=2 AND fecha_fin IS NULL')->fetchColumn());
    }
    public function testSuspensionUltimoOperarioHaceRollbackYConRelevoEsPermitida(): void
    {
        $user=new Usuario($this->db);$user->id_usuario=2;
        $this->denied(fn()=>$user->delete());
        $this->assertSame('Activo',$this->db->query('SELECT activo FROM usuario WHERE id_usuario=2')->fetchColumn());
        $this->db->exec('UPDATE usuario_cuadrilla SET id_cuadrilla=1 WHERE id_usuario=3');
        $this->assertTrue($user->delete());
        $this->assertSame('Inactivo',$this->db->query('SELECT activo FROM usuario WHERE id_usuario=2')->fetchColumn());
    }
    public static function cambiosHabilitacion(): array
    {
        return [
            'desactivación'=>["UPDATE usuario SET activo='Inactivo' WHERE id_usuario=2"],
            'sector incorrecto'=>["UPDATE usuario_rol SET sector='TI' WHERE id_usuario=2"],
            'autorización vencida'=>["UPDATE usuario_rol SET fecha_hasta='2020-01-02' WHERE id_usuario=2"],
            'rol retirado'=>['DELETE FROM usuario_rol WHERE id_usuario=2'],
        ];
    }
    /** @dataProvider cambiosHabilitacion */
    public function testCambioDeHabilitacionEnPutHaceRollback(string $sql): void
    {
        $user=new Usuario($this->db);
        $before=$this->db->query('SELECT * FROM usuario_rol WHERE id_usuario=2')->fetchAll(PDO::FETCH_ASSOC);
        $r=$user->withContinuity(2,function()use($sql){
            $this->db->exec($sql);
            return ['statusCode'=>200];
        });
        $this->assertSame(409,$r['statusCode']);$this->assertFalse($this->db->inTransaction());
        $this->assertSame('Activo',$this->db->query('SELECT activo FROM usuario WHERE id_usuario=2')->fetchColumn());
        $this->assertSame($before,$this->db->query('SELECT * FROM usuario_rol WHERE id_usuario=2')->fetchAll(PDO::FETCH_ASSOC));
    }
    public function testSuspensionResponde409ControladoDesdeController(): void
    {
        $r=(new UsuarioController($this->db))->delete(2);
        $this->assertSame(409,$r['statusCode']);$this->assertFalse($r['success']);
        $this->assertSame('Activo',$this->db->query('SELECT activo FROM usuario WHERE id_usuario=2')->fetchColumn());
        $this->assertFalse($this->db->inTransaction());
    }
    public function testFinalizadoPendienteYApoyoNoBloqueanRetiro(): void
    {
        foreach(['Finalizado','Pendiente','Cancelado'] as $state) {
            $this->db->exec("UPDATE recorrido SET estado='$state' WHERE id_recorrido=1");
            $this->db->beginTransaction();$g=new ContinuidadRecorridoRegular($this->db);
            $this->assertSame([],$g->before(2));$this->db->rollBack();
        }
        $this->db->exec("UPDATE recorrido SET estado='En Proceso';UPDATE vehiculo SET funcion_operativa='APOYO' WHERE id_vehiculo=1");
        $this->memberChange('finalizar_pertenencia');$this->assertSame(0,$this->membership());
    }
    public function testV19NoCierraNiCambiaMientrasDependeUnRegular(): void
    {
        $model=new AsignacionVehiculo($this->db);
        foreach(['cerrar','cambiar'] as $action) {
            $this->denied(fn()=>$model->mutate(1,$action,1,$action==='cambiar'?2:null,1,'Prueba'));
            $this->assertNull($this->db->query('SELECT fecha_fin FROM asignacion_vehiculo_operativa WHERE id_asignacion_vehiculo=1')->fetchColumn());
        }
    }
    public function testV19CierraConPendienteYTrasFinalizacion(): void
    {
        $this->db->exec("UPDATE recorrido SET estado='Pendiente'");
        (new AsignacionVehiculo($this->db))->mutate(1,'cerrar',1,null,1,'Prueba');
        $this->assertNotNull($this->db->query('SELECT fecha_fin FROM asignacion_vehiculo_operativa WHERE id_asignacion_vehiculo=1')->fetchColumn());
    }
    public function testV19AjenaAlRecorridoRegularPuedeCerrar(): void
    {
        (new AsignacionVehiculo($this->db))->mutate(1,'cerrar',2,null,2,'Sin dependencia');
        $this->assertNotNull($this->db->query('SELECT fecha_fin FROM asignacion_vehiculo_operativa WHERE id_asignacion_vehiculo=2')->fetchColumn());
        $this->assertSame('En Proceso',$this->db->query('SELECT estado FROM recorrido WHERE id_recorrido=1')->fetchColumn());
    }
    public function testFinalizarConPendientesNoCierraV19NiReescribeParticipa(): void
    {
        $before=$this->db->query('SELECT * FROM participa')->fetchAll(PDO::FETCH_ASSOC);
        $r=(new RecoleccionOperativa($this->db))->operar(2,1,'finalizar',null);
        $this->assertSame('Finalizado',$r['recorrido']['estado']);
        $this->assertSame(1,$r['recorrido']['progreso']['pendientes']);
        $this->assertSame(2,$r['recorrido']['id_usuario_fin']);$this->assertNotNull($r['recorrido']['fecha_fin']);
        $this->assertNull($this->db->query('SELECT fecha_fin FROM asignacion_vehiculo_operativa WHERE id_asignacion_vehiculo=1')->fetchColumn());
        $this->assertSame($before,$this->db->query('SELECT * FROM participa')->fetchAll(PDO::FETCH_ASSOC));
        (new AsignacionVehiculo($this->db))->mutate(1,'cerrar',1,null,1,'Fin de jornada');
        $this->assertNotNull($this->db->query('SELECT fecha_fin FROM asignacion_vehiculo_operativa WHERE id_asignacion_vehiculo=1')->fetchColumn());
        $this->memberChange('finalizar_pertenencia');$this->assertSame(0,$this->membership());
    }
    public function testF6ApoyoMantieneBloqueoDeV19PorSolicitud(): void
    {
        $this->db->exec("INSERT INTO solicitud VALUES(1,'En atención',2,1);INSERT INTO atencion_solicitud VALUES(1,1,2,2,'En atención',NULL)");
        $this->denied(fn()=>(new AsignacionVehiculo($this->db))->mutate(1,'cerrar',2,null,2,'Prueba'));
        $this->assertNull($this->db->query('SELECT fecha_fin FROM asignacion_vehiculo_operativa WHERE id_asignacion_vehiculo=2')->fetchColumn());
    }
}
