<?php
declare(strict_types=1);

/** Grafo dirigido explícito e inmutable, sin dependencias operativas. */
final class GrafoDijkstra
{
    private array $adjacency = [];
    private array $nodes = [];
    private string $version;
    private string $unit;

    public function __construct(array $data)
    {
        self::fields($data, ['version', 'classification', 'cost_unit', 'weight_meaning', 'nodes', 'edges']);
        foreach (['version', 'classification', 'cost_unit', 'weight_meaning'] as $field) {
            if (!is_string($data[$field]) || trim($data[$field]) === '') {
                throw new InvalidArgumentException('Metadatos del grafo inválidos.');
            }
        }
        if (!is_array($data['nodes']) || !array_is_list($data['nodes']) || !$data['nodes']
            || !is_array($data['edges']) || !array_is_list($data['edges'])) {
            throw new InvalidArgumentException('Nodos y aristas deben ser listas; se requiere al menos un nodo.');
        }
        foreach ($data['nodes'] as $node) {
            if (!is_array($node)) throw new InvalidArgumentException('Nodo inválido.');
            self::fields($node, ['id'], ['coordinates']);
            // El prefijo alfabético evita la conversión de claves numéricas de PHP.
            if (!is_string($node['id']) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $node['id'])
                || isset($this->adjacency[$node['id']])) {
                throw new InvalidArgumentException('Identificador de nodo inválido o duplicado.');
            }
            if (array_key_exists('coordinates', $node)) {
                if (!is_array($node['coordinates'])) throw new InvalidArgumentException('Coordenadas inválidas.');
                self::fields($node['coordinates'], ['x', 'y']);
                foreach ($node['coordinates'] as $value) self::number($value, false);
            }
            $this->nodes[$node['id']] = $node;
            $this->adjacency[$node['id']] = [];
        }
        foreach ($data['edges'] as $edge) {
            if (!is_array($edge)) throw new InvalidArgumentException('Arista inválida.');
            self::fields($edge, ['from', 'to', 'weight']);
            foreach (['from', 'to'] as $end) {
                if (!is_string($edge[$end]) || !isset($this->adjacency[$edge[$end]])) {
                    throw new InvalidArgumentException('Extremo de arista inexistente.');
                }
            }
            self::number($edge['weight'], true);
            if (array_key_exists($edge['to'], $this->adjacency[$edge['from']])) {
                throw new InvalidArgumentException('Arista dirigida duplicada.');
            }
            $this->adjacency[$edge['from']][$edge['to']] = (float) $edge['weight'];
        }
        ksort($this->nodes, SORT_STRING);
        ksort($this->adjacency, SORT_STRING);
        foreach ($this->adjacency as &$neighbors) ksort($neighbors, SORT_STRING);
        unset($neighbors);
        $this->version = $data['version'];
        $this->unit = $data['cost_unit'];
    }

    private static function fields(array $data, array $required, array $optional = []): void
    {
        if (array_diff($required, array_keys($data)) || array_diff(array_keys($data), [...$required, ...$optional])) {
            throw new InvalidArgumentException('Estructura del grafo inválida: campos ausentes o desconocidos.');
        }
    }

    private static function number(mixed $value, bool $nonnegative): void
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || ($nonnegative && $value < 0)) {
            throw new InvalidArgumentException('Se requiere un número finito' . ($nonnegative ? ' no negativo.' : '.'));
        }
    }

    public static function fromJson(string $json): self
    {
        // Se decodifica como objetos para distinguir {} de [] en el contrato.
        $object = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        if (!$object instanceof stdClass || !isset($object->nodes, $object->edges)
            || !is_array($object->nodes) || !is_array($object->edges)) {
            throw new InvalidArgumentException('JSON: raíz objeto y listas nodes/edges requeridas.');
        }
        foreach ($object->nodes as $node) {
            if (!$node instanceof stdClass || (isset($node->coordinates) && !$node->coordinates instanceof stdClass)) {
                throw new InvalidArgumentException('JSON: nodo/coordenadas deben ser objetos.');
            }
        }
        foreach ($object->edges as $edge) {
            if (!$edge instanceof stdClass) throw new InvalidArgumentException('JSON: arista debe ser objeto.');
        }
        return new self(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    public static function fromFile(string $path): self
    {
        $json = @file_get_contents($path);
        if ($json === false) throw new RuntimeException('No se pudo leer el grafo.');
        return self::fromJson($json);
    }

    public function nodeIds(): array { return array_keys($this->nodes); }
    public function neighbors(string $id): array
    {
        if (!isset($this->adjacency[$id])) throw new InvalidArgumentException('Nodo inexistente.');
        return $this->adjacency[$id];
    }
    public function version(): string { return $this->version; }
    public function costUnit(): string { return $this->unit; }
}
