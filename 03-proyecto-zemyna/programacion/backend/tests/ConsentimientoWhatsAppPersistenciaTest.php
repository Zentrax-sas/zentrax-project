<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../controllers/IncidenciaController.php';
require_once __DIR__ . '/../controllers/SolicitudRetiroController.php';

// Integración MySQL opt-in; todas las escrituras son transaccionales en una BD exclusiva.
class ConsentimientoWhatsAppPersistenciaTest extends TestCase
{
    private ?PDO $db = null;
    protected function setUp(): void
    {
        if (getenv('ZEMYNA_CONSENTIMIENTO_TEST') !== '1') $this->markTestSkipped('Activar ZEMYNA_CONSENTIMIENTO_TEST=1 para integración MySQL.');
        $this->db = (new Database())->getConnection();
        $this->assertInstanceOf(PDO::class, $this->db);
        $this->db->exec('CREATE DATABASE IF NOT EXISTS zemyna_consentimiento_test CHARACTER SET utf8mb4');
        $this->db->exec('USE zemyna_consentimiento_test');
        $this->assertSame('zemyna_consentimiento_test', $this->db->query('SELECT DATABASE()')->fetchColumn());
        $this->db->exec('CREATE TABLE IF NOT EXISTS incidencia (
            id_incidencia INT AUTO_INCREMENT PRIMARY KEY, tracking_number VARCHAR(32) UNIQUE,
            descripcion TEXT, fecha_reporte DATETIME, estado VARCHAR(40), prioridad VARCHAR(20), tipo_problema VARCHAR(80),
            id_contenedor INT, id_ruta INT, id_cuadrilla INT, id_usuario INT,
            telefono VARCHAR(20) NULL, consentimiento_whatsapp TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB');
        $this->db->exec('CREATE TABLE IF NOT EXISTS solicitud (
            id_solicitud INT AUTO_INCREMENT PRIMARY KEY, tracking_number VARCHAR(32) UNIQUE,
            fecha DATETIME, descripcion TEXT, direccion VARCHAR(150), estado VARCHAR(40), id_tipo_residuo INT,
            email VARCHAR(100), telefono VARCHAR(20), tipo_solicitud VARCHAR(40), tipo_residuo_solicitado VARCHAR(80), foto_url VARCHAR(100),
            consentimiento_whatsapp TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB');
        $this->db->exec('CREATE TABLE IF NOT EXISTS tipo_residuo (id_tipo_residuo INT PRIMARY KEY, nombre VARCHAR(80)) ENGINE=InnoDB');
        $columns = $this->db->query('SHOW COLUMNS FROM solicitud')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['calle' => 'VARCHAR(150)', 'numero_referencia' => 'VARCHAR(150)', 'latitud' => 'DECIMAL(10,7)', 'longitud' => 'DECIMAL(10,7)'] as $col => $type) {
            if (!in_array($col, $columns, true)) $this->db->exec("ALTER TABLE solicitud ADD COLUMN `$col` $type NULL");
        }
        $this->db->beginTransaction();
        $this->db->exec("INSERT INTO tipo_residuo VALUES (901, 'Residuo de prueba') ON DUPLICATE KEY UPDATE nombre = 'Residuo de prueba'");
    }
    protected function tearDown(): void
    {
        if ($this->db?->inTransaction()) $this->db->rollBack();
    }
    public function testAmbosTramitesPersistenTelefonoConsentimientoYTrackingSinMensajes(): void
    {
        $incidencias = new IncidenciaController($this->db);
        $retiros = new SolicitudRetiroController($this->db);
        $trackings = [];
        foreach ([['099123456', true, 1], ['099123456', false, 0], ['', false, 0], ['099123456', null, 0]] as [$celular, $consentimiento, $esperado]) {
            $input = ['celular' => $celular];
            if ($consentimiento !== null) $input['consentimiento_whatsapp'] = $consentimiento;
            $incidencia = $incidencias->create($input + ['descripcion' => 'Prueba', 'tipo_problema' => 'Contenedor Desbordado', 'id_contenedor' => 1]);
            $solicitud = $retiros->crear($input + ['direccion' => 'Brasil 123', 'descripcion' => 'Mesa', 'id_tipo_residuo' => 901, 'calle' => 'Brasil', 'numero_referencia' => '123', 'latitud' => -34.91, 'longitud' => -56.16]);
            if ($solicitud['success']) {
                $operativa = (new Solicitud($this->db))->findOperativaPorTracking($solicitud['data']['tracking_number']);
                $this->assertSame('Brasil 123', $operativa['direccion']);
                $this->assertSame(-34.91, (float)$operativa['latitud']);
                $this->assertSame(-56.16, (float)$operativa['longitud']);
                $this->assertSame('Residuo de prueba', $operativa['tipo_residuo']);
                $this->assertSame('Pendiente', $operativa['estado']);
            }
            foreach (['incidencia' => $incidencia, 'solicitud' => $solicitud] as $table => $response) {
                $this->assertSame(201, $response['statusCode'], json_encode($response));
                $tracking = $response['data']['tracking_number'];
                $this->assertMatchesRegularExpression($table === 'incidencia' ? '/^INC-\d{4}-[A-F0-9]{5}$/' : '/^RET-\d{4}-[A-F0-9]{5}$/', $tracking);
                $trackings[] = $tracking;
                $query = $this->db->prepare("SELECT telefono, consentimiento_whatsapp FROM `$table` WHERE tracking_number = ?");
                $query->execute([$tracking]); $row = $query->fetch(PDO::FETCH_ASSOC);
                $this->assertSame($celular === '' ? null : $celular, $row['telefono']);
                $this->assertSame($esperado, (int)$row['consentimiento_whatsapp']);
            }
            $this->assertArrayNotHasKey('whatsapp_enviado', $solicitud['data']);
        }
        $this->assertCount(8, array_unique($trackings));
    }
    public function testRechazaConsentimientoSinCelularYValoresAmbiguosSinInsertar(): void
    {
        foreach ([['', true], ['099123456', 'false'], ['099123456', []]] as [$celular, $valor]) {
            $input = ['celular' => $celular, 'consentimiento_whatsapp' => $valor];
            $this->assertSame(400, (new IncidenciaController($this->db))->create($input + ['descripcion' => 'Prueba', 'tipo_problema' => 'Contenedor Desbordado', 'id_contenedor' => 1])['statusCode']);
            $this->assertSame(400, (new SolicitudRetiroController($this->db))->crear($input + ['direccion' => 'Brasil', 'descripcion' => 'Mesa', 'id_tipo_residuo' => 901])['statusCode']);
        }
    }
    public function testDefaultCeroNoAsumeConsentimientoEnDatosAnteriores(): void
    {
        foreach (['incidencia', 'solicitud'] as $table) {
            $this->db->exec("INSERT INTO `$table` (descripcion) VALUES ('Registro sin consentimiento explícito')");
            $id = $this->db->lastInsertId();
            $query = $this->db->prepare("SELECT consentimiento_whatsapp FROM `$table` WHERE id_$table = ?");
            $query->execute([$id]); $this->assertSame(0, (int)$query->fetchColumn());
        }
    }
}
