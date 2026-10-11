<?php
require_once __DIR__.'/../models/ReporteOperativo.php';

final class ReporteOperativoController
{
    public function __construct(private ?PDO $db) {}
    private function error(int $status, string $message): array { return ['success'=>false,'data'=>null,'message'=>$message,'errors'=>[],'statusCode'=>$status]; }
    private function deny(int $status, string $message): never { throw new DomainException($message,$status); }
    public function consultar(array $q, string $method = 'GET'): array {
        $own = false;
        try {
            $actor = $_SESSION['usuario']['id_usuario'] ?? null;
            if ((!is_int($actor) && !is_string($actor)) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string)$actor) || (int)$actor>2147483647) $this->deny(401,'No autenticado.');
            if (!$this->db) return $this->error(503,'Servicio temporalmente no disponible.');
            if ($this->db->inTransaction()) throw new LogicException('Transacción externa no admitida.');
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $this->db->beginTransaction(); $own = true;
            $s = $this->db->prepare('SELECT activo FROM usuario WHERE id_usuario=?'); $s->execute([(int)$actor]);
            if ($s->fetchColumn() !== 'Activo') $this->deny(401,'Sesión inválida o cuenta inactiva.');
            $report = $q['informe'] ?? 'todos';
            if (!is_string($report) || !in_array($report,['solicitudes','atencion','recorridos','todos'],true) || array_diff(array_keys($q),['informe','desde','hasta'])) $this->deny(400,'Parámetros inválidos.');
            $date = (new DateTimeImmutable('now',new DateTimeZone('America/Montevideo')))->format('Y-m-d');
            $s = $this->db->prepare("SELECT DISTINCT p.nombre FROM usuario_rol ur JOIN rol_permiso rp ON rp.id_rol=ur.id_rol JOIN permiso p ON p.id_permiso=rp.id_permiso WHERE ur.id_usuario=? AND ur.sector='OPERACIONES' AND ur.fecha_desde<=? AND (ur.fecha_hasta IS NULL OR ur.fecha_hasta>=?)");
            $s->execute([(int)$actor,$date,$date]); $permissions = $s->fetchAll(PDO::FETCH_COLUMN);
            $required = match($report) { 'solicitudes'=>['solicitud.consultar'], 'atencion'=>['incidencia.consultar','solicitud.consultar'], 'recorridos'=>['recorrido.consultar','contenedor.consultar'], default=>['solicitud.consultar','incidencia.consultar','recorrido.consultar','contenedor.consultar'] };
            if (array_diff($required,$permissions)) $this->deny(403,'Sin permisos vigentes para las fuentes del informe en Operaciones.');
            if ($method !== 'GET') $this->deny(405,'Método no permitido.');
            $dates = [];
            foreach (['desde','hasta'] as $key) {
                $v = $q[$key] ?? null;
                if ($v === '' || $v === null) { $dates[$key] = null; continue; }
                if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v) || $v<'1000-01-01' || $v>'9999-12-30') $this->deny(400,'Fechas inválidas.');
                $d = DateTimeImmutable::createFromFormat('!Y-m-d',$v,new DateTimeZone('America/Montevideo'));
                if (!$d || $d->format('Y-m-d') !== $v) $this->deny(400,'Fechas inválidas.');
                $dates[$key] = $v;
            }
            if ($dates['desde'] && $dates['hasta'] && $dates['desde']>$dates['hasta']) $this->deny(400,'Desde no puede superar hasta.');
            $until = $dates['hasta'] === null ? null : (new DateTimeImmutable($dates['hasta'],new DateTimeZone('America/Montevideo')))->modify('+1 day')->format('Y-m-d');
            $data = (new ReporteOperativo($this->db))->consultar($report,$dates['desde'],$until);
            $data = ['informe'=>$report,'zona_horaria'=>'America/Montevideo','periodo'=>['desde'=>$dates['desde'],'hasta'=>$dates['hasta']],'generado_en'=>(new DateTimeImmutable('now',new DateTimeZone('America/Montevideo')))->format('Y-m-d H:i:s')] + $data;
            $this->db->commit(); $own = false;
            return ['success'=>true,'data'=>$data,'message'=>'Informe consultado.','errors'=>[],'statusCode'=>200];
        } catch (Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            if ($e instanceof DomainException) return $this->error($e->getCode(),$e->getMessage());
            $status = $e instanceof PDOException && in_array((int)($e->errorInfo[1]??0),[2002,2006,2013],true)?503:500;
            return $this->error($status,'No se pudo consultar el informe operativo.');
        }
    }
}
