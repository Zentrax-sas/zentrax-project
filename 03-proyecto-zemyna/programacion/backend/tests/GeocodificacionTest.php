<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../models/Geocodificacion.php';

class GeocodificacionDoble extends Geocodificacion {
    public ?array $cache = null;
    public array $responses = [];
    public int $requests = 0;
    public array $saved = [];
    public function __construct() { parent::__construct(null); }
    public function findCache($idContenedor) { return $this->cache; }
    public function saveCache($idContenedor, $direccion, $barrio, $localidad) {
        $this->saved[] = compact('idContenedor', 'direccion', 'barrio', 'localidad'); return true;
    }
    protected function consultarNominatim(float $lat, float $lon): array {
        $this->requests++;
        if (!$this->responses) throw new RuntimeException('Request inesperado');
        return array_shift($this->responses);
    }
}

final class GeocodificacionTest extends TestCase {
    public function testNumeracionesRealesEnRespuestaYCache(): void {
        foreach ([
            ['Timote', '5394,5396,5398', 'Timote'],
            ['Emancipación', '4626,4630', 'Emancipación'],
            ['Emancipación', '5485,5487,5497,5499,5497 BIS', 'Emancipación'],
            ['General Hornos', '5286,5286 BIS,5284 A', 'General Hornos'],
            ['General Hornos', '5419,5419 BIS', 'General Hornos'],
            ['Costanera Parque Tomkinson', '2676,2700', 'Costanera Parque Tomkinson'],
            ['Calle', '5419', 'Calle 5419'],
            ['Calle', '5419 BIS', 'Calle 5419 BIS'],
            ['Calle', '5284 A', 'Calle 5284 A'],
            ['Calle Ejemplo', null, 'Calle Ejemplo'],
            [null, '5419,5421', null],
        ] as [$via, $numero, $expected]) {
            $display = ($numero === null ? '' : $numero . ', ') . ($via === null ? '' : $via . ', ') . 'Montevideo, Uruguay';
            $result = Geocodificacion::normalizarRespuesta(['display_name' => $display, 'address' => ['road' => $via, 'house_number' => $numero]]);
            $this->assertSame($expected, $result['direccion']);
            $this->assertSame($display, $result['display_name']);
            $cached = Geocodificacion::normalizarCache(['direccion' => $display, 'localidad' => 'Montevideo']);
            $this->assertSame($expected, $cached['direccion']);
            $this->assertSame($display, $cached['display_name']);
        }
    }

    public function testViaSinNumeroEsValidaSinInventarPuerta(): void {
        foreach ([[], ['house_number' => null], ['house_number' => ''], ['house_number' => '   ']] as $number) {
            $result = Geocodificacion::normalizarRespuesta(['address' => array_merge(['road' => 'Los Lirios'], $number)]);
            $this->assertSame('Los Lirios', $result['direccion']);
        }
        $this->assertSame('Los Lirios 2733', Geocodificacion::normalizarRespuesta(['address' => ['road' => 'Los Lirios', 'house_number' => '2733']])['direccion']);
        // Listas cortas y largas usan solo la via.
        $this->assertSame('Los Lirios', Geocodificacion::normalizarRespuesta(['address' => ['road' => 'Los Lirios', 'house_number' => '2733,2735']])['direccion']);
        $this->assertSame('Los Lirios', Geocodificacion::normalizarRespuesta(['address' => ['road' => 'Los Lirios', 'house_number' => implode(',', range(2600, 2644))]])['direccion']);
        $this->assertNull(Geocodificacion::normalizarRespuesta(['address' => ['suburb' => 'La Paloma', 'house_number' => '2733']])['direccion']);
    }

    public function testId180ConservaDisplayLargoYReutilizaCacheSinElegirNumero(): void {
        $body = json_decode(file_get_contents(__DIR__ . '/fixtures/nominatim-id180.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertGreaterThan(255, mb_strlen($body['display_name'], 'UTF-8'));
        $geo = new GeocodificacionDoble();
        $geo->responses = [$this->response(200, false, $body)];
        $row = ['id_contenedor' => 180, 'latitud' => '-34.8484151', 'longitud' => '-56.2794918'];
        $result = $geo->resolver($row);
        $this->assertSame('Los Lirios', $result['data']['direccion']);
        $this->assertNull($result['error']);
        $this->assertTrue($result['cache_saved']);
        $this->assertSame($body['display_name'], $geo->saved[0]['direccion']);
        $geo->cache = $geo->saved[0];
        $cached = $geo->resolver($row);
        $this->assertSame('cache', $cached['source']);
        $this->assertSame('Los Lirios', $cached['data']['direccion']);
        $this->assertSame(0, $cached['requests']);
        $this->assertSame(1, $geo->requests);
    }
    public function testMultiplesNumerosYLimitesSinTruncar(): void {
        foreach ([
            ['Los Lirios', '2733', 'Los Lirios 2733'],
            ['Los Lirios', '2733,2735', 'Los Lirios'],
            ['Los Lirios', implode(',', range(2600, 2644)), 'Los Lirios'],
            ['Los Lirios', str_repeat('2', 151), null],
            ['Los Lirios', implode(',', array_fill(0, 45, '2733')), null],
            [str_repeat('a', 151), '1,2', null],
            [null, implode(',', range(2600, 2644)), null],
            ['Los Lirios', str_repeat('desconocido,', 30), null],
        ] as [$road, $number, $expected]) {
            $this->assertSame($expected, Geocodificacion::normalizarRespuesta(['address' => ['road' => $road, 'house_number' => $number]])['direccion']);
        }
    }
    public function testNormalizaSoloViaYNumeroConPrioridad(): void {
        $result = Geocodificacion::normalizarRespuesta(['display_name' => '1234, Miguel Martínez, Buceo, Montevideo, Uruguay',
            'address' => ['road' => 'Miguel Martínez', 'pedestrian' => 'Otra via', 'house_number' => '1234',
                'neighbourhood' => 'Buceo', 'suburb' => 'Otro barrio', 'city' => 'Montevideo', 'town' => 'Otra localidad', 'postcode' => '11600']]);
        $this->assertSame(['direccion' => 'Miguel Martínez 1234', 'barrio' => 'Buceo', 'localidad' => 'Montevideo',
            'display_name' => '1234, Miguel Martínez, Buceo, Montevideo, Uruguay'], $result);
    }
    public function testAlternativasDeViaYLocalidad(): void {
        foreach (['road', 'pedestrian', 'residential', 'footway', 'path', 'service'] as $key) {
            $result = Geocodificacion::normalizarRespuesta(['address' => [$key => 'Estivao', 'suburb' => 'Buceo', 'village' => 'Villa']]);
            $this->assertSame('Estivao', $result['direccion']);
            $this->assertSame('Buceo', $result['barrio']);
            $this->assertSame('Villa', $result['localidad']);
        }
        $this->assertSame('Villa', Geocodificacion::normalizarRespuesta(['address' => ['town' => 'Villa']])['localidad']);
    }
    public function testNoUsaDisplayNameComoViaNiTrunca(): void {
        $this->assertNull(Geocodificacion::normalizarRespuesta(['display_name' => 'Punta Carretas, Montevideo, Uruguay',
            'address' => ['suburb' => 'Punta Carretas', 'city' => 'Montevideo']])['direccion']);
        $this->assertNull(Geocodificacion::normalizarRespuesta(['address' => ['road' => str_repeat('a', 151)]])['direccion']);
        $this->assertNull(Geocodificacion::normalizarRespuesta(['address' => ['road' => '<script>']])['direccion']);
        $this->assertSame('Camino', Geocodificacion::normalizarRespuesta(['address' => ['road' => [], 'path' => 'Camino']])['direccion']);
    }
    public function testCacheHistoricaConNumeroMultiplesNumerosYPoi(): void {
        foreach ([
            ['3766, Miguel Martínez, Buceo, Montevideo, Uruguay', 'Miguel Martínez 3766'],
            ['3096,3098, Avenida Brasil, Pocitos, Montevideo, Uruguay', 'Avenida Brasil'],
            ['Escuela 35, 1411, Melitón González, Buceo, Montevideo, Uruguay', 'Melitón González 1411'],
            ['Avenida Brasil, Pocitos, Montevideo, Uruguay', 'Avenida Brasil'],
            ['1234, 18 de Julio, Centro, Montevideo, Uruguay', '18 de Julio 1234'],
        ] as [$display, $expected]) {
            $result = Geocodificacion::normalizarCache(['direccion' => $display, 'barrio' => 'Buceo', 'localidad' => 'Montevideo']);
            $this->assertSame($expected, $result['direccion']);
            $this->assertSame($display, $result['display_name']);
        }
    }
    public function testCacheBarrioOTextoAmbiguoNoEsVia(): void {
        foreach (['Punta Carretas, Montevideo, Uruguay', 'Antel, Constituyente, Centro, Montevideo, Uruguay', '1000, Punta Carretas, Montevideo, Uruguay'] as $display) {
            $this->assertNull(Geocodificacion::normalizarCache(['direccion' => $display, 'barrio' => 'Punta Carretas', 'localidad' => 'Montevideo'])['direccion']);
        }
    }
    private function contenedor(): array { return ['id_contenedor' => 10837, 'latitud' => '-34.8952274', 'longitud' => '-56.1328721']; }
    private function response(int $status, bool $transient, ?array $body = null): array {
        return ['status' => $status, 'transient' => $transient, 'requested' => true, 'body' => $body];
    }
    public function testCacheValidaNoConsultaExterno(): void {
        $geo = new GeocodificacionDoble();
        $geo->cache = ['direccion' => '3766, Miguel Martínez, Buceo, Montevideo, Uruguay', 'barrio' => 'Buceo', 'localidad' => 'Montevideo'];
        $result = $geo->resolver($this->contenedor());
        $this->assertSame('cache', $result['source']);
        $this->assertSame('Miguel Martínez 3766', $result['data']['direccion']);
        $this->assertSame(0, $geo->requests);
        $this->assertSame([], $geo->saved);
    }
    public function testCacheInvalidaConsultaYGuardaDisplayCompleto(): void {
        $geo = new GeocodificacionDoble();
        $geo->cache = ['direccion' => 'Buceo, Montevideo, Uruguay'];
        $geo->responses = [$this->response(200, false, ['display_name' => '3766, Miguel Martínez, Buceo, Montevideo, Uruguay',
            'address' => ['road' => 'Miguel Martínez', 'house_number' => '3766']])];
        $result = $geo->resolver($this->contenedor());
        $this->assertSame('Miguel Martínez 3766', $result['data']['direccion']);
        $this->assertSame('3766, Miguel Martínez, Buceo, Montevideo, Uruguay', $geo->saved[0]['direccion']);
        $this->assertSame(1, $result['requests']);
    }
    public function testRetrySoloTransitorioYMaximoUno(): void {
        $geo = new GeocodificacionDoble();
        $geo->responses = [$this->response(503, true), $this->response(503, true)];
        $this->assertSame('fallback', $geo->resolver($this->contenedor())['source']);
        $this->assertSame(2, $geo->requests);
        foreach ([403, 404, 429] as $status) {
            $geo = new GeocodificacionDoble(); $geo->responses = [$this->response($status, false)];
            $geo->resolver($this->contenedor()); $this->assertSame(1, $geo->requests);
        }
    }
    public function testSinViaNuncaProduceDireccionYCoordInvalidaNoConsulta(): void {
        $geo = new GeocodificacionDoble();
        $geo->responses = [$this->response(200, false, ['display_name' => 'Buceo, Montevideo, Uruguay', 'address' => ['suburb' => 'Buceo']])];
        $this->assertNull($geo->resolver($this->contenedor())['data']['direccion']);
        $geo = new GeocodificacionDoble();
        $this->assertNull($geo->resolver(['id_contenedor' => 1, 'latitud' => 100, 'longitud' => -56])['data']['direccion']);
        $this->assertSame(0, $geo->requests);
    }
}

