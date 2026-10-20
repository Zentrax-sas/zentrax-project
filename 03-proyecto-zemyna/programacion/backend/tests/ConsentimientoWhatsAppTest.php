<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../helpers/ConsentimientoWhatsApp.php';

class ConsentimientoWhatsAppTest extends TestCase
{
    public function testSoloAceptaConsentimientoExplicito(): void
    {
        foreach ([true, 1, '1'] as $value) $this->assertTrue(ConsentimientoWhatsApp::leer($value));
        foreach ([false, 0, '0'] as $value) $this->assertFalse(ConsentimientoWhatsApp::leer($value));
        foreach (['false', 'true', 'sí', '', 2, [], null] as $value) $this->assertNull(ConsentimientoWhatsApp::leer($value));
    }
}
