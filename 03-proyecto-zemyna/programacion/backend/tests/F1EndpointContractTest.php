<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/fixtures/F1HttpSandbox.php';
final class F1EndpointContractTest extends TestCase {
    public function testFotosPostAndPutReturn405WithoutDatabase(): void {
        // Este nombre no se crea: cualquier conexión fallaría. Ninguna referencia a BD habitual.
        $http=new F1HttpSandbox('zemyna_f1_test_'.bin2hex(random_bytes(6)));
        try{foreach(['POST','PUT']as$method){
            $result=$http->request($method,'/api/fotos.php','{"id_foto":1,"id_incidencia":2,"url":"test.png"}',['Content-Type: application/json']);
            $this->assertSame(405,$result['status']);$this->assertFalse(json_decode($result['body'],true)['success']);
        }}finally{$http->close();}
    }
    public function testAlternateControllerHasNoAssociationMethods(): void {
        require_once __DIR__.'/../controllers/FotoController.php';
        $this->assertFalse(method_exists(FotoController::class,'create'));
        $this->assertFalse(method_exists(FotoController::class,'update'));
        $this->assertTrue(method_exists(FotoController::class,'getAll'));
        $this->assertTrue(method_exists(FotoController::class,'delete'));
    }
}
