import { describe, expect, it } from 'vitest';
import { ANDROID_API_BASE_URL } from './api.android.config';
import { API_BASE_URL, resolveApiBaseUrl } from './api.config';

describe('ConfiguraciÃ³n central de API', () => {
  it('conserva la URL web y no exige configuraciÃ³n Android', () => {
    expect(API_BASE_URL).toBe('/backend/api');
    expect(resolveApiBaseUrl(false, 'web', '')).toBe('/backend/api');
    expect(resolveApiBaseUrl(false, 'android', 'https://example.test/api')).toBe('/backend/api');
    expect(resolveApiBaseUrl(true, 'ios', '')).toBe('/backend/api');
  });

  it.each(['http', 'https'])('selecciona la URL Android %s y conserva puerto y subcarpetas', scheme => {
    expect(resolveApiBaseUrl(true, 'android', ` ${scheme}://example.test:8080/proyecto/backend/api/// `))
      .toBe(`${scheme}://example.test:8080/proyecto/backend/api`);
  });

  it('normaliza barras del path sin romper el protocolo', () => {
    const base = resolveApiBaseUrl(true, 'android', 'https://example.test//backend///api/');
    expect(`${base}/incidencias.php`).toBe('https://example.test/backend/api/incidencias.php');
  });

  it.each(['', '/backend/api', '//example.test/api', 'ftp://example.test/api',
    'https://user:pass@example.test/api', 'https://example.test/api?q=1', 'https://example.test/api#x'])
  ('rechaza configuraciÃ³n Android invÃ¡lida: %s', value => {
    expect(() => resolveApiBaseUrl(true, 'android', value)).toThrow();
  });
});
