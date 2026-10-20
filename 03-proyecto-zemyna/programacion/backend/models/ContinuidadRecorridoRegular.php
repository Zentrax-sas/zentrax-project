<?php
declare(strict_types=1);
require_once __DIR__.'/RecoleccionOperativa.php';

/** C3: guards transaccionales; no cambia pertenencias, V19 ni participa. */
final class ContinuidadRecorridoRegular
{
    public function __construct(private PDO $db) {}
    private function rows(string $sql,array $args): array
    {
        if (!$this->db->inTransaction()) throw new LogicException('Continuidad fuera de transacción.');
        $s=$this->db->prepare($sql.($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));
        $s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    private function running(int $squad,?int $vehicle=null): bool
    {
        return (bool)$this->rows("SELECT r.id_recorrido FROM recorrido r JOIN participa p ON p.id_recorrido=r.id_recorrido
            JOIN usa u ON u.id_usa=p.id_usa JOIN vehiculo v ON v.id_vehiculo=u.id_vehiculo
            WHERE r.estado='En Proceso' AND v.funcion_operativa='REGULAR' AND u.id_cuadrilla=?"
            .($vehicle===null?'':' AND u.id_vehiculo=?').' ORDER BY r.id_recorrido',
            $vehicle===null?[$squad]:[$squad,$vehicle]);
    }
    private function eligible(int $squad): int
    {
        $count=0;$op=new RecoleccionOperativa($this->db);
        foreach ($this->rows('SELECT DISTINCT id_usuario FROM usuario_cuadrilla WHERE id_cuadrilla=? AND fecha_fin IS NULL ORDER BY id_usuario',[$squad]) as $m) {
            $id=(int)$m['id_usuario'];
            if ($op->elegibilidad($id,true)['elegible']) {
                $current=$op->pertenencia($id);
                if ($current && (int)$current['id_cuadrilla']===$squad) $count++;
            }
        }
        return $count;
    }
    /** Llamador mantiene la puerta de pertenencias F6 antes de usuarios/recursos. */
    public function before(int $user): array
    {
        $result=[];
        foreach ($this->rows('SELECT id_cuadrilla FROM usuario_cuadrilla WHERE id_usuario=? AND fecha_fin IS NULL ORDER BY id_cuadrilla',[$user]) as $m) {
            $squad=(int)$m['id_cuadrilla'];
            if ($this->running($squad)) $result[$squad]=$this->eligible($squad);
        }
        return $result;
    }
    public function after(array $before): void
    {
        foreach ($before as $squad=>$count) if ($count>0 && $this->eligible((int)$squad)===0) {
            throw new DomainException('La cuadrilla tiene un recorrido REGULAR En Proceso. Debe permanecer un integrante habilitado.',409);
        }
    }
    /** Cuadrilla y versión V19 ya bloqueadas; protege cierre y sustitución. */
    public function assertAssignmentCanClose(array $assignment): void
    {
        if ($this->running((int)$assignment['id_cuadrilla'],(int)$assignment['id_vehiculo'])) {
            throw new DomainException('La utilización sostiene un recorrido REGULAR En Proceso. Finalizá el recorrido antes de cerrar o cambiar el vehículo.',409);
        }
    }
}
