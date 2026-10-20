<?php
require_once __DIR__.'/../helpers/ReglaCancelacion.php';
require_once __DIR__.'/Seguimiento.php';
require_once __DIR__.'/../helpers/TramitePublico.php';
class Cancelacion {
    public function __construct(private PDO $db) {}
    public function cancelar(string $tracking,int $revision): array {
        $inc = str_starts_with($tracking,'INC-');
        $tabla=$inc?'incidencia':'solicitud';
        $this->db->beginTransaction();
        try {
            if (!$inc) TramitePublico::gate($this->db);
            // El bloqueo protege estado, asignación y revisión en una única operación.
            $stmt=$this->db->prepare("SELECT * FROM $tabla WHERE tracking_number=? FOR UPDATE");
            $stmt->execute([$tracking]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { $this->db->rollBack();return ['success'=>false,'statusCode'=>404,'data'=>null,'message'=>'No se encontró la incidencia o solicitud.']; }
            if (!$inc && TramitePublico::asignada($this->db, $row, true)) $row['asignada'] = true;
            if (!ReglaCancelacion::permitida($row) || (int)$row['revision']!==$revision) {
                $actual=(new Seguimiento($this->db))->findByTracking($tracking);
                $mensaje=ReglaCancelacion::permitida($row)?'Los datos cambiaron. Revisá la información actual antes de cancelar.':ReglaCancelacion::mensaje($tracking,$row);
                $this->db->rollBack();return ['success'=>false,'statusCode'=>409,'data'=>$actual,'message'=>$mensaje];
            }
            $stmt=$this->db->prepare("UPDATE $tabla SET estado='Cancelada',fecha_cancelacion=NOW(6),origen_cancelacion='CIUDADANA',updated_at=NOW(6),revision=revision+1 WHERE tracking_number=? AND estado='Pendiente' AND revision=?");
            $stmt->execute([$tracking,$revision]);
            if ($stmt->rowCount()!==1) throw new RuntimeException('Actualización no confirmada.');
            $actual=(new Seguimiento($this->db))->findByTracking($tracking);
            $this->db->commit();
            return ['success'=>true,'statusCode'=>200,'data'=>$actual,'message'=>$inc?'Incidencia cancelada.':'Solicitud cancelada.'];
        } catch(Throwable $error) {
            if($this->db->inTransaction())$this->db->rollBack();throw $error;
        }
    }
}
