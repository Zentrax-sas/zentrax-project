<?php
require_once __DIR__.'/SolicitudPropiaOperativa.php';
require_once __DIR__.'/ContinuidadRecorridoRegular.php';

/** Continuidad F6 y C3: exclusivamente dentro de la transacción de escritura. */
final class ContinuidadSolicitudOperativa
{
    public function __construct(private PDO $db) {}
    private function rows(string $sql, array $args=[], bool $lock=true): array {
        if ($lock && !$this->db->inTransaction()) throw new LogicException('Continuidad fuera de transacción.');
        $s=$this->db->prepare($sql.($lock && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));
        $s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    /** Puerta compartida anterior a solicitud, usuario y recursos. Sin nueva tabla. */
    public function gate(): void { $this->rows('SELECT id_cuadrilla FROM cuadrilla ORDER BY id_cuadrilla'); }
    public function eligible(int $crew, bool $lock=true): int {
        $members=$this->rows('SELECT id_usuario FROM usuario_cuadrilla WHERE id_cuadrilla=? AND fecha_fin IS NULL ORDER BY id_usuario',[$crew],$lock);
        $count=0;$operative=new SolicitudPropiaOperativa($this->db);
        foreach ($members as $m) {
            try {$membership=$operative->authorize((int)$m['id_usuario'],$lock);if((int)$membership['id_cuadrilla']===$crew)$count++;}
            catch (DomainException $e) {if(!in_array($e->getCode(),[401,403,409],true))throw $e;}
        }
        return $count;
    }
    public function protectedWork(int $crew): bool {
        $work=$this->rows("SELECT DISTINCT s.id_solicitud,s.estado,s.id_cuadrilla FROM solicitud s LEFT JOIN atencion_solicitud a ON a.id_solicitud=s.id_solicitud WHERE s.id_cuadrilla=? AND (s.estado='En atención' OR (a.estado='En atención' AND a.fecha_cierre IS NULL)) ORDER BY s.id_solicitud",[$crew]);
        foreach ($work as $s) {
            $a=$this->rows('SELECT * FROM atencion_solicitud WHERE id_solicitud=? AND fecha_cierre IS NULL',[$s['id_solicitud']]);
            if ($s['estado']!=='En atención' || count($a)!==1 || $a[0]['estado']!=='En atención' || (int)$a[0]['id_cuadrilla']!==$crew)
                throw new DomainException('Contexto operativo inconsistente. No se puede modificar la habilitación.',409);
        }
        // Un intento En atención con cuadrilla divergente tampoco puede desaparecer del guard.
        foreach ($this->rows("SELECT s.estado,s.id_cuadrilla,a.fecha_cierre FROM atencion_solicitud a JOIN solicitud s ON s.id_solicitud=a.id_solicitud WHERE a.id_cuadrilla=? AND a.estado='En atención'",[$crew]) as $s)
            if ($s['estado']!=='En atención' || (int)$s['id_cuadrilla']!==$crew || $s['fecha_cierre']!==null)throw new DomainException('Contexto operativo inconsistente.',409);
        return count($work)>0;
    }
    public function before(int $user): array {
        $this->gate();$this->rows('SELECT id_usuario FROM usuario WHERE id_usuario=?',[$user]);$result=[];
        foreach ($this->rows('SELECT id_cuadrilla FROM usuario_cuadrilla WHERE id_usuario=? AND fecha_fin IS NULL',[$user]) as $m) {
            $id=(int)$m['id_cuadrilla'];if($this->protectedWork($id))$result[$id]=$this->eligible($id);
        }
        $result['regular']=(new ContinuidadRecorridoRegular($this->db))->before($user);
        return $result;
    }
    public function after(array $before): void {
        $regular=$before['regular']??[];unset($before['regular']);
        (new ContinuidadRecorridoRegular($this->db))->after($regular);
        foreach ($before as $id=>$count) if($count>0 && $this->eligible((int)$id)===0)
            throw new DomainException('La cuadrilla tiene una solicitud especial en atención. Debe permanecer un integrante habilitado.',409);
    }
    /** Agrupa PUT usuario/roles para evaluar el resultado completo, no hitos intermedios. */
    public function change(int|callable $user, callable $write): array {
        try {
            $this->db->beginTransaction();
            $this->gate();$before=$this->before(is_callable($user)?$user():$user);$r=$write();
            if(($r['statusCode']??500)>=400){$this->db->rollBack();return $r;}
            $this->after($before);$this->db->commit();return $r;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();return self::error($e);}
    }
    public static function error(Throwable $e): array {
        $status=$e instanceof DomainException && in_array($e->getCode(),[400,401,403,404,409],true)?$e->getCode():500;
        if($e instanceof PDOException){$n=(int)($e->errorInfo[1]??0);$status=in_array($n,[1062,1205,1213],true)?409:(in_array($n,[2002,2006,2013],true)?503:500);}
        return ['success'=>false,'data'=>null,'message'=>$e instanceof DomainException?$e->getMessage():($status===409?'Conflicto concurrente. Volvé a consultar.':'No se pudo completar la operación.'),'errors'=>[],'statusCode'=>$status];
    }
}
