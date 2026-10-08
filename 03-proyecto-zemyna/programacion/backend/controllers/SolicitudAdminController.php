<?php
require_once __DIR__ . '/../models/SolicitudOperativa.php';

final class SolicitudAdminController
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
            return ['success'=>true,'data'=>$fn(new SolicitudOperativa($this->db), $actor),'message'=>'Operación completada.','errors'=>[],'statusCode'=>200];
        } catch (DomainException $e) { return $this->error(in_array($e->getCode(),[400,401,403,404,409],true)?$e->getCode():500, $e->getMessage()); }
        catch (PDOException $e) {
            $code = (int)($e->errorInfo[1] ?? 0);
            if (in_array($code,[1062,1205,1213],true)) return $this->error(409,'Conflicto concurrente. Volvé a consultar.');
            return $this->error(in_array($code,[2002,2006,2013],true)?503:500,'No se pudo completar la operación.');
        } catch (Throwable $e) { return $this->error(500,'No se pudo completar la operación.'); }
    }
    private function error(int $status, string $message): array { return ['success'=>false,'data'=>null,'message'=>$message,'errors'=>[],'statusCode'=>$status]; }
    public function get(array $q): array {
        return $this->response(function($m,$actor) use ($q) {
            $m->authorize($actor);
            if (array_key_exists('id',$q)) {
                $this->only($q,['id','historial_page','opciones']); $id=$this->integer($q['id']);
                if (isset($q['opciones'])) { if ($q['opciones']!=='asignacion' || isset($q['historial_page'])) $this->invalid(); return $m->options($actor,$id); }
                return $m->detail($actor,$id,$this->integer($q['historial_page']??1,100000));
            }
            $this->only($q,['page','limit','estado','desde','hasta','tipo_solicitud']);
            $q['page']=$this->integer($q['page']??1,100000);$q['limit']=$this->integer($q['limit']??20,100);
            if (isset($q['estado']) && !in_array($q['estado'],array_merge(SolicitudOperativa::STATES,['Cerradas']),true)) $this->invalid();
            if (isset($q['tipo_solicitud']) && !in_array($q['tipo_solicitud'],['Reciclables','Gran volumen'],true)) $this->invalid();
            foreach(['desde','hasta'] as $key) if(isset($q[$key])) { if (!is_string($q[$key])) $this->invalid(); $date=DateTimeImmutable::createFromFormat('!Y-m-d',$q[$key]);if(!$date || $date->format('Y-m-d')!==$q[$key])$this->invalid(); }
            if(isset($q['desde'],$q['hasta']) && $q['desde']>$q['hasta'])$this->invalid();
            return $m->list($actor,$q);
        });
    }
    public function put(array $b): array {
        return $this->response(function($m,$actor) use ($b) {
            $m->authorize($actor,true);
            $action=$b['accion']??null;
            $fields=['accion','id_solicitud','estado_esperado','version_esperada','id_atencion_esperada'];
            if($action==='confirmar_residuo')$fields[]='id_tipo_residuo';
            elseif(in_array($action,['asignar','reasignar'],true))$fields=array_merge($fields,['id_cuadrilla','id_asignacion_vehiculo','id_usa']);
            elseif(!in_array($action,['desasignar','cancelar'],true))$this->invalid();
            if(in_array($action,['reasignar','desasignar','cancelar'],true))$fields[]='motivo';
            $this->only($b,$fields);foreach($fields as $key)if(!array_key_exists($key,$b))$this->invalid();
            foreach(['id_solicitud','id_tipo_residuo','id_cuadrilla','id_asignacion_vehiculo','id_usa'] as $key)if(array_key_exists($key,$b))$b[$key]=$this->integer($b[$key]);
            if($b['id_atencion_esperada']!==null)$b['id_atencion_esperada']=$this->integer($b['id_atencion_esperada']);
            if(!in_array($b['estado_esperado'],SolicitudOperativa::STATES,true) || !is_string($b['version_esperada']) || !preg_match('/^[a-f0-9]{64}$/D',$b['version_esperada']))$this->invalid();
            if(array_key_exists('motivo',$b)) { if(!is_string($b['motivo']))$this->invalid();$b['motivo']=trim($b['motivo']);if(mb_strlen($b['motivo'],'UTF-8')<1 || mb_strlen($b['motivo'],'UTF-8')>500)$this->invalid(); }
            return $m->mutate($actor,$b);
        });
    }
}
