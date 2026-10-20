# Novedades públicas del móvil

`GET /backend/api/novedades.php`, sin parámetros ni autenticación. Solo lectura;
otros métodos de negocio responden 405. OPTIONS lo resuelve el bootstrap existente.

Origen: las últimas 20 filas de `incidencia` asociadas a un `contenedor` activo,
con `fecha_reporte <= CURRENT_TIMESTAMP`, ordenadas por `fecha_reporte DESC` e
`id_incidencia DESC`. No incluye incidencias de rutas, solicitudes ni avisos inventados.

Respuesta: `{success, data, message, statusCode}`. Cada elemento de `data` contiene:
- `id`: identificador de incidencia, para distinguir tarjetas.
- `tipo`: `Incidencia`.
- `titulo`: texto fijo derivado de las cuatro categorías admitidas por el registro
  de incidencias; para categorías históricas desconocidas, `Incidencia reportada en contenedor`.
- `fecha`: `incidencia.fecha_reporte`, DATETIME local sin zona (`YYYY-MM-DD HH:mm:ss`).
- `direccion`: dirección pública del contenedor asociado; no domicilio del vecino.
- `estado`: estado actual almacenado: `Pendiente`, `En Proceso` o `Resuelta`.

No representa un historial de cambios: no hay fecha de resolución ni fecha de cambio
de estado en esta fuente. La dirección es la actual del contenedor. No hay push,
polling ni eventos en tiempo real. El móvil consulta al entrar y al pulsar Actualizar.

Privacidad: consulta SQL de columnas explícitas, sin descripciones libres, fotos,
tracking, contactos, usuarios, teléfonos ni emails. El controlador construye una
lista permitida de campos y no publica texto libre de categorías ni estados desconocidos.
Los errores no incluyen detalles SQL. No se amplían permisos del listado administrativo.

Sin filas: HTTP 200 con `data: []`. Fallo de conexión/consulta: HTTP 500 con
`success: false`. No hay paginación; el máximo es 20. Los datos de desarrollo/demo
presentes en la BD se mostrarán como registros del sistema: no existe marca de publicación
ni de moderación en el esquema actual.

## Validación local — 27/09/2026

Windows, XAMPP/PHP 8.2.12 y móvil Angular:
- `npm test -- --watch=false`: 96 pruebas aprobadas.
- `npm run build`: correcto; advertencias de estilos existentes fuera de Novedades.
- `npx eslint src/app/novedades/*.ts src/app/novedades/*.html`: correcto.
- `npm run lint`: falla en `incidencia-generada.page.ts:47`, regla `prefer-inject`, ajena a Novedades.
- PHPUnit 10.5.64: `NovedadTest.php`, 6 pruebas y 26 aserciones aprobadas.
  Se usó la instalación Composer ya disponible en
  `zemyna-mobile/tmp/reverse/backend/vendor/phpunit/phpunit/phpunit`, con
  `--no-configuration --bootstrap .../vendor/autoload.php backend/tests/NovedadTest.php`.
- Suite PHP completa (`backend/tests`): 501 pruebas, un fallo existente en
  `DockerComposeContractTest` por las variables WhatsApp de `.env.example`.
- `php -l` sobre los archivos PHP del backend: sin errores de sintaxis.
- HTTP local: GET 200, 20 filas ordenadas y solo campos permitidos; POST 405.

Pendiente: revisión visual en dispositivo físico de 320–430 px.
