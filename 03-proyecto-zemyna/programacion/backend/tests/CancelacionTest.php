<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../controllers/CancelacionController.php';
require_once __DIR__.'/../controllers/IncidenciaController.php';
require_once __DIR__.'/../controllers/SolicitudRetiroController.php';
require_once __DIR__.'/../config/database.php';
class CancelacionTest extends TestCase {
    public function testSoloPendienteSinAsignacionPermiteCancelar():void{
        $this->assertTrue(ReglaCancelacion::permitida(['estado'=>'Pendiente']));
        foreach(['En Proceso','En camino','Aceptada','Programada','Resuelta','Finalizada','Cancelada','Desconocido'] as $estado){
            $this->assertFalse(ReglaCancelacion::permitida(['estado'=>$estado]));
        }
        foreach(['id_camion','id_vehiculo','id_cuadrilla','asignada'] as $campo)$this->assertFalse(ReglaCancelacion::permitida(['estado'=>'Pendiente',$campo=>1]));
        $this->assertTrue(ReglaCancelacion::permitida(['estado'=>'Pendiente','id_ruta'=>1]));
        foreach(['INC','RET'] as $tipo){
            $mensaje=ReglaCancelacion::mensaje($tipo.'-2026-ABCDE',['estado'=>'En camino']);
            $this->assertStringContainsString('0800 0800',$mensaje);$this->assertStringNotContainsString('trámite',$mensaje);
        }
    }
    public function testExigeAutorizacionYNoAceptaCambiarDatosProtegidos():void{
        $modelo=new class extends Cancelacion{public int $calls=0;public function __construct(){}public function cancelar(string $tracking,int $revision):array{$this->calls++;return ['success'=>true];}};
        $auth=new class extends EdicionTramite{public bool $permitir=false;public function __construct(){}public function autorizado(string $tracking,string $token):bool{return $this->permitir;}};
        $controller=new CancelacionController($modelo,$auth);
        $this->assertSame(403,$controller->cancelar('INC-2026-ABCDE',['revision'=>0],'')['statusCode']);$auth->permitir=true;
        foreach([[],['revision'=>-1],['revision'=>'0'],['revision'=>0,'estado'=>'Cancelada'],['revision'=>0,'tracking_number'=>'INC-2026-FFFFF']] as $body)$this->assertSame(400,$controller->cancelar('INC-2026-ABCDE',$body,'')['statusCode']);
        $this->assertSame(400,$controller->cancelar('REF-2026-ABCDE',['revision'=>0],'')['statusCode']);$this->assertSame(0,$modelo->calls);
    }
    public function testPersistenciaTransicionUnicaAsignacionYConflictosEnMysqlAislado():void{
        if(getenv('ZEMYNA_CANCELACION_TEST')!=='1')$this->markTestSkipped('Integración MySQL opt-in: ZEMYNA_CANCELACION_TEST=1.');
        $db=(new Database())->getConnection();$this->assertInstanceOf(PDO::class,$db);$origen=$db->query('SELECT DATABASE()')->fetchColumn();$this->assertMatchesRegularExpression('/^[a-zA-Z0-9_]+$/',$origen);
        $db->exec('CREATE DATABASE IF NOT EXISTS zemyna_cancelacion_test CHARACTER SET utf8mb4');
        foreach(['incidencia','solicitud','contenedor','tipo_residuo','tramite_edicion'] as $tabla)$db->exec("CREATE TABLE IF NOT EXISTS zemyna_cancelacion_test.`$tabla` LIKE `$origen`.`$tabla`");
        $db->exec('USE zemyna_cancelacion_test');$this->assertSame('zemyna_cancelacion_test',$db->query('SELECT DATABASE()')->fetchColumn());
        $db->beginTransaction();
        try{
            $db->exec("INSERT IGNORE INTO tipo_residuo SELECT * FROM `$origen`.tipo_residuo ORDER BY id_tipo_residuo LIMIT 1");
            $db->exec("INSERT IGNORE INTO contenedor SELECT * FROM `$origen`.contenedor ORDER BY id_contenedor LIMIT 1");
            $tipo=(int)$db->query('SELECT id_tipo_residuo FROM tipo_residuo LIMIT 1')->fetchColumn();$contenedor=(int)$db->query('SELECT id_contenedor FROM contenedor LIMIT 1')->fetchColumn();
            // Preparar filas independientes; Cancelacion usa sus propias transacciones.
            $fixtures=[];
            foreach(['INC','RET'] as $prefijo)foreach(['Pendiente','asignada','final','conflicto'] as $caso){
                $response=$prefijo==='INC'?(new IncidenciaController($db))->create(['descripcion'=>'Validación cancelación','tipo_problema'=>'Contenedor Desbordado','id_contenedor'=>$contenedor]):(new SolicitudRetiroController($db))->crear(['descripcion'=>'Validación cancelación','id_tipo_residuo'=>$tipo,'direccion'=>'Brasil 123','calle'=>'Brasil','numero_referencia'=>'123','latitud'=>-34.91,'longitud'=>-56.16]);
                $this->assertTrue($response['success'],json_encode($response));$track=$response['data']['tracking_number'];$tabla=$prefijo==='INC'?'incidencia':'solicitud';
                $token=(new EdicionTramite($db))->emitirClave($track);
                if($caso==='asignada')$db->prepare($prefijo==='INC'?"UPDATE incidencia SET id_cuadrilla=55 WHERE tracking_number=?":"UPDATE solicitud SET estado='Programada' WHERE tracking_number=?")->execute([$track]);
                if($caso==='final')$db->prepare("UPDATE $tabla SET estado=? WHERE tracking_number=?")->execute([$prefijo==='INC'?'Resuelta':'Finalizada',$track]);
                if($caso==='conflicto')$db->prepare("UPDATE $tabla SET estado=? WHERE tracking_number=?")->execute([$prefijo==='INC'?'En Proceso':'Programada',$track]);
                $fixtures[]=[$track,$tabla,$token,$caso];
            }
            $db->commit();
            try{
                foreach($fixtures as [$track,$tabla,$token,$caso]){
                    $controller=new CancelacionController(new Cancelacion($db),new EdicionTramite($db));
                    $read=$db->prepare("SELECT * FROM $tabla WHERE tracking_number=?");$read->execute([$track]);$before=$read->fetch(PDO::FETCH_ASSOC);
                    $result=$controller->cancelar($track,['revision'=>0],$token);
                    $this->assertSame($caso==='Pendiente'?200:409,$result['statusCode'],json_encode($result));
                    $read->execute([$track]);$after=$read->fetch(PDO::FETCH_ASSOC);
                    $this->assertSame($track,$result['data']['tracking_number']);
                    foreach($before as $campo=>$valor)if(!in_array($campo,['estado','fecha_cancelacion','updated_at','revision'],true))$this->assertSame($valor,$after[$campo],$campo);
                    if($caso==='Pendiente'){
                        $this->assertSame('Cancelada',$after['estado']);$this->assertNotNull($after['fecha_cancelacion']);$this->assertFalse($result['data']['cancelable']);
                        $segunda=$controller->cancelar($track,['revision'=>1],$token);$this->assertSame(409,$segunda['statusCode']);$read->execute([$track]);$this->assertSame($after,$read->fetch(PDO::FETCH_ASSOC));
                    }else{
                        $this->assertSame($before,$after);$this->assertSame($before['estado'],$result['data']['estado']);
                        if($caso!=='final')$this->assertStringContainsString('0800 0800',$result['message']);
                    }
                }
            }finally{
                foreach($fixtures as [$track,$tabla]){$db->prepare('DELETE FROM tramite_edicion WHERE tracking_number=?')->execute([$track]);$db->prepare("DELETE FROM $tabla WHERE tracking_number=?")->execute([$track]);}
            }
        }finally{if($db->inTransaction())$db->rollBack();}
    }
}
