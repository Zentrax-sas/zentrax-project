<?php
/** Copias de código, archivos y sesiones en TEMP; nunca sirve el workspace. */
final class F1HttpSandbox {
    public string $root;
    private $process=null;
    private array $pipes=[];
    private string $base;
    public function __construct(string $database) {
        if(!preg_match('/^zemyna_f1_test_[a-f0-9]{12}$/D',$database))throw new LogicException('BD HTTP no aislada.');
        $this->root=sys_get_temp_dir().DIRECTORY_SEPARATOR.'zemyna_f1_http_'.bin2hex(random_bytes(8));
        mkdir($this->root,0700);mkdir($this->root.'/sessions',0700);mkdir($this->root.'/upload-temp',0700);
        foreach(['api','config','controllers','helpers','models','exceptions']as$directory){
            mkdir($this->root.'/'.$directory,0700);
            foreach(glob(__DIR__.'/../../'.$directory.'/*.php')?:[]as$file)copy($file,$this->root.'/'.$directory.'/'.basename($file));
        }
        $socket=stream_socket_server('tcp://127.0.0.1:0',$code,$message);
        if(!$socket)throw new RuntimeException('No se pudo reservar un puerto de prueba.');
        $address=stream_socket_get_name($socket,false);fclose($socket);$this->base='http://'.$address;
        $environment=getenv();$environment['DB_NAME']=$database;
        $this->process=proc_open([PHP_BINARY,'-d','session.save_path='.$this->root.'/sessions','-d','upload_tmp_dir='.$this->root.'/upload-temp','-d','upload_max_filesize=6M','-d','post_max_size=7M','-S',$address,'-t',$this->root],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$this->pipes,null,$environment);
        if(!is_resource($this->process))throw new RuntimeException('No se pudo iniciar HTTP aislado.');
        fclose($this->pipes[0]);stream_set_blocking($this->pipes[1],false);stream_set_blocking($this->pipes[2],false);
        $deadline=microtime(true)+8;
        do{$ready=@stream_socket_client('tcp://'.$address,$code,$message,0.1);if($ready){fclose($ready);return;}usleep(20000);}while(microtime(true)<$deadline);
        $this->close();throw new RuntimeException('HTTP aislado no disponible.');
    }
    public function session(?int $user, array $permissions=[]): string {
        $id=bin2hex(random_bytes(16));$record='';
        if($user!==null){$auth=array_map(fn($p)=>['permiso'=>$p,'sector'=>'OPERACIONES'],$permissions);
            $record='usuario|'.serialize(['id_usuario'=>$user,'roles'=>['OPERARIO'],'autorizaciones'=>$auth]);}
        // Captcha conocido exclusivamente dentro del fixture HTTP descartable.
        $record.='captcha_resultado|i:7;';file_put_contents($this->root.'/sessions/sess_'.$id,$record);
        return 'PHPSESSID='.$id;
    }
    public function request(string $method,string $path,string $body='',array $headers=[]): array {
        $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$body,'ignore_errors'=>true,'timeout'=>20]]);
        $data=file_get_contents($this->base.$path,false,$context);
        $responseHeaders=$http_response_header??[];preg_match('/\s(\d{3})\s/',$responseHeaders[0]??'',$match);
        return ['status'=>(int)($match[1]??0),'body'=>$data,'headers'=>$responseHeaders];
    }
    public function multipart(array $fields,string $bytes,string $cookie=''): array {
        $boundary='zemyna'.bin2hex(random_bytes(8));$body='';
        foreach($fields as$key=>$value)$body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"".$key."\"\r\n\r\n".$value."\r\n";
        $body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"foto\"; filename=\"test.png\"\r\nContent-Type: image/png\r\n\r\n".$bytes."\r\n--".$boundary."--\r\n";
        return $this->request('POST','/api/foto.php',$body,['Content-Type: multipart/form-data; boundary='.$boundary,'Cookie: '.$cookie]);
    }
    public function close(): void {
        if(is_resource($this->process)){proc_terminate($this->process);foreach($this->pipes as$pipe)if(is_resource($pipe))fclose($pipe);proc_close($this->process);$this->process=null;}
        if(isset($this->root)&&is_dir($this->root)){
            $real=realpath($this->root);$temp=realpath(sys_get_temp_dir());
            if($real===false||$temp===false||dirname($real)!==$temp||!preg_match('/^zemyna_f1_http_[a-f0-9]{16}$/D',basename($real)))throw new LogicException('Limpieza fuera de TEMP.');
            $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
            foreach($files as$file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($real);
        }
    }
    public function __destruct() {$this->close();}
}
