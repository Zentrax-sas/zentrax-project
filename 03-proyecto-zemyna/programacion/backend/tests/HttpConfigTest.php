<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../config/http.php';

final class HttpConfigTest extends TestCase
{
    public function testExplicitOriginsAndWebProxy(): void
    {
        foreach (['https://localhost', 'http://localhost:4200', 'http://127.0.0.1:8100',
            'http://localhost:8101', 'http://127.0.0.1:8101'] as $origin) {
            self::assertTrue(zemynaOriginAllowed(['HTTP_ORIGIN' => $origin]));
        }
        self::assertTrue(zemynaOriginAllowed([]));
        self::assertTrue(zemynaOriginAllowed(['HTTP_HOST' => 'demo.test', 'HTTP_ORIGIN' => 'http://demo.test']));
        foreach (['null', 'https://localhost.evil.test', 'https://evil.test',
            'http://localhost:8102', 'http://localhost:8101.evil.test'] as $origin) {
            self::assertFalse(zemynaOriginAllowed(['HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => $origin]));
        }
    }

    public function testHttpCannotIssueCrossSiteSecureCookie(): void
    {
        $options = zemynaSessionCookieOptions(['HTTP_ORIGIN' => ZEMYNA_CAPACITOR_ORIGIN]);
        self::assertSame('Lax', $options['samesite']);
        self::assertFalse($options['secure']);
        self::assertTrue($options['httponly']);
        self::assertSame('', $options['domain']);
        self::assertSame('/', $options['path']);
    }

    public function testHttpsAllowsCapacitorAndPreservesWebPolicy(): void
    {
        $native = zemynaSessionCookieOptions(['HTTPS' => 'on', 'HTTP_ORIGIN' => ZEMYNA_CAPACITOR_ORIGIN]);
        self::assertTrue($native['secure']);
        self::assertSame('None', $native['samesite']);
        self::assertSame('Lax', zemynaSessionCookieOptions(['HTTPS' => 'on'])['samesite']);
        self::assertFalse(zemynaRequestIsHttps(['HTTP_X_FORWARDED_PROTO' => 'https']));
    }
}
