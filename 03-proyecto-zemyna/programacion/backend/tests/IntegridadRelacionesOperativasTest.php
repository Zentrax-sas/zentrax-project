<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../controllers/UsaController.php';
require_once __DIR__.'/../controllers/ParticipaController.php';

final class IntegridadRelacionesOperativasTest extends TestCase
{
    private PDO $db;
    protected function setUp(): void {
        $this->db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE cuadrilla(id_cuadrilla INTEGER PRIMARY KEY);
          CREATE TABLE vehiculo(id_vehiculo INTEGER PRIMARY KEY,activo INTEGER,estado TEXT);
          CREATE TABLE usa(id_usa INTEGER PRIMARY KEY AUTOINCREMENT,id_cuadrilla INTEGER,id_vehiculo INTEGER);
          CREATE TABLE recorrido(id_recorrido INTEGER PRIMARY KEY,estado TEXT,fecha_fin TEXT,id_usuario_inicio INTEGER,id_usuario_fin INTEGER);
          CREATE TABLE participa(id_participa INTEGER PRIMARY KEY AUTOINCREMENT,id_usa INTEGER,id_recorrido INTEGER,hora_inicio TEXT,hora_fin TEXT,motivo_fin TEXT);
          CREATE TABLE atencion_contenedor(id_atencion_contenedor INTEGER PRIMARY KEY,id_recorrido INTEGER);
          CREATE TABLE asignacion_vehiculo_operativa(id_asignacion_vehiculo INTEGER PRIMARY KEY,id_cuadrilla INTEGER,id_vehiculo INTEGER,fecha_fin TEXT);
          CREATE TABLE solicitud(id_solicitud INTEGER PRIMARY KEY,estado TEXT,id_cuadrilla INTEGER,id_tipo_residuo INTEGER);
          CREATE TABLE atencion_solicitud(id_atencion_solicitud INTEGER PRIMARY KEY,id_solicitud INTEGER,id_asignacion_vehiculo INTEGER,estado TEXT,fecha_cierre TEXT,id_cuadrilla INTEGER);
          INSERT INTO cuadrilla VALUES(1),(2),(3);
          INSERT INTO vehiculo VALUES(1,1,'Disponible'),(2,1,'Disponible'),(3,1,'Disponible');
          INSERT INTO usa VALUES(1,1,1),(2,2,2);
          INSERT INTO recorrido VALUES(1,'Pendiente',NULL,NULL,NULL),(2,'Pendiente',NULL,NULL,NULL),(3,'Pendiente',NULL,NULL,NULL);");
        $this->db->exec("ALTER TABLE vehiculo ADD funcion_operativa TEXT DEFAULT 'REGULAR';
          ALTER TABLE asignacion_vehiculo_operativa ADD fecha_inicio TEXT DEFAULT '2020-01-01 00:00:00';
          INSERT INTO asignacion_vehiculo_operativa(id_asignacion_vehiculo,id_cuadrilla,id_vehiculo) VALUES(1,1,1),(2,2,2)");
    }
    private function body(int $use=1,int $trip=1,?int $id=null): array {
        $b=['id_usa'=>$use,'id_recorrido'=>$trip,'hora_inicio'=>'08:00:00'];
        if ($id!==null) $b['id_participa']=$id; return $b;
    }
    private function rowCount(string $table): int { return (int)$this->db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(); }
    public function testAutorizacionLibreDuplicadoCambioYBorrado(): void {
        $c=new UsaController($this->db);
        $r=$c->create(['id_cuadrilla'=>3,'id_vehiculo'=>1]); $this->assertSame(201,$r['statusCode']);
        $id=$r['data']['id_usa'];
        $this->assertSame(409,$c->create(['id_cuadrilla'=>3,'id_vehiculo'=>1])['statusCode']);
        $this->assertSame(409,$c->update(['id_usa'=>$id,'id_cuadrilla'=>1,'id_vehiculo'=>1])['statusCode']);
        $this->assertSame(200,$c->update(['id_usa'=>$id,'id_cuadrilla'=>3,'id_vehiculo'=>3])['statusCode']);
        $this->assertSame(200,$c->delete($id)['statusCode']); $this->assertSame(2,$this->rowCount('usa'));
    }
    public function testModeloNoDependeDePreconsultaDelControlador(): void {
        $m=new Usa($this->db); $m->id_cuadrilla=1;$m->id_vehiculo=1;
        try {$m->create();$this->fail('Duplicado permitido');}catch(DomainException $e){$this->assertSame(409,$e->getCode());}
        $this->assertFalse($this->db->inTransaction());$this->assertSame(2,$this->rowCount('usa'));
    }
    public function testReferenciasDeCualquierEstadoCongelanParejaEHistorial(): void {
        $c=new UsaController($this->db);$p=new ParticipaController($this->db);
        $this->assertSame(201,$p->create($this->body())['statusCode']);
        foreach(['Pendiente','En Proceso','Finalizado','Cancelado'] as $state){
            $s=$this->db->prepare('UPDATE recorrido SET estado=? WHERE id_recorrido=1');$s->execute([$state]);
            $this->assertSame(409,$c->update(['id_usa'=>1,'id_cuadrilla'=>3,'id_vehiculo'=>1])['statusCode']);
            $this->assertSame(409,$c->delete(1)['statusCode']);
            $this->assertSame(200,$c->update(['id_usa'=>1,'id_cuadrilla'=>1,'id_vehiculo'=>1])['statusCode']);
        }
    }
    public function testV19AbiertaProtegeParejaYEsObligatoriaParaParticipar(): void {
        $p=new ParticipaController($this->db);$this->assertSame(201,$p->create($this->body())['statusCode']);
        $this->db->exec('DELETE FROM participa');
        $c=new UsaController($this->db);
        $this->assertSame(409,$c->delete(1)['statusCode']);
        $this->assertSame(409,$c->update(['id_usa'=>1,'id_cuadrilla'=>3,'id_vehiculo'=>1])['statusCode']);
        $this->assertSame(200,$c->update(['id_usa'=>1,'id_cuadrilla'=>1,'id_vehiculo'=>1])['statusCode']);
    }
    public function testParticipacionValidaDuplicadosYExclusividad(): void {
        $c=new ParticipaController($this->db);
        $this->assertSame(201,$c->create($this->body())['statusCode']);
        $this->assertSame(409,$c->create($this->body())['statusCode']);
        $this->assertSame(409,$c->create($this->body(2,1))['statusCode']);
        $this->assertSame(409,$c->create($this->body(1,2))['statusCode']);
        $this->assertSame(201,$c->create($this->body(2,2))['statusCode']);
        $this->assertSame(2,$this->rowCount('participa'));
    }
    public function testUpdateValidaOrigenYDestinoYDeletePendienteLibre(): void {
        $c=new ParticipaController($this->db);$id=$c->create($this->body())['data']['id_participa'];
        $this->assertSame(200,$c->update($this->body(2,2,$id))['statusCode']);
        $this->db->exec("UPDATE recorrido SET estado='Finalizado' WHERE id_recorrido=3");
        $this->assertSame(409,$c->update($this->body(2,3,$id))['statusCode']);
        $this->assertSame(2,(int)$this->db->query('SELECT id_recorrido FROM participa')->fetchColumn());
        $this->assertSame(200,$c->delete($id)['statusCode']);
    }
    public function testEstadosIniciadosOCerradosYActividadProtegidos(): void {
        $c=new ParticipaController($this->db);$id=$c->create($this->body())['data']['id_participa'];
        foreach(['En Proceso','Finalizado','Cancelado'] as $state){
            $s=$this->db->prepare('UPDATE recorrido SET estado=? WHERE id_recorrido=1');$s->execute([$state]);
            $this->assertSame(409,$c->create($this->body())['statusCode']);
            $this->assertSame(409,$c->update($this->body(2,2,$id))['statusCode']);
            $this->assertSame(409,$c->delete($id)['statusCode']);
        }
        $this->db->exec("UPDATE recorrido SET estado='Pendiente' WHERE id_recorrido=1;INSERT INTO atencion_contenedor VALUES(1,1)");
        $this->assertSame(409,$c->delete($id)['statusCode']);$this->assertSame(1,$this->rowCount('participa'));
    }
    public function testRollbackAnteErrorDePersistenciaNoExponeSQL(): void {
        $this->db->exec("CREATE TRIGGER fallo BEFORE INSERT ON participa BEGIN SELECT RAISE(ABORT,'SQL secreto'); END;");
        $r=(new ParticipaController($this->db))->create($this->body());
        $this->assertSame(500,$r['statusCode']);$this->assertStringNotContainsString('secreto',json_encode($r));
        $this->assertFalse($this->db->inTransaction());$this->assertSame(0,$this->rowCount('participa'));
    }
    public function testF6MantieneProteccionDeSolicitudEnAtencion(): void {
        $this->db->exec("INSERT INTO solicitud VALUES(1,'En atención',1,3);
          INSERT INTO atencion_solicitud VALUES(1,1,1,'En atención',NULL,1)");
        $c=new UsaController($this->db);
        $this->assertSame(409,$c->create(['id_cuadrilla'=>1,'id_vehiculo'=>1])['statusCode']);
        $this->assertSame(409,$c->delete(1)['statusCode']);
        $this->assertSame(409,$c->update(['id_usa'=>1,'id_cuadrilla'=>2,'id_vehiculo'=>1])['statusCode']);
        $this->assertSame(200,$c->update(['id_usa'=>1,'id_cuadrilla'=>1,'id_vehiculo'=>1])['statusCode']);
    }
    public function testNoConexionYRecursosAusentes(): void {
        $this->assertSame(503,(new UsaController(null))->create(['id_cuadrilla'=>1,'id_vehiculo'=>1])['statusCode']);
        $this->assertSame(503,(new ParticipaController(null))->create($this->body())['statusCode']);
        $this->assertSame(404,(new ParticipaController($this->db))->delete(999)['statusCode']);
        $this->assertSame(404,(new UsaController($this->db))->delete(999)['statusCode']);
    }
    public function testTraduccion403Y503ConDobles(): void {
        foreach([[UsaController::class,Usa::class,['id_cuadrilla'=>3,'id_vehiculo'=>3],'usa'],[ParticipaController::class,Participa::class,$this->body(),'participa']] as [$controller,$model,$body,$property]){
            $double=$this->getMockBuilder($model)->disableOriginalConstructor()->onlyMethods(['create'])->getMock();
            $double->method('create')->willThrowException(new DomainException('Sin autorización.',403));
            $c=new $controller($this->db);$r=new ReflectionProperty($controller,$property);$r->setValue($c,$double);
            $this->assertSame(403,$c->create($body)['statusCode']);
            foreach ([1213=>409,1205=>409,2006=>503] as $driverCode=>$status) {
                $error=new PDOException('SQL interno que no debe salir');
                $error->errorInfo=['HY000',$driverCode,'detalle interno'];
                $double=$this->getMockBuilder($model)->disableOriginalConstructor()->onlyMethods(['create'])->getMock();
                $double->method('create')->willThrowException($error);
                $r->setValue($c,$double); $response=$c->create($body);
                $this->assertSame($status,$response['statusCode']);
                $this->assertStringNotContainsString('interno',json_encode($response));
            }
        }
    }
    public function testParticipacionCerradaNoPuedeReinterpretarseComoPendiente(): void {
        $c=new ParticipaController($this->db);$id=$c->create($this->body())['data']['id_participa'];
        $this->db->exec("UPDATE participa SET hora_fin='10:00:00' WHERE id_participa=$id");
        $this->assertSame(409,$c->update($this->body(2,2,$id))['statusCode']);
        $this->assertSame(409,$c->delete($id)['statusCode']);
        $this->assertSame('10:00:00',$this->db->query('SELECT hora_fin FROM participa')->fetchColumn());
    }
    public function testUpdateDestinoOcupadoYVehiculoInvalidoConservanOrigen(): void {
        $c=new ParticipaController($this->db);$id=$c->create($this->body())['data']['id_participa'];
        $this->assertSame(201,$c->create($this->body(2,2))['statusCode']);
        $this->assertSame(409,$c->update($this->body(2,2,$id))['statusCode']);
        $this->db->exec('UPDATE vehiculo SET activo=0 WHERE id_vehiculo=1');
        $this->assertSame(409,$c->update($this->body(1,3,$id))['statusCode']);
        $this->assertSame(1,(int)$this->db->query("SELECT id_recorrido FROM participa WHERE id_participa=$id")->fetchColumn());
        $this->assertFalse($this->db->inTransaction());
    }
    public function testFalloDeEscrituraUsaHaceRollback(): void {
        $this->db->exec("CREATE TRIGGER fallo BEFORE INSERT ON usa BEGIN SELECT RAISE(ABORT,'detalle privado'); END;");
        $r=(new UsaController($this->db))->create(['id_cuadrilla'=>3,'id_vehiculo'=>3]);
        $this->assertSame(500,$r['statusCode']);
        $this->assertStringNotContainsString('privado',json_encode($r));
        $this->assertFalse($this->db->inTransaction());$this->assertSame(2,$this->rowCount('usa'));
    }
    public function testExclusividadSeparadaDeVehiculoYCuadrilla(): void {
        $c=new ParticipaController($this->db);
        $this->assertSame(201,$c->create($this->body())['statusCode']);
        $this->db->exec('INSERT INTO usa VALUES(3,3,1),(4,1,3)');
        $this->assertSame(409,$c->create($this->body(3,2))['statusCode']);
        $this->assertSame(409,$c->create($this->body(4,3))['statusCode']);
        $this->assertSame(1,$this->rowCount('participa'));
    }
    public function testContrato400404YCierrePorFlujoOperativo(): void {
        $c=new ParticipaController($this->db);
        $this->assertSame(400,$c->create([])['statusCode']);
        $this->assertSame(404,$c->create($this->body(999))['statusCode']);
        $this->assertSame(404,$c->create($this->body(1,999))['statusCode']);
        $this->assertSame(409,$c->create($this->body()+['hora_fin'=>'10:00:00'])['statusCode']);
        $this->assertSame(0,$this->rowCount('participa'));
    }
}
