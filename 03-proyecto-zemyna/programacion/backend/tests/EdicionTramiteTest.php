<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../controllers/EdicionTramiteController.php';
require_once __DIR__.'/../controllers/IncidenciaController.php';
require_once __DIR__.'/../controllers/SolicitudRetiroController.php';
require_once __DIR__.'/../config/database.php';

class EdicionTramiteTest extends TestCase {
    public function testEstadosFinalesYDesconocidosNoSonEditables(): void {
        foreach (['Resuelta','Finalizada','Cancelada','Cerrada','Cerrado','Resuelto','Desconocido'] as $estado) {
            $this->assertFalse(EdicionTramite::abierto($estado,true));
            $this->assertFalse(EdicionTramite::abierto($estado,false));
        }
        $this->assertTrue(EdicionTramite::abierto('En Proceso',true));
        $this->assertTrue(EdicionTramite::abierto('Programada',false));
    }
    public function testNoBastaElTrackingYSeRechazanCamposProtegidosOVacios(): void {
        $model=new class extends EdicionTramite {
            public bool $autorizar=false;
            public int $escrituras=0;
            public function __construct() {}
            public function autorizado(string $tracking,string $token):bool{return $this->autorizar;}
            public function guardar(string $tracking,array $data):array{$this->escrituras++;return [];}
        };
        $controller=new EdicionTramiteController($model);
        $this->assertSame(403,$controller->ejecutar('PUT','RET-2026-ABCDE',[],'')['statusCode']);
        $model->autorizar=true;
        $valido=['descripcion'=>'Microondas','revision'=>0,'id_tipo_residuo'=>1];
        foreach (['estado'=>'Finalizada','fecha'=>'2030-01-01','created_at'=>'2030','tracking_number'=>'RET-2026-00000','consentimiento_whatsapp'=>true,'telefono'=>'099999999'] as $campo=>$valor) {
            $this->assertSame(400,$controller->ejecutar('PUT','RET-2026-ABCDE',$valido+[$campo=>$valor],'')['statusCode']);
        }
        foreach (['', '  ', str_repeat('a',221), []] as $texto) {
            $this->assertSame(400,$controller->ejecutar('PUT','RET-2026-ABCDE',array_replace($valido,['descripcion'=>$texto]),'')['statusCode']);
        }
        $this->assertSame(0,$model->escrituras);
    }
    public function testCreacionEdicionRelecturaYConcurrenciaRealesEnBaseAislada():void {
        if(getenv('ZEMYNA_EDICION_TEST')!=='1')$this->markTestSkipped('Integración MySQL opt-in: ZEMYNA_EDICION_TEST=1.');
        $db=(new Database())->getConnection();$this->assertInstanceOf(PDO::class,$db);
        $origen=$db->query('SELECT DATABASE()')->fetchColumn();
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_]+$/',$origen);
        $db->exec('CREATE DATABASE IF NOT EXISTS zemyna_edicion_test CHARACTER SET utf8mb4');
        foreach(['incidencia','solicitud','contenedor','tipo_residuo','tramite_edicion'] as $table)$db->exec("CREATE TABLE IF NOT EXISTS zemyna_edicion_test.`$table` LIKE `$origen`.`$table`");
        $db->exec('USE zemyna_edicion_test');$this->assertSame('zemyna_edicion_test',$db->query('SELECT DATABASE()')->fetchColumn());
        // No se borran tablas/datos. Solo se limpian nuestras filas al terminar.
        $tracks=[];$contenedor=null;$tipos=[];
        try{
            $db->exec("INSERT IGNORE INTO tipo_residuo SELECT * FROM `$origen`.tipo_residuo");
            $tipos=$db->query('SELECT id_tipo_residuo,nombre FROM tipo_residuo ORDER BY id_tipo_residuo LIMIT 2')->fetchAll(PDO::FETCH_ASSOC);
            $this->assertCount(2,$tipos);
            $db->exec("INSERT IGNORE INTO contenedor SELECT * FROM `$origen`.contenedor ORDER BY id_contenedor LIMIT 1");
            $contenedor=(int)$db->query('SELECT id_contenedor FROM contenedor LIMIT 1')->fetchColumn();
            $inc=(new IncidenciaController($db))->create(['id_contenedor'=>$contenedor,'tipo_problema'=>'Contenedor Desbordado','descripcion'=>'Texto anterior','celular'=>'099123456']);
            $ret=(new SolicitudRetiroController($db))->crear(['id_tipo_residuo'=>(int)$tipos[0]['id_tipo_residuo'],'descripcion'=>'Microondas','direccion'=>'Brasil 123','calle'=>'Brasil','numero_referencia'=>'123','latitud'=>-34.91,'longitud'=>-56.16,'celular'=>'099123456','consentimiento_whatsapp'=>true]);
            $this->assertTrue($inc['success'],json_encode($inc));$tracks[]=$inc['data']['tracking_number'];
            $this->assertTrue($ret['success'],json_encode($ret));$tracks[]=$ret['data']['tracking_number'];
            foreach($tracks as $tracking){
                $model=new EdicionTramite($db);$anterior=$model->emitirClave($tracking);$token=$model->emitirClave($tracking);$controller=new EdicionTramiteController($model);
                $this->assertFalse($model->autorizado($tracking,$anterior));$this->assertTrue($model->autorizado($tracking,$token));
                $before=$model->detalle($tracking);$isInc=str_starts_with($tracking,'INC-');$table=$isInc?'incidencia':'solicitud';
                $raw=$db->prepare("SELECT * FROM $table WHERE tracking_number=?");$raw->execute([$tracking]);$original=$raw->fetch(PDO::FETCH_ASSOC);
                $this->assertSame(403,$controller->ejecutar('GET',$tracking,[],str_repeat('0',64))['statusCode']);
                $data=['descripcion'=>$before['descripcion'].' corregido','revision'=>$before['revision']]+($isInc?['tipo_problema'=>'Contenedor Roto/Dañado']:['id_tipo_residuo'=>(int)$tipos[1]['id_tipo_residuo']]);
                $saved=$controller->ejecutar('PUT',$tracking,$data,$token);$this->assertSame(200,$saved['statusCode'],json_encode($saved));
                $fresh=(new EdicionTramite($db))->detalle($tracking);
                $this->assertSame($data['descripcion'],$fresh['descripcion']);$this->assertSame($before['fecha'],$fresh['fecha']);$this->assertSame($before['estado'],$fresh['estado']);$this->assertSame($tracking,$fresh['tracking_number']);$this->assertNotNull($fresh['updated_at']);$this->assertSame(1,$fresh['revision']);
                if(!$isInc)$this->assertSame($tipos[1]['nombre'],$fresh['tipo_residuo']);
                $raw->execute([$tracking]);$after=$raw->fetch(PDO::FETCH_ASSOC);
                foreach(['created_at','fecha','fecha_reporte','telefono','consentimiento_whatsapp','direccion','latitud','longitud'] as $field)if(array_key_exists($field,$original))$this->assertSame($original[$field],$after[$field],$field);
                $this->assertSame(409,$controller->ejecutar('PUT',$tracking,$data,$token)['statusCode']);
                $data['revision']=1;$data['descripcion']='Texto adicional';$this->assertSame(200,$controller->ejecutar('PUT',$tracking,$data,$token)['statusCode']);
                $this->assertSame('Texto adicional',(new EdicionTramite($db))->detalle($tracking)['descripcion']);
                $db->prepare("UPDATE $table SET estado=? WHERE tracking_number=?")->execute([$isInc?'Resuelta':'Finalizada',$tracking]);
                $data['revision']=2;$data['descripcion']='No debe guardarse';$this->assertSame(409,$controller->ejecutar('PUT',$tracking,$data,$token)['statusCode']);
                $this->assertSame('Texto adicional',$model->detalle($tracking)['descripcion']);
            }
        }finally{
            foreach($tracks as $tracking){$db->prepare('DELETE FROM tramite_edicion WHERE tracking_number=?')->execute([$tracking]);$table=str_starts_with($tracking,'INC-')?'incidencia':'solicitud';$db->prepare("DELETE FROM $table WHERE tracking_number=?")->execute([$tracking]);}
        }
    }
}
