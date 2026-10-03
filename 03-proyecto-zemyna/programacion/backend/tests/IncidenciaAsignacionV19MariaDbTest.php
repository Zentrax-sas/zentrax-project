<?php
require_once __DIR__.'/IncidenciaAsignacionV19Test.php';
require_once __DIR__.'/../config/database.php';

/** Hereda el contrato y añade carreras reales. Nunca admite una BD externa. */
final class IncidenciaAsignacionV19MariaDbTest extends IncidenciaAsignacionV19Test
{
    private static ?PDO $shared=null;
    private static ?string $name=null;
    private static bool $installed=false;
    protected function connect(): PDO {
        if(getenv('ZEMYNA_F3_V19_MARIADB')!=='1')$this->markTestSkipped('MariaDB F3/V19 aislada no habilitada.');
        if(self::$shared)return self::$shared;
        $db=$this->other(false);self::$name='zemyna_f3_v19_test_'.bin2hex(random_bytes(6));
        $db->exec('CREATE DATABASE '.self::$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$db->exec('USE '.self::$name);
        fwrite(STDERR,'F3/V19 isolated database: '.self::$name."\n");self::$shared=$db;return $db;
    }
    private function other(bool $select=true): PDO {
        $db=new PDO('mysql:host='.(getenv('DB_HOST')?:'localhost').($select?';dbname='.self::$name:'').';charset=utf8mb4',getenv('DB_USER')?:'root',getenv('DB_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);return $db;
    }
    protected function schema(): void {
        if($this->db->query('SELECT DATABASE()')->fetchColumn()!==self::$name || !preg_match('/^zemyna_f3_v19_test_[a-f0-9]{12}$/D',self::$name))throw new RuntimeException('Base aislada incorrecta.');
        if(!self::$installed){$this->db->exec(file_get_contents(__DIR__.'/../../base-datos/database/sql/schema.sql'));self::$installed=true;return;}
        $this->db->exec('DROP TRIGGER IF EXISTS fallo_f3_v19');$this->db->exec('SET FOREIGN_KEY_CHECKS=0');
        try{foreach($this->db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)as$t){if(!preg_match('/^[a-z_]+$/D',$t))throw new RuntimeException('Tabla inesperada');$this->db->exec('DELETE FROM `'.$t.'`');}}
        finally{$this->db->exec('SET FOREIGN_KEY_CHECKS=1');}
    }
    public static function tearDownAfterClass(): void {
        if(self::$shared && self::$name && preg_match('/^zemyna_f3_v19_test_[a-f0-9]{12}$/D',self::$name))self::$shared->exec('DROP DATABASE '.self::$name);
        self::$shared=null;self::$name=null;self::$installed=false;
    }
    private function start(array $request): array {
        $p=proc_open([PHP_BINARY,__DIR__.'/fixtures/f3_v19_worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $this->assertIsResource($p);fwrite($pipes[0],json_encode(['database'=>self::$name]+$request));fclose($pipes[0]);stream_set_timeout($pipes[1],20);$this->assertSame("READY\n",fgets($pipes[1]));return[$p,$pipes];
    }
    private function finish(array $worker): array {
        [$p,$pipes]=$worker;$body=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$this->assertSame(0,proc_close($p),$error);return json_decode($body,true,512,JSON_THROW_ON_ERROR);
    }
    private function waitLocks(int $count): void {
        $deadline=microtime(true)+8;do{$n=(int)$this->db->query("SELECT COUNT(*) FROM information_schema.INNODB_TRX t JOIN information_schema.PROCESSLIST p ON p.ID=t.trx_mysql_thread_id WHERE t.trx_state='LOCK WAIT' AND p.DB=".$this->db->quote(self::$name))->fetchColumn();if($n===$count)break;usleep(20000);}while(microtime(true)<$deadline);$this->assertSame($count,$n,'Conexiones independientes deben esperar realmente.');
    }
    private function afterOtherWrite(callable $write): void {
        $before=$this->state();$this->db->beginTransaction();$this->db->query('SELECT id_incidencia FROM incidencia WHERE id_incidencia=1 FOR UPDATE')->fetchAll();$worker=null;
        try{$worker=$this->start(['body'=>$this->payload()]);$this->waitLocks(1);$write($this->other());$this->db->commit();$result=$this->finish($worker);$worker=null;$this->assertSame(409,$result['statusCode']);$this->assertSame($before,$this->state());}
        finally{if($this->db->inTransaction())$this->db->rollBack();if($worker){proc_terminate($worker[0]);foreach($worker[1]as$p)if(is_resource($p))fclose($p);proc_close($worker[0]);}}
    }
    public function testF3EsperaIncidenciaYRevalidaCierreV19(): void {
        $this->afterOtherWrite(fn($db)=>(new AsignacionVehiculo($db))->mutate(1,'cerrar',1,null,1,'Test cierre'));
    }
    public function testF3EsperaIncidenciaYRevalidaCambioV19(): void {
        $this->afterOtherWrite(function($db){$v=new AsignacionVehiculo($db);$v->mutate(1,'cerrar',2,null,2,'Test');$db->exec("UPDATE recorrido SET estado='Finalizado' WHERE id_recorrido=1");$v->mutate(1,'cambiar',1,2,1,'Test cambio');});
    }
    public function testF3EsperaIncidenciaYRevalidaRecorrido(): void {
        $this->afterOtherWrite(fn($db)=>$db->exec("UPDATE recorrido SET estado='Finalizado' WHERE id_recorrido=1"));
    }
    public function testDosOperadoresSoloUnoConfirma(): void {
        $this->db->beginTransaction();$this->db->query('SELECT id_incidencia FROM incidencia WHERE id_incidencia=1 FOR UPDATE')->fetchAll();$workers=[];
        try{$workers[]=$this->start(['body'=>$this->payload(),'actor'=>1]);$workers[]=$this->start(['body'=>$this->payload(true),'actor'=>3]);$this->waitLocks(2);$this->db->commit();$results=array_map(fn($w)=>$this->finish($w),$workers);$workers=[];$codes=array_column($results,'statusCode');sort($codes);$this->assertSame([200,409],$codes);$this->assertCount(1,$this->state()[1]);}
        finally{if($this->db->inTransaction())$this->db->rollBack();foreach($workers as$w){proc_terminate($w[0]);foreach($w[1]as$p)if(is_resource($p))fclose($p);proc_close($w[0]);}}
    }
    /** @dataProvider vehicleChanges */
    public function testF3ContraPoliticaCrudVehiculo(array $change): void {
        $this->db->beginTransaction();$this->db->query('SELECT id_vehiculo FROM vehiculo WHERE id_vehiculo=1 FOR UPDATE')->fetchAll();$workers=[];
        $vehicle=['id_vehiculo'=>1,'matricula'=>'TEST1','marca'=>'Test','modelo'=>'Test','capacidad_carga'=>1,'id_tipo_residuo'=>1,'estado'=>'Disponible','funcion_operativa'=>'REGULAR'];
        try{$workers[]=$this->start(['body'=>$this->payload()]);$workers[]=$this->start(['vehicle'=>array_replace($vehicle,$change)]);$this->waitLocks(2);$this->db->commit();$f3=$this->finish($workers[0]);$crud=$this->finish($workers[1]);$workers=[];$this->assertContains($f3['statusCode'],[200,409]);$this->assertSame(409,$crud['statusCode']);$this->assertSame('Disponible',$this->db->query('SELECT estado FROM vehiculo WHERE id_vehiculo=1')->fetchColumn());}
        finally{if($this->db->inTransaction())$this->db->rollBack();foreach($workers as$w){proc_terminate($w[0]);foreach($w[1]as$p)if(is_resource($p))fclose($p);proc_close($w[0]);}}
    }
    public static function vehicleChanges(): array { return ['mantenimiento'=>[['estado'=>'En Mantenimiento']],'baja'=>[['__delete'=>true]],'quitar funcion'=>[['funcion_operativa'=>null]]]; }
    public function testRollbackRealRevierteAtencion(): void {
        $this->db->exec("CREATE TRIGGER fallo_f3_v19 BEFORE UPDATE ON incidencia FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fallo controlado'");$before=$this->state();$this->assertSame(500,$this->request($this->payload(true))['statusCode']);$this->assertSame($before,$this->state());$this->assertFalse($this->db->inTransaction());
    }
}
