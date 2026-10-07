# F1: alta pública y evidencia fotográfica (V20)

La migración `migration_v20_incidencia_upload_token.sql` debe aplicarse antes de
habilitar este código en una instalación existente. No hace backfill, no cambia
incidencias históricas ni les concede tokens. `schema.sql` incluye la tabla para
instalaciones nuevas; es un instalador destructivo y no reemplaza la migración.

## Alta de incidencia

`POST incidencias.php` permite `descripcion`, `tipo_problema`, `id_contenedor` o
`id_ruta`, y `direccion` por compatibilidad. No cambia la semántica pública de
ubicación: las coordenadas del cliente público no se guardan.

El servidor ignora estado, prioridad, fecha, ID, tracking, usuario, cuadrilla,
resolución y cualquier autor/fecha operativa enviados por cliente. Guarda
`Pendiente`, `Media`, hora de `America/Montevideo`, usuario y cuadrilla NULL,
resolución NULL y tracking `INC` generado en servidor.

`POST incidencias.php?view=crew` conserva el permiso de alta existente, la
identidad de sesión y la validación de contenedor activo y/o ubicación propia.
También empieza Pendiente/Media, con fecha servidor y cuadrilla NULL. No se
agrega UI de fotos al reporte de operario.

El HTTP 201 conserva `data.id_incidencia` y `data.tracking_number`, y agrega
`data.upload_token` (64 caracteres hexadecimales) y `data.upload_expires_at`
(`Y-m-d H:i:s`, America/Montevideo). La respuesta lleva `Cache-Control: no-store`.
Incidencia y concesión se confirman en una misma transacción. Si falla la
concesión, no queda una incidencia creada parcialmente.

## Solicitud pública

`POST solicitud.php` conserva captcha, descripción, dirección, contacto,
tipo de solicitud. Desde F6.1/V21 exige residuo explícito del catálogo, sin inferencia. Ignora estado, fecha e
identidad/tracking del cliente: fecha servidor, estado Pendiente y tracking REF.
El ID de residuo debe ser un entero positivo que exista en `tipo_residuo`;
un ID inexistente devuelve 400. No cambia los estados posteriores ni F6.

## Capability de fotografía

Cada nueva incidencia recibe una concesión de 32 bytes de entropía mediante
`random_bytes(32)`, válida durante diez minutos. La BD sólo guarda SHA-256
binario de 32 bytes, incidencia, emisor opcional, creación, expiración, consumo
y referencia a foto. El secreto no se guarda en BD, logs, URL, tracking,
comprobantes ni almacenamiento frontend. Se mantiene en una variable local
sólo durante el intento de carga. Tracking nunca autoriza una subida.

`POST foto.php` recibe multipart con `foto`, `id_incidencia` y `upload_token`.
El recurso autorizado deriva del token, y debe coincidir con el ID recibido.
El token público es una capability anónima. El token crew exige además la misma
sesión activa, usuario activo y permiso actual `incidencia.adjuntar_evidencia`
en OPERACIONES, INSPECCION o PUNTOS_Y_DESTINOS, consultados en BD; no usa roles
cacheados ni el bypass TI. No se renueva por ID o tracking.

Se validan error de carga, tamaño declarado y real (máximo 5 MiB), MIME real
JPG/PNG/WEBP y extensión correspondiente. El archivo conserva nombre aleatorio,
directorio seguro y `move_uploaded_file`. Dentro de una transacción se bloquea
primero la incidencia y después el token, se revalida autorización y vigencia,
se guarda el archivo, se inserta foto y se consume la concesión antes del commit.
La expiración vuelve a comprobarse al consumirla.

Sólo una carga exitosa por concesión. Dos cargas concurrentes sólo pueden
confirmar una foto; replay devuelve **409**. Token ausente/malformado devuelve
400; desconocido, ajeno, expirado, incidencia eliminada o identidad/permiso
inválido, 403. Tamaño inválido devuelve 413; fallo de almacenamiento/persistencia,
500. Ningún error previo consume el token. Fallo después de mover el archivo
revierte la BD y elimina compensatoriamente el archivo. Filesystem y BD no
comparten ACID; una terminación abrupta del proceso puede dejar un archivo
huérfano, nunca una concesión reutilizada después de un commit confirmado.
Borrar una foto no restablece el token consumido.

El frontend conserva el tracking si falla la foto, muestra el error y no crea
otra incidencia automáticamente. Una respuesta de subida perdida puede dejar
una foto ya guardada: el siguiente intento devuelve 409 si hubo commit.

## Evidencia histórica y vía alternativa

`GET foto.php?id=...` y `GET fotos.php` mantienen lectura y permisos existentes,
incluidas rutas históricas normalizadas por `FotoStorage`. `DELETE fotos.php`
mantiene el permiso existente de modificación. POST/PUT de `fotos.php` devuelven
405 antes de conectar a BD; el controlador ya no tiene métodos de asociación
o reasociación JSON. La única asociación expuesta es el multipart autorizado.

## Pruebas aisladas

`F1SecurityTest` usa SQLite en memoria. `F1SecurityMariaDbTest`, habilitado con
`ZEMYNA_F1_MARIADB=1`, crea una BD aleatoria `zemyna_f1_test_<12hex>`, ejecuta
el esquema real, contratos, fallos y una carrera con conexiones independientes,
y elimina exclusivamente esa BD. No admite un destino externo. Las pruebas HTTP
sirven copias del código, fotos y sesiones exclusivamente desde TEMP.
`F1EndpointContractTest` comprueba HTTP 405 sin una BD existente.
Frontend: `node --test tests/*.test.cjs`. No usar la BD habitual para estas pruebas.
