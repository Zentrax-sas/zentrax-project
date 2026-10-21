import { codigoDesdeQr } from './codigo-qr';

describe('Código QR de contenedor', () => {
  it.each([
    ['CTN-001', 'CTN-001'], [' ctn-prueba ', 'CTN-PRUEBA'],
    ['https://zemyna.example/contenedor?codigo=CTN-001', 'CTN-001'],
    ['http://localhost/mapa?codigo_contenedor=ctn-002', 'CTN-002'],
  ])('extrae %s sin navegar a la URL', (entrada, esperado) => {
    expect(codigoDesdeQr(entrada)).toBe(esperado);
  });
  it.each(['', '1', '123', 'CTN 001', '<script>alert(1)</script>', "CTN-001' OR 1=1", 'CTN-%',
    'javascript:alert(1)', 'https://example.org/?id=1', 'https://example.org/?codigo=CTN-001&codigo=CTN-002',
    'https://example.org/?codigo=CTN-001&codigo_contenedor=CTN-002',
    'https://user:pass@example.org/?codigo=CTN-001', 'A'.repeat(21), 'A'.repeat(2049),
  ])('rechaza contenido inválido: %s', entrada => expect(codigoDesdeQr(entrada)).toBeNull());
});
