<?php
require_once __DIR__ . '/../models/Solicitud.php';
require_once __DIR__ . '/../helpers/FotoStorage.php';
require_once __DIR__ . '/../helpers/ConsentimientoWhatsApp.php';

class SolicitudRetiroController {
    private $solicitud;

    // Compatibilidad con clientes anteriores; el catálogo móvil procede de la BD.
    private const TIPOS_RESIDUO = [
        'Mueble' => 'Residuos voluminosos',
        'Electrodoméstico' => 'Electrónicos',
        'Poda' => 'Orgánico',
        'Escombro liviano' => 'Escombros',
        'Residuo voluminoso' => 'Residuos voluminosos',
        'Otro' => 'Otros',
    ];

    public function __construct($db) {
        $this->solicitud = new Solicitud($db);
    }

    private function generateTrackingNumber(array $attempted): string {
        $base = hexdec(substr(bin2hex(random_bytes(3)), 0, 5));
        for ($offset = 0; $offset <= count($attempted); $offset++) {
            $code = strtoupper(str_pad(dechex(($base + $offset) % 0x100000), 5, '0', STR_PAD_LEFT));
            $trackingNumber = 'RET-' . date('Y') . '-' . $code;
            if (!isset($attempted[$trackingNumber])) return $trackingNumber;
        }
        throw new RuntimeException('No se pudo generar un tracking único.');
    }

    private function isTrackingDuplicate(PDOException $exception): bool {
        $errorInfo = $exception->errorInfo ?? null;
        return is_array($errorInfo)
            && (string)($errorInfo[0] ?? '') === '23000'
            && (int)($errorInfo[1] ?? 0) === 1062;
    }

    public function crear(array $data, ?string $fotoUrl = null): array {
        $tipoValue = $data['tipo_residuo'] ?? null;
        $usaId = array_key_exists('id_tipo_residuo', $data);
        $idValue = $data['id_tipo_residuo'] ?? null;
        $idTipo = (is_int($idValue) || is_string($idValue))
            ? filter_var($idValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $direccionValue = $data['direccion'] ?? null;
        $descripcionValue = $data['descripcion'] ?? null;
        $celularValue = $data['celular'] ?? '';
        $tipo = is_string($tipoValue) ? trim($tipoValue) : '';
        $direccion = is_string($direccionValue) ? trim($direccionValue) : '';
        $descripcion = is_string($descripcionValue) ? trim($descripcionValue) : '';
        $celular = is_string($celularValue) ? trim($celularValue) : '';
        $digits = preg_replace('/\D+/', '', $celular);
        $errors = [];
        $calle = $data['calle'] ?? null; $referencia = $data['numero_referencia'] ?? null;
        $lat = $data['latitud'] ?? null; $lon = $data['longitud'] ?? null;
        if (!is_string($calle) || trim($calle) === '' || mb_strlen($calle) > 150) $errors['calle'] = 'La calle es obligatoria y admite hasta 150 caracteres.';
        if (!is_string($referencia) || trim($referencia) === '' || mb_strlen($referencia) > 150) $errors['numero_referencia'] = 'Ingresá el número o referencia del retiro.';
        if (!is_numeric($lat) || !is_numeric($lon) || !is_finite((float)$lat) || !is_finite((float)$lon) || abs((float)$lat) > 85 || abs((float)$lon) > 180 || ((float)$lat === 0.0 && (float)$lon === 0.0)) {
            $errors['ubicacion'] = 'Confirmá una ubicación válida para el retiro.';
        }
        $consentimiento = ConsentimientoWhatsApp::leer($data['consentimiento_whatsapp'] ?? false);
        if ($consentimiento === null) $errors['consentimiento_whatsapp'] = 'El consentimiento WhatsApp debe ser booleano o 0/1.';
        if ($consentimiento === true && $celular === '') {
            $errors['celular'] = 'Ingresá un celular para recibir actualizaciones por WhatsApp.';
        }

        if ($usaId ? $idTipo === false : (!is_string($tipoValue) || !array_key_exists($tipo, self::TIPOS_RESIDUO))) {
            $errors[$usaId ? 'id_tipo_residuo' : 'tipo_residuo'] = 'El tipo de residuo no es válido.';
        }
        if ($direccion === '') $errors['direccion'] = 'La dirección es obligatoria.';
        elseif (mb_strlen($direccion) > 150) $errors['direccion'] = 'La dirección no puede superar los 150 caracteres.';
        if ($descripcion === '') $errors['descripcion'] = 'La descripción es obligatoria.';
        elseif (mb_strlen($descripcion) > 220) $errors['descripcion'] = 'La descripción no puede superar los 220 caracteres.';
        if (!is_string($celularValue) || ($celular !== '' && (strlen($digits) < 8 || mb_strlen($celular) > 20))) {
            $errors['celular'] = 'El celular debe contener al menos 8 dígitos y no superar los 20 caracteres.';
        }
        if ($fotoUrl !== null && (!FotoStorage::isSafeFileName($fotoUrl) || !str_starts_with($fotoUrl, 'retiro_'))) {
            $errors['foto'] = 'La referencia de la foto no es válida.';
        }

        if ($errors) return $this->failure(400, 'Datos incompletos o inválidos.', $errors);

        try {
            if ($usaId) {
                $registroTipo = $this->solicitud->tipoResiduoPorId($idTipo);
                if (!$registroTipo) return $this->failure(400, 'Datos incompletos o inválidos.', [
                    'id_tipo_residuo' => 'El tipo de residuo no es válido.',
                ]);
                $tipo = $registroTipo['nombre'];
            } else {
                $idTipo = $this->solicitud->tipoResiduoIdPorNombre(self::TIPOS_RESIDUO[$tipo]);
            }
            if (!$idTipo) return $this->failure(500, 'No se pudo clasificar el tipo de residuo.');

            $this->solicitud->fecha = date('Y-m-d H:i:s');
            $this->solicitud->descripcion = $descripcion;
            $this->solicitud->direccion = $direccion;
            $this->solicitud->estado = 'Pendiente';
            $this->solicitud->id_tipo_residuo = $idTipo;
            $this->solicitud->telefono = $celular === '' ? null : $celular;
            $this->solicitud->consentimiento_whatsapp = $consentimiento;
            $this->solicitud->tipo_solicitud = 'Gran volumen';
            $this->solicitud->tipo_residuo_solicitado = $tipo;
            $this->solicitud->foto_url = $fotoUrl;
            $this->solicitud->calle = trim($calle);
            $this->solicitud->numero_referencia = trim($referencia);
            $this->solicitud->latitud = (float)$lat;
            $this->solicitud->longitud = (float)$lon;

            $attempted = [];
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $this->solicitud->tracking_number = $this->generateTrackingNumber($attempted);
                $attempted[$this->solicitud->tracking_number] = true;
                try {
                    $created = $this->solicitud->createRetiroMovil();
                } catch (PDOException $exception) {
                    if ($this->isTrackingDuplicate($exception) && $attempt < 3) continue;
                    return $this->failure(500, 'Error al registrar la solicitud.');
                }
                if (!$created) return $this->failure(500, 'Error al registrar la solicitud.');

                return [
                    'success' => true,
                    'data' => [
                        'id_solicitud' => (int)$this->solicitud->id_solicitud,
                        'tracking_number' => $this->solicitud->tracking_number,
                        'tipo_residuo' => $tipo,
                        'direccion' => $direccion,
                        'calle' => $this->solicitud->calle,
                        'numero_referencia' => $this->solicitud->numero_referencia,
                        'latitud' => $this->solicitud->latitud,
                        'longitud' => $this->solicitud->longitud,
                        'estado' => $this->solicitud->estado,
                        'fecha' => $this->solicitud->fecha,
                        'celular' => $this->solicitud->telefono,
                        'consentimiento_whatsapp' => $this->solicitud->consentimiento_whatsapp,
                    ],
                    'message' => 'Solicitud de retiro registrada correctamente.',
                    'errors' => [],
                    'statusCode' => 201,
                ];
            }
        } catch (Throwable $exception) {
            return $this->failure(500, 'Error al registrar la solicitud.');
        }

        return $this->failure(500, 'Error al registrar la solicitud.');
    }

    private function failure(int $statusCode, string $message, array $errors = []): array {
        return [
            'success' => false,
            'data' => null,
            'message' => $message,
            'errors' => $errors,
            'statusCode' => $statusCode,
        ];
    }
}
