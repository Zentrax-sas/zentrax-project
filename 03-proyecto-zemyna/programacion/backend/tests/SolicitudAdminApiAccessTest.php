<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../helpers/solicitud_admin_http.php';
final class SolicitudAdminApiAccessTest extends TestCase {
    /** @dataProvider origins */
    public function testCsrf(array $server,bool $expected): void {
        $this->assertSame($expected,solicitudAdminCsrf($server,['solicitud_admin_csrf'=>'secret']));
    }
    public static function origins(): array {
        $s=['HTTP_X_CSRF_TOKEN'=>'secret','HTTP_HOST'=>'localhost:8080'];
        return [[[],false],[$s,true],[$s+['HTTP_ORIGIN'=>'http://localhost:8080'],true],[$s+['HTTP_ORIGIN'=>'http://evil.invalid'],false],[$s+['HTTP_ORIGIN'=>'null'],false],[$s+['HTTP_ORIGIN'=>'https://localhost:8080'],false],[$s+['HTTP_ORIGIN'=>'http://localhost:8080/path'],false],[$s+['HTTP_SEC_FETCH_SITE'=>'cross-site'],false],[array_replace($s,['HTTP_X_CSRF_TOKEN'=>'wrong']),false]];
    }
    /** Ejecuta el endpoint real con controlador doble; nunca conecta a la BD habitual. */
    public function testHttpRoutingAndSession(): void {
        foreach([['GET',false,401,null],['DELETE',true,405,null],['PUT',true,403,null],['GET',true,200,null],['PUT',true,200,'{"accion":"asignar"}'],['PUT',true,400,'[]'],['PUT',true,400,'{']] as [$method,$session,$expected,$input]) {
            $file=tempnam(sys_get_temp_dir(),'f62_http_');
            $code='<?php session_save_path(sys_get_temp_dir());session_start();putenv("DB_HOST=127.0.0.1;port=1");class SolicitudAdminController {public function __construct($db){} public function get($q){return ["success"=>true,"statusCode"=>200,"data"=>[]];}public function put($b){return ["success"=>true,"statusCode"=>200,"data"=>$b];}} ob_start();register_shutdown_function(function(){$body=ob_get_clean();echo json_encode(["status"=>http_response_code(),"body"=>json_decode($body,true)]);session_destroy();});';
            if($input!==null) {
                $code.='class F62Input {public $context;public static string $body;private int $offset=0;public function stream_open($p,$m,$o,&$opened):bool{return $p==="php://input";}public function stream_read($n):string{$s=substr(self::$body,$this->offset,$n);$this->offset+=strlen($s);return $s;}public function stream_eof():bool{return $this->offset>=strlen(self::$body);}public function stream_stat():array{return [];}}stream_wrapper_unregister("php");stream_wrapper_register("php",F62Input::class);F62Input::$body='.var_export($input,true).';$_SERVER["HTTP_X_CSRF_TOKEN"]="secret";';
            }
            $sessionData=$session?['usuario'=>['id_usuario'=>1],'solicitud_admin_csrf'=>'secret']:[];
            $code.='$_SERVER["REQUEST_METHOD"]='.var_export($method,true).';$_SESSION='.var_export($sessionData,true).';$_GET=[];require '.var_export(realpath(__DIR__.'/../api/solicitudes_admin.php'),true).';';file_put_contents($file,$code);
            try {$p=proc_open([PHP_BINARY,$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$this->assertSame(0,proc_close($p),$err);$r=json_decode($out,true,512,JSON_THROW_ON_ERROR);$this->assertSame($expected,$r['status']);if($expected===200 && $method==='GET')$this->assertSame('secret',$r['body']['csrf_token']);if($expected===200 && $method==='PUT')$this->assertSame('asignar',$r['body']['data']['accion']);}
            finally {unlink($file);}
        }
    }
}
