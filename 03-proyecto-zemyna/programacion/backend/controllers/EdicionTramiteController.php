<?php
require_once __DIR__ . '/../models/EdicionTramite.php';
class EdicionTramiteController {
    public function __construct(private EdicionTramite $model) {}
    public function ejecutar(string $method, string $tracking, array $data, string $token, bool $permiso = false): array {
        try {
            if (!preg_match('/^(INC|RET)-\d{4}-[A-F0-9]{5}$/D', $tracking)) throw new DomainException('Código de seguimiento inválido.', 400);
            if (!$this->model->autorizado($tracking, $token)) throw new DomainException('Este dispositivo no tiene autorización para editar el trámite.', 403);
            if ($method === 'GET') {
                $row = $this->model->detalle($tracking);
                if (!$row) throw new DomainException('No se encontró el trámite.', 404);
            } else {
                $inc = str_starts_with($tracking, 'INC-');
                $permitidos = $inc ? ['descripcion','tipo_problema','revision'] : ['descripcion','id_tipo_residuo','revision'];
                if (array_diff(array_keys($data), $permitidos)) throw new DomainException('La edición contiene campos que no se pueden modificar.', 400);
                if (!isset($data['descripcion']) || !is_string($data['descripcion']) || trim($data['descripcion']) === '' || mb_strlen($data['descripcion']) > ($inc ? 500 : 220)) throw new DomainException('Ingresá una descripción válida dentro del límite indicado.', 400);
                if (!isset($data['revision']) || !is_int($data['revision']) || $data['revision'] < 0) throw new DomainException('Volvé a consultar el trámite antes de editar.', 400);
                if ($inc && !in_array($data['tipo_problema'] ?? null, ['Contenedor Desbordado','Contenedor Roto/Dañado','Obstruido por Vehículo','Incendio/Vandalismo'], true)) throw new DomainException('Seleccioná un motivo válido.', 400);
                if (!$inc && (!isset($data['id_tipo_residuo']) || !is_int($data['id_tipo_residuo']) || $data['id_tipo_residuo'] < 1)) throw new DomainException('Seleccioná un tipo de residuo válido.', 400);
                $data['descripcion'] = trim($data['descripcion']);
                $row = $this->model->guardar($tracking, $data);
            }
            return ['success'=>true,'data'=>$row,'message'=>$method === 'GET' ? 'Datos consultados.' : 'Cambios guardados correctamente.','statusCode'=>200];
        } catch (DomainException $error) {
            return ['success'=>false,'data'=>null,'message'=>$error->getMessage(),'statusCode'=>$error->getCode()];
        } catch (Throwable $error) {
            return ['success'=>false,'data'=>null,'message'=>'No se pudieron guardar o consultar los datos. Intentá nuevamente.','statusCode'=>500];
        }
    }
}
