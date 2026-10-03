<?php
require_once __DIR__ . '/../models/AsignacionVehiculo.php';

class AsignacionVehiculoController
{
    public function __construct(private ?PDO $db) {}
    private function id($value): bool { return (is_int($value) || is_string($value)) && preg_match('/^[1-9][0-9]*$/D',(string)$value) && (float)$value<=2147483647; }
    public function handle(string $method, array $data): array {
        $actor=$_SESSION['usuario']['id_usuario'] ?? null;
        try {
            if (!$this->id($actor)) throw new DomainException('No autenticado.',401);
            if (!in_array($method,['GET','POST'],true)) throw new DomainException('Metodo no permitido.',405);
            if (!$this->db) throw new DomainException('Base de datos no disponible.',503);
            $model=new AsignacionVehiculo($this->db);
            $model->authorize((int)$actor,$method==='POST');
            if ($method==='GET') {
                if (array_diff(array_keys($data),['id_cuadrilla','page'])) throw new DomainException('Parametros no admitidos.',400);
                if (!$data) $result=$model->catalog((int)$actor);
                else {
                    if (!$this->id($data['id_cuadrilla'] ?? null) || (isset($data['page']) && (!$this->id($data['page']) || (int)$data['page']>1000000))) throw new DomainException('IDs o pagina invalidos.',400);
                    $result=$model->detail((int)$actor,(int)$data['id_cuadrilla'],(int)($data['page'] ?? 1));
                }
            } else {
                $action=$data['accion'] ?? null;
                if (!in_array($action,['abrir','cerrar','cambiar'],true)) throw new DomainException('Accion invalida.',400);
                $allowed=['accion','id_cuadrilla'];
                if ($action!=='cerrar') $allowed[]='id_vehiculo';
                if ($action!=='abrir') $allowed=array_merge($allowed,['id_asignacion_vehiculo','motivo']);
                if (array_diff(array_keys($data),$allowed) || array_diff($allowed,array_keys($data))) throw new DomainException('Campos invalidos. Actores y fechas provienen del servidor.',400);
                foreach (['id_cuadrilla','id_vehiculo','id_asignacion_vehiculo'] as $key) if (array_key_exists($key,$data) && !$this->id($data[$key])) throw new DomainException('IDs positivos obligatorios.',400);
                $reason=null;
                if ($action!=='abrir') {
                    if (!is_string($data['motivo']) || trim($data['motivo'])==='' || mb_strlen(trim($data['motivo']),'UTF-8')>150) throw new DomainException('Indica un motivo de hasta 150 caracteres.',400);
                    $reason=trim($data['motivo']);
                }
                $result=$model->mutate((int)$actor,$action,(int)$data['id_cuadrilla'],isset($data['id_vehiculo'])?(int)$data['id_vehiculo']:null,isset($data['id_asignacion_vehiculo'])?(int)$data['id_asignacion_vehiculo']:null,$reason);
            }
            return ['success'=>true,'statusCode'=>200,'data'=>$result];
        } catch (DomainException $e) { return ['success'=>false,'statusCode'=>$e->getCode(),'message'=>$e->getMessage()]; }
        catch (PDOException $e) {
            $conflict=in_array((int)($e->errorInfo[1] ?? 0),[1062,1205,1213],true);
            return ['success'=>false,'statusCode'=>$conflict?409:503,'message'=>$conflict?'Conflicto concurrente. Volve a consultar.':'No se pudo completar la operacion.'];
        }
    }
}
