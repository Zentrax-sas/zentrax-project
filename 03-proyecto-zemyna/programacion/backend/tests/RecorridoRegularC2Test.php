<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../controllers/RecorridoController.php';
require_once __DIR__.'/../controllers/ParticipaController.php';

/** C2: solo SQLite en memoria; nunca carga configuración ni la BD habitual. */
final class RecorridoRegularC2Test extends TestCase
{
    private PDO $db;
    protected function setUp(): void
    {
        $this->db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE cuadrilla(id_cuadrilla INTEGER PRIMARY KEY);
            CREATE TABLE vehiculo(id_vehiculo INTEGER PRIMARY KEY,activo INTEGER,estado TEXT,funcion_operativa TEXT);
            CREATE TABLE usa(id_usa INTEGER PRIMARY KEY,id_cuadrilla INTEGER,id_vehiculo INTEGER);
            CREATE TABLE recorrido(id_recorrido INTEGER PRIMARY KEY AUTOINCREMENT,fecha_inicio TEXT,fecha_fin TEXT,estado TEXT,id_ruta INTEGER,id_usuario_inicio INTEGER,id_usuario_fin INTEGER);
            CREATE TABLE participa(id_participa INTEGER PRIMARY KEY AUTOINCREMENT,id_usa INTEGER,id_recorrido INTEGER,hora_inicio TEXT,hora_fin TEXT,motivo_fin TEXT);
            CREATE TABLE atencion_contenedor(id_atencion_contenedor INTEGER PRIMARY KEY,id_recorrido INTEGER);
            CREATE TABLE asignacion_vehiculo_operativa(id_asignacion_vehiculo INTEGER PRIMARY KEY,id_cuadrilla INTEGER,id_vehiculo INTEGER,fecha_inicio TEXT,fecha_fin TEXT);
            INSERT INTO cuadrilla VALUES(1),(2);
            INSERT INTO vehiculo VALUES(1,1,'Disponible','REGULAR'),(2,1,'Disponible','APOYO');
            INSERT INTO usa VALUES(1,1,1),(2,2,2);
            INSERT INTO recorrido VALUES(1,'2020-01-01 08:00:00',NULL,'Pendiente',1,NULL,NULL),(2,'2020-01-01 08:00:00',NULL,'Pendiente',1,NULL,NULL);
            INSERT INTO asignacion_vehiculo_operativa VALUES(1,1,1,'2020-01-01 00:00:00',NULL),(2,2,2,'2020-01-01 00:00:00',NULL)");
    }
    private function participation(int $use=1,int $trip=1): array
    {
        return ['id_usa'=>$use,'id_recorrido'=>$trip,'hora_inicio'=>'08:00:00'];
    }
    private function trip(string $state='En Proceso'): array
    {
        return ['id_recorrido'=>1,'fecha_inicio'=>'2020-01-01 08:00:00','estado'=>$state,'id_ruta'=>1];
    }
    public static function invalidContexts(): array
    {
        return [
            'sin utilización'=>['DELETE FROM asignacion_vehiculo_operativa'],
            'cerrada'=>["UPDATE asignacion_vehiculo_operativa SET fecha_fin='2021-01-01' WHERE id_asignacion_vehiculo=1"],
            'incoherente'=>['UPDATE asignacion_vehiculo_operativa SET id_cuadrilla=2 WHERE id_asignacion_vehiculo=1'],
            'ambigua'=>["INSERT INTO asignacion_vehiculo_operativa VALUES(3,1,1,'2020-01-01',NULL)"],
            'APOYO'=>["UPDATE vehiculo SET funcion_operativa='APOYO' WHERE id_vehiculo=1"],
            'sin clasificación'=>['UPDATE vehiculo SET funcion_operativa=NULL WHERE id_vehiculo=1'],
        ];
    }
    /** @dataProvider invalidContexts */
    public function testCrudParticipaNoEludeC2(string $sql): void
    {
        $this->db->exec($sql);
        $r=(new ParticipaController($this->db))->create($this->participation());
        $this->assertSame(409,$r['statusCode']);
        $this->assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM participa')->fetchColumn());
        $this->assertFalse($this->db->inTransaction());
    }
    /** @dataProvider invalidContexts */
    public function testCrudRecorridoNoEludeRevalidacionAlIniciar(string $sql): void
    {
        $this->db->exec("INSERT INTO participa(id_usa,id_recorrido,hora_inicio) VALUES(1,1,'08:00:00')");
        $this->db->exec($sql);
        $r=(new RecorridoController($this->db))->update($this->trip());
        $this->assertSame(409,$r['statusCode']);
        $this->assertSame('Pendiente',$this->db->query('SELECT estado FROM recorrido WHERE id_recorrido=1')->fetchColumn());
        $this->assertFalse($this->db->inTransaction());
    }
    public function testCrearPendienteSinAsignacionContinuaPermitido(): void
    {
        $this->db->exec('DELETE FROM asignacion_vehiculo_operativa');
        $c=new RecorridoController($this->db);
        $this->assertSame(201,$c->create($this->trip('Pendiente'))['statusCode']);
        $this->assertSame(409,$c->create($this->trip())['statusCode']);
        $this->assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM participa')->fetchColumn());
    }
    public function testInicioLegacySinAsignacionEsRechazadoYConV19EsPermitido(): void
    {
        $c=new RecorridoController($this->db);
        $this->assertSame(409,$c->update($this->trip())['statusCode']);
        $p=new ParticipaController($this->db);
        $this->assertSame(201,$p->create($this->participation())['statusCode']);
        $this->assertSame(200,$c->update($this->trip())['statusCode']);
        $this->assertSame('En Proceso',$this->db->query('SELECT estado FROM recorrido WHERE id_recorrido=1')->fetchColumn());
    }
    public function testUpdateParticipaRevalidaDestinoYConservaOrigenAnteRechazo(): void
    {
        $p=new ParticipaController($this->db);
        $id=$p->create($this->participation())['data']['id_participa'];
        $r=$p->update($this->participation(2,2)+['id_participa'=>$id]);
        $this->assertSame(409,$r['statusCode']);
        $this->assertSame(['id_usa'=>1,'id_recorrido'=>1],$this->db->query('SELECT id_usa,id_recorrido FROM participa')->fetch(PDO::FETCH_ASSOC));
        $this->assertFalse($this->db->inTransaction());
        $this->db->exec("UPDATE vehiculo SET funcion_operativa='REGULAR' WHERE id_vehiculo=2");
        $this->assertSame(200,$p->update($this->participation(2,2)+['id_participa'=>$id])['statusCode']);
    }
}
