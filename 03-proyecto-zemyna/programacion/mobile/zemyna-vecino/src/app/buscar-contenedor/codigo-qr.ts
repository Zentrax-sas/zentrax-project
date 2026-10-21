// El QR es un identificador, nunca una dirección a la que navegar.
export function codigoDesdeQr(valor: string): string | null {
  if (valor.length > 2048) return null;
  let codigo = valor.trim();
  if (/^https?:\/\//i.test(codigo)) {
    try {
      const url = new URL(codigo);
      if (url.username || url.password) return null;
      const valores = [...url.searchParams.getAll('codigo'), ...url.searchParams.getAll('codigo_contenedor')];
      if (valores.length !== 1) return null;
      codigo = valores[0];
    } catch { return null; }
  }
  // codigo es VARCHAR(20) en el esquema actual. No acepta SQL, rutas ni IDs ambiguos.
  return /^(?=.*[a-z])[a-z0-9][a-z0-9_-]{1,19}$/i.test(codigo) ? codigo.toUpperCase() : null;
}
