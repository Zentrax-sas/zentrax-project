<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/RutaApoyoService.php';

final class RutaApoyoController
{
    public function __construct(private ?PDO $db, private ?Closure $clientFactory = null) {}
    private function error(int $status, string $message, string $code = 'consulta_rechazada'): array
    { return ['success' => false, 'data' => null, 'message' => $message, 'code' => $code, 'errors' => [], 'statusCode' => $status]; }
    public function get(array $query, string $method = 'GET'): array
    {
        try {
            $actor = $_SESSION['usuario']['id_usuario'] ?? null;
            if ((!is_int($actor) && !is_string($actor)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $actor) || (int) $actor > 2147483647) return $this->error(401, 'No autenticado.');
            if ($method !== 'GET') return $this->error(405, 'Método no permitido.');
            if (array_diff(array_keys($query), ['id_incidencia', 'origen_latitud', 'origen_longitud'])
                || array_diff(['id_incidencia', 'origen_latitud', 'origen_longitud'], array_keys($query))
                || (!is_int($query['id_incidencia']) && !is_string($query['id_incidencia']))
                || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $query['id_incidencia']) || (int) $query['id_incidencia'] > 2147483647) {
                return $this->error(400, 'Parámetros inválidos.');
            }
            $origin = [];
            foreach (['latitud', 'longitud'] as $key) {
                $v = $query['origen_' . $key];
                if ((!is_int($v) && !is_float($v) && !is_string($v)) || !preg_match('/^-?\d{1,3}(?:\.\d{1,7})?$/D', (string) $v)) return $this->error(400, 'Coordenadas de origen inválidas.');
                $origin[$key] = (float) $v;
            }
            try { $origin = OsrmClient::point($origin); }
            catch (InvalidArgumentException $e) { return $this->error(400, 'Coordenadas de origen inválidas.'); }
            if (!$this->db) return $this->error(503, 'Servicio temporalmente no disponible.');
            $service = new RutaApoyoService($this->db, $this->clientFactory ?? static fn() => OsrmClient::fromEnvironment());
            return ['success' => true, 'data' => $service->suggest((int) $actor, (int) $query['id_incidencia'], $origin),
                'message' => 'Sugerencia vial consultiva.', 'errors' => [], 'statusCode' => 200];
        } catch (RutaVialException $e) { return $this->error($e->getCode(), $e->getMessage(), $e->reason); }
        catch (DomainException $e) { return $this->error($e->getCode(), $e->getMessage()); }
        catch (Throwable $e) { return $this->error(503, 'No se pudo validar o consultar la sugerencia vial.'); }
    }
}
