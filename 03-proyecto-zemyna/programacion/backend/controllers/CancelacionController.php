<?php
require_once __DIR__.'/../models/Cancelacion.php';
require_once __DIR__.'/../models/EdicionTramite.php';
class CancelacionController {
    public function __construct(private Cancelacion $cancelacion,private EdicionTramite $autorizacion) {}
    public function cancelar(string $tracking,array $data,string $token,bool $permiso=false):array {
        try {
            if(!preg_match('/^(INC|RET)-\d{4}-[A-F0-9]{5}$/D',$tracking))throw new DomainException('Código de seguimiento inválido.',400);
            if(!$this->autorizacion->autorizado($tracking,$token))throw new DomainException('Este dispositivo no tiene autorización para cancelar esta incidencia o solicitud.',403);
            if(array_keys($data)!==['revision']||!is_int($data['revision'])||$data['revision']<0)throw new DomainException('Volvé a consultar el estado antes de cancelar.',400);
            return $this->cancelacion->cancelar($tracking,$data['revision']);
        }catch(DomainException $error){return ['success'=>false,'data'=>null,'statusCode'=>$error->getCode(),'message'=>$error->getMessage()];}
        catch(Throwable $error){return ['success'=>false,'data'=>null,'statusCode'=>500,'message'=>'No pudimos confirmar la cancelación. Consultá el estado antes de reintentar.'];}
    }
}
