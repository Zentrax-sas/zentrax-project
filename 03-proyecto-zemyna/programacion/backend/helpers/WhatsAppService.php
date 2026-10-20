<?php

final class WhatsAppService
{
    private $transport;
    private $logger;

    public function __construct(?callable $transport = null, ?callable $logger = null)
    {
        $this->transport = $transport ?? fn (string $url, array $headers, string $body): array => $this->request($url, $headers, $body);
        $this->logger = $logger ?? static function (array $entry): void {
            error_log('Zemyna WhatsApp: ' . json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        };
    }

    public static function normalizarTelefono(string $telefono): ?string
    {
        $telefono = trim($telefono);
        if ($telefono === '' || preg_match('/^\+?[0-9().\s-]+$/', $telefono) !== 1) return null;

        $international = str_starts_with($telefono, '+');
        $digits = preg_replace('/\D+/', '', $telefono);
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $international = true;
        }

        if (str_starts_with($digits, '598')) {
            return preg_match('/^5989\d{7}$/', $digits) === 1 ? $digits : null;
        }

        if (preg_match('/^09\d{7}$/', $digits) === 1) {
            return '598' . substr($digits, 1);
        }
        if (preg_match('/^9\d{7}$/', $digits) === 1) {
            return '598' . $digits;
        }

        if ($international && preg_match('/^[1-9]\d{7,14}$/', $digits) === 1) {
            return $digits;
        }

        return null;
    }

    public function enviarConfirmacionSolicitud(string $telefono, array $solicitud): array
    {
        $trackingNumber = preg_match('/^[A-Z0-9-]{1,32}$/', (string)($solicitud['tracking_number'] ?? '')) === 1
            ? (string)$solicitud['tracking_number']
            : 'unknown';

        if (trim($telefono) === '') return $this->resultado(false, 'sin_celular', $trackingNumber);
        $recipient = self::normalizarTelefono($telefono);
        if ($recipient === null) return $this->resultado(false, 'numero_no_normalizable', $trackingNumber);

        $token = trim((string)(getenv('WHATSAPP_ACCESS_TOKEN') ?: ''));
        $phoneNumberId = trim((string)(getenv('WHATSAPP_PHONE_NUMBER_ID') ?: ''));
        $apiVersion = trim((string)(getenv('WHATSAPP_API_VERSION') ?: ''));
        if ($token === '' || preg_match('/^\d+$/', $phoneNumberId) !== 1 || preg_match('/^v\d+\.\d+$/', $apiVersion) !== 1) {
            return $this->resultado(false, 'credenciales_no_configuradas', $trackingNumber);
        }

        $templateName = trim((string)(getenv('WHATSAPP_TEMPLATE_NAME') ?: 'solicitud_retiro_confirmacion'));
        $language = trim((string)(getenv('WHATSAPP_TEMPLATE_LANGUAGE') ?: 'es_UY'));
        if (preg_match('/^[a-z0-9_]{1,128}$/', $templateName) !== 1 || preg_match('/^[a-z]{2}_[A-Z]{2}$/', $language) !== 1) {
            return $this->resultado(false, 'template_no_configurado', $trackingNumber);
        }

        $templateParameters = [
            (string)($solicitud['tracking_number'] ?? ''),
            (string)($solicitud['tipo_residuo'] ?? ''),
            (string)($solicitud['estado'] ?? ''),
            (string)($solicitud['direccion'] ?? ''),
        ];
        foreach ($templateParameters as $parameter) {
            if ($parameter === '') return $this->resultado(false, 'datos_incompletos', $trackingNumber);
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $language],
                'components' => [[
                    'type' => 'body',
                    'parameters' => array_map(static fn (string $text): array => ['type' => 'text', 'text' => $text], $templateParameters),
                ]],
            ],
        ];

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $response = ($this->transport)(
                "https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/messages",
                ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json'],
                $body
            );
            $decoded = json_decode((string)($response['body'] ?? ''), true);
            $sent = (int)($response['status'] ?? 0) >= 200
                && (int)($response['status'] ?? 0) < 300
                && is_array($decoded)
                && is_string($decoded['messages'][0]['id'] ?? null)
                && $decoded['messages'][0]['id'] !== '';
            return $this->resultado($sent, $sent ? 'enviado' : 'proveedor_no_confirmo', $trackingNumber);
        } catch (Throwable $exception) {
            return $this->resultado(false, 'error_de_transporte', $trackingNumber);
        }
    }

    private function request(string $url, array $headers, string $body): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) $headerLines[] = $name . ': ' . $value;
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headerLines),
            'content' => $body,
            'timeout' => 15,
            'ignore_errors' => true,
        ]]);
        $responseBody = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches) === 1) $status = (int)$matches[1];
        }
        return ['status' => $status, 'body' => is_string($responseBody) ? $responseBody : ''];
    }

    private function resultado(bool $enviado, string $estado, string $trackingNumber): array
    {
        $entry = [
            'timestamp' => gmdate(DATE_ATOM),
            'event' => 'solicitud_retiro_whatsapp',
            'tracking_number' => $trackingNumber,
            'result' => $estado,
        ];
        try {
            ($this->logger)($entry);
        } catch (Throwable $exception) {
            error_log('Zemyna WhatsApp: no se pudo registrar el resultado de notificación.');
        }
        return ['enviado' => $enviado, 'estado' => $estado];
    }
}