<?php
require_once __DIR__ . '/../models/SolicitudPropiaOperativa.php';

final class SolicitudesPropiasController
{
    public function __construct(private ?PDO $db) {}
    private function invalid(): never { throw new DomainException('Parámetros inválidos.', 400); }
    private function integer(mixed $v, int $max = 2147483647): int {
        if ((!is_int($v) && !is_string($v)) || !preg_match('/^[1-9][0-9]*$/D', (string)$v) || strlen((string)$v)>10 || (int)$v>$max) $this->invalid();
        return (int)$v;
    }
    private function only(array $b, array $fields): void { if (array_diff(array_keys($b), $fields)) $this->invalid(); }
    private function response(callable $fn): array {
        try {
            if (!$this->db) return $this->error(503, 'Servicio temporalmente no disponible.');
            if (empty($_SESSION['usuario']['id_usuario'])) return $this->error(401,'No autenticado.');
            $actor = $this->integer($_SESSION['usuario']['id_usuario']);
            return ['success'=>true,'data'=>$fn(new SolicitudPropiaOperativa($this->db), $actor),'message'=>'Operación completada.','errors'=>[],'statusCode'=>200];
        } catch (DomainException $e) { return $this->error(in_array($e->getCode(),[400,401,403,404,409],true)?$e->getCode():500, $e->getMessage()); }
        catch (PDOException $e) {
            $code = (int)($e->errorInfo[1] ?? 0);
            if (in_array($code,[1062,1205,1213],true)) return $this->error(409,'Conflicto concurrente. Volvé a consultar.');
            return $this->error(in_array($code,[2002,2006,2013],true)?503:500,'No se pudo completar la operación.');
        } catch (Throwable $e) { return $this->error(500,'No se pudo completar la operación.'); }
    }
    private function error(int $status, string $message): array { return ['success'=>false,'data'=>null,'message'=>$message,'errors'=>[],'statusCode'=>$status]; }
    public function get(array $q): array {
        return $this->response(function($m,$actor) use($q){
            $m->authorize($actor);$this->only($q,['id_solicitud','page','limit']);
            $id=array_key_exists('id_solicitud',$q)?$this->integer($q['id_solicitud']):null;
            return $m->get($actor,$id,$this->integer($q['page']??1,100000),$this->integer($q['limit']??20,100));
        });
    }
    public function put(array $b): array {
        return $this->response(function($m,$actor) use($b){
            $m->authorize($actor);
            $fields=['accion','id_solicitud','id_atencion_solicitud','estado_esperado','estado_operativo_esperado','version_esperada'];
            if(!in_array($b['accion']??null,['aceptar_solicitud','rechazar_solicitud','iniciar_atencion_solicitud','finalizar_atencion_solicitud'],true))$this->invalid();
            if($b['accion']==='rechazar_solicitud')$fields[]='motivo';
            $this->only($b,$fields);foreach($fields as $f)if(!array_key_exists($f,$b))$this->invalid();
            foreach(['id_solicitud','id_atencion_solicitud'] as $f)$b[$f]=$this->integer($b[$f]);
            if(!in_array($b['estado_esperado'],['Programada','En atención'],true) || !in_array($b['estado_operativo_esperado'],['Asignada','Aceptada','En atención'],true) || !is_string($b['version_esperada']) || !preg_match('/^[a-f0-9]{64}$/D',$b['version_esperada']))$this->invalid();
            if(array_key_exists('motivo',$b)){if(!is_string($b['motivo']))$this->invalid();$b['motivo']=trim($b['motivo']);if(mb_strlen($b['motivo'],'UTF-8')<1||mb_strlen($b['motivo'],'UTF-8')>500)$this->invalid();}
            return $m->operate($actor,$b);
        });
    }
}
