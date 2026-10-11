<?php
require_once __DIR__.'/RecoleccionOperativa.php';
require_once __DIR__.'/ContinuidadSolicitudOperativa.php';
final class SolicitudPropiaOperativa
{
    public function __construct(private PDO $db) {}
    public static function now(): string { return (new DateTimeImmutable('now',new DateTimeZone('America/Montevideo')))->format('Y-m-d H:i:s'); }
    private function rows(string $sql,array $args=[],bool $lock=false): array {
        $s=$this->db->prepare($sql.($lock && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    private function write(string $sql,array $args): void {$s=$this->db->prepare($sql);$s->execute($args);}
    private function fail(int $code,string $message): never {throw new DomainException($message,$code);}
    public function authorize(int $actor,bool $lock=false): array {
        $u=$this->rows('SELECT activo FROM usuario WHERE id_usuario=?',[$actor],$lock)[0]??null;
        if(!$u || $u['activo']!=='Activo')$this->fail(401,'Sesión inválida o cuenta inactiva.');
        if(!(new RecoleccionOperativa($this->db))->elegibilidad($actor,$lock)['elegible'])$this->fail(403,'Sin habilitación operativa vigente en Operaciones.');
        $date=substr(self::now(),0,10);
        if(!$this->rows("SELECT p.id_permiso FROM usuario_rol ur JOIN rol_permiso rp ON rp.id_rol=ur.id_rol JOIN permiso p ON p.id_permiso=rp.id_permiso WHERE ur.id_usuario=? AND ur.sector='OPERACIONES' AND ur.fecha_desde<=? AND (ur.fecha_hasta IS NULL OR ur.fecha_hasta>=?) AND p.nombre='solicitud.operar'",[$actor,$date,$date],$lock))$this->fail(403,'Sin permiso vigente para operar solicitudes.');
        $members=$this->rows('SELECT id_usuario_cuadrilla,id_cuadrilla,fecha_inicio FROM usuario_cuadrilla WHERE id_usuario=? AND fecha_fin IS NULL',[$actor],$lock);
        if(count($members)!==1 || $members[0]['fecha_inicio']>self::now())$this->fail(409,'Sin pertenencia actual e inequívoca a una cuadrilla.');
        return $members[0];
    }
    private function snapshot(int $id,bool $lock=false): array {
        $s=$this->rows('SELECT id_solicitud,tracking_number,fecha,tipo_solicitud,id_tipo_residuo,estado,direccion,descripcion,id_cuadrilla,fecha_confirmacion_residuo,fecha_finalizacion FROM solicitud WHERE id_solicitud=?',[$id],$lock)[0]??null;
        $attempts=$this->rows('SELECT * FROM atencion_solicitud WHERE id_solicitud=? ORDER BY id_atencion_solicitud DESC',[$id],$lock);
        $open=array_values(array_filter($attempts,fn($a)=>$a['fecha_cierre']===null));
        if(count($open)>1)$this->fail(409,'Contexto operativo inconsistente.');
        return [$s,$open[0]??null,hash('sha256',json_encode([$s,$attempts],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))];
    }
    private function scoped(?array $s,?array $a,array $member): void {
        if(!$s || !$a || (int)$s['id_cuadrilla']!==(int)$member['id_cuadrilla'] || (int)$a['id_cuadrilla']!==(int)$member['id_cuadrilla'] || !in_array($a['estado'],['Asignada','Aceptada','En atención'],true))$this->fail(404,'Trabajo no disponible para tu cuadrilla.');
    }
    private function resources(array $s,array $a,bool $lock=false): array {
        if($s['fecha_confirmacion_residuo']===null)$this->fail(409,'Residuo pendiente de confirmación.');
        $coherent=($s['estado']==='Programada' && in_array($a['estado'],['Asignada','Aceptada'],true)) || ($s['estado']==='En atención' && $a['estado']==='En atención');
        if(!$coherent)$this->fail(409,'Estado de solicitud e intento inconsistente.');
        try {$o=(new RecoleccionOperativa($this->db))->validarOpcionF3((int)$a['id_cuadrilla'],(int)$a['id_asignacion_vehiculo'],null,null,$lock);}
        // validarOpcionF3 devuelve mensajes de dominio sin identidad ni datos personales.
        catch(DomainException $e){$this->fail(409,$e->getMessage());}
        $v19=$this->rows('SELECT id_cuadrilla,id_vehiculo,fecha_inicio,fecha_fin FROM asignacion_vehiculo_operativa WHERE id_asignacion_vehiculo=?',[$a['id_asignacion_vehiculo']],$lock)[0]??null;
        $v=$this->rows('SELECT id_tipo_residuo FROM vehiculo WHERE id_vehiculo=?',[$o['id_vehiculo']],$lock)[0]??null;
        if(!$v19 || $v19['fecha_fin']!==null || $v19['fecha_inicio']>self::now() || $o['funcion_operativa']!=='APOYO' || !$v || (int)$v['id_tipo_residuo']!==(int)$s['id_tipo_residuo'])$this->fail(409,'V19 no vigente, vehículo no APOYO o residuo incompatible.');
        return ['id_asignacion_vehiculo'=>(int)$a['id_asignacion_vehiculo'],'id_vehiculo'=>(int)$o['id_vehiculo'],'matricula'=>$o['matricula'],'funcion_operativa'=>$o['funcion_operativa']];
    }
    private function project(array $s,array $a,string $version): array {
        $reason=null;$vehicle=null;
        try{$vehicle=$this->resources($s,$a);}catch(DomainException $e){$reason=$e->getMessage();}
        $s['tipo_residuo']=$this->rows('SELECT nombre FROM tipo_residuo WHERE id_tipo_residuo=?',[$s['id_tipo_residuo']])[0]['nombre']??null;
        unset($s['fecha_confirmacion_residuo']);
        $s['atencion']=['id_atencion_solicitud'=>(int)$a['id_atencion_solicitud'],'estado'=>$a['estado'],'id_asignacion_vehiculo'=>(int)$a['id_asignacion_vehiculo']];
        $s['vehiculo']=$vehicle;$s['version_esperada']=$version;$s['estado_esperado']=$s['estado'];$s['estado_operativo_esperado']=$a['estado'];
        $s['capacidades']=['aceptar_solicitud'=>$reason===null && $a['estado']==='Asignada','rechazar_solicitud'=>$reason===null && $a['estado']==='Asignada','iniciar_atencion_solicitud'=>$reason===null && $a['estado']==='Aceptada','finalizar_atencion_solicitud'=>$reason===null && $a['estado']==='En atención'];
        $s['motivo_bloqueo']=$reason;return $s;
    }
    public function get(int $actor,?int $id,int $page,int $limit): array {
        // Una instantánea coherente evita mezclar filas leídas antes/después de una reasignación.
        $this->db->beginTransaction();
        try{
            $member=$this->authorize($actor);$items=[];$more=false;
            if($id!==null){[$s,$a,$version]=$this->snapshot($id);$this->scoped($s,$a,$member);$items[]=$this->project($s,$a,$version);}
            else{
                $offset=($page-1)*$limit;
                $ids=$this->rows("SELECT s.id_solicitud FROM solicitud s JOIN atencion_solicitud a ON a.id_solicitud=s.id_solicitud WHERE s.id_cuadrilla=? AND a.id_cuadrilla=? AND a.fecha_cierre IS NULL AND a.estado IN ('Asignada','Aceptada','En atención') ORDER BY s.fecha DESC,s.id_solicitud DESC LIMIT ".($limit+1)." OFFSET $offset",[$member['id_cuadrilla'],$member['id_cuadrilla']]);
                $more=count($ids)>$limit;
                foreach(array_slice($ids,0,$limit) as $r){[$s,$a,$version]=$this->snapshot((int)$r['id_solicitud']);$this->scoped($s,$a,$member);$items[]=$this->project($s,$a,$version);}
            }
            $result=['items'=>$items,'page'=>$page,'limit'=>$limit,'has_more'=>$more,'cuadrilla'=>['id_cuadrilla'=>(int)$member['id_cuadrilla']]];$this->db->commit();return $result;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function operate(int $actor,array $b): array {
        $this->db->beginTransaction();
        try{
            (new ContinuidadSolicitudOperativa($this->db))->gate();
            [$s,$a,$version]=$this->snapshot($b['id_solicitud'],true);
            $member=$this->authorize($actor,true);$this->scoped($s,$a,$member);
            $origin=['aceptar_solicitud'=>'Asignada','rechazar_solicitud'=>'Asignada','iniciar_atencion_solicitud'=>'Aceptada','finalizar_atencion_solicitud'=>'En atención'][$b['accion']];
            if((int)$a['id_atencion_solicitud']!==$b['id_atencion_solicitud'] || $s['estado']!==$b['estado_esperado'] || $a['estado']!==$b['estado_operativo_esperado'] || $version!==$b['version_esperada'] || $a['estado']!==$origin)$this->fail(409,'La atención cambió. Volvé a consultar.');
            $this->resources($s,$a,true);$now=self::now();
            if($now<$s['fecha'] || $now<($a['fecha_inicio']??$a['fecha_aceptacion']??$a['fecha_asignacion']))$this->fail(409,'Cronología incompatible con el reloj del servidor.');
            $id=$b['id_solicitud'];$attempt=$b['id_atencion_solicitud'];
            if($b['accion']==='aceptar_solicitud'){$state='Aceptada';$this->write("UPDATE atencion_solicitud SET estado='Aceptada',fecha_aceptacion=?,id_usuario_acepta=? WHERE id_atencion_solicitud=?",[$now,$actor,$attempt]);}
            elseif($b['accion']==='iniciar_atencion_solicitud'){$state='En atención';$this->write("UPDATE atencion_solicitud SET estado='En atención',fecha_inicio=?,id_usuario_inicia=? WHERE id_atencion_solicitud=?",[$now,$actor,$attempt]);$this->write("UPDATE solicitud SET estado='En atención' WHERE id_solicitud=?",[$id]);}
            elseif($b['accion']==='rechazar_solicitud'){$state='Rechazada';$this->write("UPDATE atencion_solicitud SET estado='Rechazada',fecha_cierre=?,id_usuario_cierra=?,motivo_cierre=? WHERE id_atencion_solicitud=?",[$now,$actor,$b['motivo'],$attempt]);$this->write("UPDATE solicitud SET estado='Pendiente',id_cuadrilla=NULL WHERE id_solicitud=?",[$id]);}
            else{$state='Finalizada';$this->write("UPDATE atencion_solicitud SET estado='Finalizada',fecha_cierre=?,id_usuario_cierra=? WHERE id_atencion_solicitud=?",[$now,$actor,$attempt]);$this->write("UPDATE solicitud SET estado='Finalizada',fecha_finalizacion=? WHERE id_solicitud=?",[$now,$id]);}
            $result=['id_solicitud'=>$id,'id_atencion_solicitud'=>$attempt,'estado_operativo'=>$state,'estado'=>$state==='Rechazada'?'Pendiente':($state==='Aceptada'?'Programada':$state),'fecha_operacion'=>$now];
            $this->db->commit();return $result;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
