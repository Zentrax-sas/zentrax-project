<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/WhatsAppService.php';

class WhatsAppServiceTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('WHATSAPP_ACCESS_TOKEN=test-access-token');
        putenv('WHATSAPP_PHONE_NUMBER_ID=123456789');
        putenv('WHATSAPP_API_VERSION=v23.0');
        putenv('WHATSAPP_TEMPLATE_NAME=solicitud_retiro_confirmacion');
        putenv('WHATSAPP_TEMPLATE_LANGUAGE=es_UY');
    }

    protected function tearDown(): void
    {
        foreach (['WHATSAPP_ACCESS_TOKEN', 'WHATSAPP_PHONE_NUMBER_ID', 'WHATSAPP_API_VERSION', 'WHATSAPP_TEMPLATE_NAME', 'WHATSAPP_TEMPLATE_LANGUAGE'] as $key) {
            putenv($key);
        }
        parent::tearDown();
    }

    public function testNormalizaCelularUruguayo(): void
    {
        $this->assertSame('59899123456', WhatsAppService::normalizarTelefono('099123456'));
        $this->assertSame('59899123456', WhatsAppService::normalizarTelefono('+598 99 123 456'));
        $this->assertSame('59899123456', WhatsAppService::normalizarTelefono('00598 99 123 456'));
        $this->assertSame('59899123456', WhatsAppService::normalizarTelefono('99123456'));
        $this->assertNull(WhatsAppService::normalizarTelefono('0991234abc'));
        $this->assertNull(WhatsAppService::normalizarTelefono('099+123456'));
        $this->assertNull(WhatsAppService::normalizarTelefono('12345'));
    }

    public function testEnviaTemplateACloudApiConParametrosEsperados(): void
    {
        $request = null;
        $service = new WhatsAppService(function (string $url, array $headers, string $body) use (&$request): array {
            $request = [$url, $headers, json_decode($body, true)];
            return ['status' => 200, 'body' => '{"messages":[{"id":"wamid.test"}]}'];
        }, static function (): void {});

        $result = $service->enviarConfirmacionSolicitud('099123456', $this->solicitud());

        $this->assertTrue($result['enviado']);
        $this->assertSame('https://graph.facebook.com/v23.0/123456789/messages', $request[0]);
        $this->assertSame('Bearer test-access-token', $request[1]['Authorization']);
        $this->assertSame('59899123456', $request[2]['to']);
        $this->assertSame('solicitud_retiro_confirmacion', $request[2]['template']['name']);
        $this->assertSame(['RET-2026-ABCDE', 'Mueble', 'Pendiente', 'Av. Rivera 123'], array_column($request[2]['template']['components'][0]['parameters'], 'text'));
    }

    public function testSinCelularNoLlamaAlTransporte(): void
    {
        $calls = 0;
        $service = new WhatsAppService(static function () use (&$calls): array { $calls++; return []; }, static function (): void {});

        $result = $service->enviarConfirmacionSolicitud('', $this->solicitud());

        $this->assertFalse($result['enviado']);
        $this->assertSame('sin_celular', $result['estado']);
        $this->assertSame(0, $calls);
    }

    public function testCredencialesAusentesNoLlamaAlTransporte(): void
    {
        putenv('WHATSAPP_ACCESS_TOKEN');
        $calls = 0;
        $service = new WhatsAppService(static function () use (&$calls): array { $calls++; return []; }, static function (): void {});

        $result = $service->enviarConfirmacionSolicitud('099123456', $this->solicitud());

        $this->assertFalse($result['enviado']);
        $this->assertSame('credenciales_no_configuradas', $result['estado']);
        $this->assertSame(0, $calls);
    }

    public function testRespuestaFallidaDeMetaNoSeConsideraEnvio(): void
    {
        $service = new WhatsAppService(static fn (): array => ['status' => 500, 'body' => '{"error":"hidden"}'], static function (): void {});
        $result = $service->enviarConfirmacionSolicitud('099123456', $this->solicitud());

        $this->assertFalse($result['enviado']);
        $this->assertSame('proveedor_no_confirmo', $result['estado']);
    }

    private function solicitud(): array
    {
        return ['tracking_number' => 'RET-2026-ABCDE', 'tipo_residuo' => 'Mueble', 'estado' => 'Pendiente', 'direccion' => 'Av. Rivera 123'];
    }
}