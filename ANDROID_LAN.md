# Android local: IPv4 estatica persistente (15/10/2026)

Windows T140J2401083459, Wi-Fi Realtek (indice 16), red Andry: IPv4 manual persistente 172.28.57.181/24, gateway y DNS IPv4 172.28.57.19. DHCP IPv4 desactivado. El Samsung SM-A145M no resuelve T140J2401083459.local ni T140J2401083459. La APK instalada conserva su URL HTTP sin recompilar. Uso diario: iniciar Apache/MySQL en XAMPP y abrir Zemyna.

Firewall: Zemyna-LAN-Apache-HTTP-Debug habilitada solo en Private, TCP 80, RemoteAddress LocalSubnet, programa C:\xampp\apache\bin\httpd.exe. Reglas amplias Apache HTTP Server y regla Zemyna-LAN-HTTPS-Debug deshabilitadas. Apache escucha 0.0.0.0:80 y [::]:80; MariaDB permanece 127.0.0.1:3310. No se usa proxy.

Alcance: esta configuracion corresponde a la misma subred 172.28.57.0/24 del hotspot Samsung Andry. No equivale a una reserva DHCP del hotspot; no se comprobo exclusion de esta IP de su pool DHCP. Cambiar la subred del hotspot o conectar Windows a otra LAN queda fuera de esta garantia.

Evidencias de aplicacion administrativa: tmp/fix-lan-admin.log. Prueba desde el WebView Samsung: tmp/lan-physical-results.json. No se modificaron backend, DB, UI ni logica.

## Registro anterior: Apache directo (12/10/2026)

Configuracion vigente: APK debug -> http://172.28.57.181/zentrax-project/03-proyecto-zemyna/programacion/backend/api -> Apache :80 -> MariaDB 127.0.0.1:3310, gestion_residuosfinal.
Basta iniciar Apache y MySQL en XAMPP y abrir el APK actualizado. No requiere Node, certificados ni puerto 8443.

- src/app/api.android.config.ts define la URL LAN; api.config.ts y los servicios Angular conservan los contratos existentes.
- android/app/src/debug/res/xml/zemyna_lan_network_security.xml permite HTTP exclusivamente a esa IP; el resto de cleartext sigue denegado.
- android/app/src/debug/assets/capacitor.config.json habilita CapacitorHttp solo en debug. El transporte nativo incorporado parchea fetch/XMLHttpRequest y conserva PHPSESSID con los atributos originales del backend. No se cambia el origen https://localhost ni se habilita mixed content.
- Release conserva su configuracion y no permite HTTP LAN. La configuracion local no constituye una configuracion de distribucion.
- scripts/start-android-local.ps1 ahora prepara exclusivamente MariaDB y Apache; el .bat existente sigue siendo opcional. No inicia proxy ni prepara TLS/firewall.
- No se modifican backend, DB, endpoints, UI, servicios ni logica de formularios.

La dependencia anterior surgio al cambiar api.android.config.ts a HTTPS:8443 y restringir debug a la CA local. El historial Git movil solo tiene Initial commit y estos archivos estan sin seguimiento: no existe un commit atribuible. La documentacion conservada debajo registra la decision del 01/10/2026: HTTPS solucionaba la cookie de sesion del CAPTCHA de retiro y evitaba mixed content. El rechazo CORS del origen web :8101 era otro problema. Habilitar solo cleartext no conserva automaticamente una cookie Lax en fetch cross-site; ahora lo resuelve el transporte nativo existente.

Referencia del transporte: https://github.com/ionic-team/capacitor/blob/main/core/http.md

## Validacion actual

- 15 tests de api.config.spec.ts y api-services.spec.ts aprobados.
- npm run build y npx cap sync android correctos; advertencias preexistentes SCSS/CommonJS.
- assembleDebug --offline correcto con JDK 21 existente y SDK 36; APK actualizado instalado en Samsung SM-A145M (R58W60ZMXVK), LAN 172.28.57.19.
- Proxy apagado; sin listener 8443. Apache 80 y MariaDB 3310 activos.
- Desde el WebView real https://localhost, CapacitorHttpAndroidInterface.isEnabled() devuelve true. fetch parcheado obtuvo tipos_residuo (200, 10), contenedores/mapa (200, 500) y consulta de incidencia existente (200).
- POST incidencia vacio devuelve 400 de validacion; POST retiro con CAPTCHA correcto y foto deliberadamente invalida devuelve 400 de foto, no de CAPTCHA. Repetirlo devuelve CAPTCHA incorrecto: sesion PHP conservada y uso unico comprobados. Sin nuevos registros en DB. No se certifica una nueva alta completa porque esta prueba no modifica DB.
- Evidencia: tmp/android-https/direct-apache-evidence.json. Apache registra las solicitudes directamente desde 172.28.57.19.

## Registro historico: HTTPS reemplazado

Todo lo que sigue describe la configuracion anterior y no son pasos necesarios actualmente.

# Demo Android HTTPS local â€” cierre funcional

## Estrategia final

APK debug: WebView `https://localhost` â†’
`https://172.28.57.181:8443/zentrax-project/03-proyecto-zemyna/programacion/backend/api`
â†’ proxy TLS Node â†’ Apache por `http://127.0.0.1:80`, conservando la ruta API.
No hay HTTP por la LAN en este recorrido. No se modificaron servicios ni contratos API.

Se eligiÃ³ A, HTTPS local confiable. La CA pÃºblica se incorpora solo en
`android/app/src/debug/res/raw/zemyna_demo_ca.crt`, y Network Security Config
la confÃ­a exclusivamente para `172.28.57.181`, sin subdominios. Las claves privadas
permanecen en `tmp/android-https` (ignorado por Git); nunca se copian al APK.
Certificado del servidor: 90 dÃ­as; CA: 365 dÃ­as. No instalar la CA globalmente.
Para renovar, revisar los certificados y crear nuevo material fuera de los archivos
existentes; actualizar la CA debug si cambia y recompilar el APK.

Se evaluÃ³ B: cambiar solo el esquema WebView a HTTP no vuelve localhost y la IP
el mismo sitio. Cambiar hostname afecta el almacenamiento y las rutas interceptadas
por Capacitor, sin aportar protecciÃ³n al trÃ¡fico LAN HTTP. No se eligiÃ³.
No hace falta C (transporte nativo ni manejo manual del identificador de sesiÃ³n).

`LanDebugActivity` ahora impide mixed content. Cleartext estÃ¡ denegado tambiÃ©n en
debug. No se deshabilita validaciÃ³n TLS. Capacitor 8.5.2 ya habilita cookies de terceros
en su `CapacitorCordovaCookieManager`; no se agrega un bypass de cookies.
Release no incluye la CA ni la actividad debug: necesita un backend HTTPS con
certificado pÃºblico y actualizar la URL antes de distribuir.

## PreparaciÃ³n y ejecuciÃ³n diaria

1. Hacer doble clic en `iniciar-zemyna-local.bat`.
2. Esperar el bloque `ZEMYNA LOCAL LISTA`.
3. Abrir la app Android. El launcher inicia solo los componentes que no estÃ©n activos.

Para crear un acceso directo en el escritorio: clic derecho en el escritorio â†’
**Nuevo â†’ Acceso directo**; como destino pegar
`<carpeta de Vecino>\iniciar-zemyna-local.bat`; asignarle el nombre
`Iniciar Zemyna local` y finalizar.

El launcher usa los iniciadores existentes `C:\xampp\mysql_start.bat` y
`C:\xampp\apache_start.bat`, sin instalar servicios ni cambiar XAMPP. MariaDB
debe conservar `bind-address=127.0.0.1`; se espera el handshake de
`mysqladmin ping` en `127.0.0.1:3310`. Apache se da por listo Ãºnicamente cuando
`http://127.0.0.1/zentrax-project/03-proyecto-zemyna/programacion/backend/api/tipos_residuo.php`
devuelve HTTP 200 y `success=true`. DespuÃ©s valida HTTPS con la CA debug. Los
arranques se hacen en segundo plano; dejÃ¡ abierta la ventana Node del proxy.

Si un servicio no queda listo en 45 segundos, el launcher indica cuÃ¡l fallÃ³,
muestra las Ãºltimas lÃ­neas de su log de XAMPP y no continÃºa al siguiente paso.
TambiÃ©n se detiene si el puerto esperado pertenece a un proceso ajeno. No cambia
reglas de firewall; si la regla 8443 no existe o no coincide, hay que revisarla
una sola vez con `scripts/android-https-firewall.ps1` como administrador.

Para ejecutar manualmente desde PowerShell en la raÃ­z mÃ³vil:

```powershell
node scripts/prepare-android-https.cjs
node scripts/android-https.cjs
```

El proxy manual tambiÃ©n debe permanecer activo durante la demo. Usa Node instalado
y OpenSSL de XAMPP para preparar los certificados, sin nuevas dependencias.
El proxy se limita a siete endpoints de la app y GET/POST/OPTIONS; no publica archivos,
login administrativo, phpMyAdmin ni bases de datos. Solo escucha en la IP Wi-Fi
indicada y rechaza clientes fuera de `172.28.57.0/24`.

En PowerShell **como administrador**, una sola vez:

```powershell
./scripts/android-https-firewall.ps1
```

Regla prevista: `Zemyna-LAN-HTTPS-Debug`, programa Node exacto, TCP 8443,
interfaz Wi-Fi, direcciÃ³n local 172.28.57.181, remoto 172.28.57.0/24.
No modifica reglas de Apache, MariaDB, phpMyAdmin, UDP, NAT ni router.
El script rechaza sobrescribir una regla existente y rechaza una IP/subred distinta.
Cambiar de red exige revisar URL, certificado, trust config, proxy y regla.
Regla aplicada mediante elevaciÃ³n UAC y verificada: Enabled=True, Inbound/Allow,
Node `C:\Program Files\nodejs\node.exe`, TCP 8443, Wi-Fi, IP y subred indicadas.

```powershell
npm run build
npx cap sync android
# Cuando exista JDK 21 y Android SDK 36:
cd android
./gradlew.bat assembleDebug
```

Salida esperada al compilar: `android/app/build/outputs/apk/debug/app-debug.apk`.
No confundir ruta esperada con un APK existente.

## CORS y sesiÃ³n

Backend: lista exacta `https://localhost`, HTTP localhost/127.0.0.1 en
4200, 8100 y 8101; ademÃ¡s conserva mismo origen del servidor y clientes sin Origin.
OrÃ­genes desconocidos â†’ 403 antes de sesiÃ³n. `Access-Control-Allow-Origin` explÃ­cito,
`Access-Control-Allow-Credentials: true`, `Vary: Origin`; OPTIONS â†’ 204 sin sesiÃ³n.
Sin wildcard. MÃ©todos/headers CORS del backend conservados.

PHP por HTTP conserva cookie host-only, Path=/, HttpOnly, SameSite=Lax, sin Secure.
HTTPS directo conserva la polÃ­tica PHP existente: Secure; None para Capacitor y
Lax para web. El proxy HTTPS reemplaza Ãºnicamente los atributos de PHPSESSID al
salir: conserva su valor opaco y aplica Path=/; HttpOnly; Secure; SameSite=None
para `https://localhost`, Lax para otros orÃ­genes. No introduce tokens ni sesiones
alternativas. No confÃ­a en X-Forwarded-Proto: Apache recibe HTTP loopback real.
El proxy no retransmite cabeceras Forwarded del cliente ni cambia Origin/CORS.

## Causa del reporte observado

Apache registrÃ³ el 29/09/2026 20:06:12 un POST a incidencias.php desde
`http://localhost:8101/captcha-reporte` con **403, 50 bytes**.
Reproducido el 01/10 con `Origin: http://localhost:8101`:
`{"success":false,"message":"Origen no permitido."}`; sin Set-Cookie ni permiso CORS.
El rechazo sucedÃ­a antes de crear incidencia: no era foto, parsing ni navegaciÃ³n.
El puerto 8101 no estaba permitido. Se aÃ±adieron solo localhost/127.0.0.1:8101.
DespuÃ©s del cambio, el POST con `{}` devuelve 400 de validaciÃ³n de datos, ya no 403;
no se insertaron registros para verificarlo.

El 30/09 a las 14:44:26 Apache tambiÃ©n registra POST **201** desde 8100 y seguimiento
**200** para INC-2026-C04B7. No se dispone del body original del POST histÃ³rico.
Su consulta actual con detalle devuelve 200 y datos vÃ¡lidos.

El CAPTCHA de **reporte** actual es una suma generada y validada en cliente; el
endpoint de incidencias no valida un CAPTCHA PHP y su servicio no requiere cookies.
Se conserva ese contrato: no presentar la prueba de sesiÃ³n como protecciÃ³n CAPTCHA
server-side del reporte. **Retiro** sÃ­ usa captcha.php, PHPSESSID y respuesta de un
solo uso. HTTPS resuelve la cookie de retiro; CORS 8101 resuelve el rechazo observado.

## ValidaciÃ³n del 01/10/2026

- `node --test scripts/android-https.test.cjs scripts/android-location.test.cjs`:
  4 pruebas correctas. TLS con CA y nombre verificados, certificado limitado a IP,
  CORS permitido/rechazado y credentials, OPTIONS sin cookie, sesiÃ³n conservada,
  retiro sin cookie rechazado, CAPTCHA aceptado con cookie y replay rechazado,
  web HTTP preservada, INC existente 200 y RET inexistente 404; ruta ajena 404.
- `tmp/android-https/http-evidence.json`: URL, mÃ©todo, status, body, Origin y
  cookies con hash consistente en lugar de valores reutilizables.
- `node scripts/android-browser-session.cjs`: correcto en Chromium real, origen
  `https://localhost`, contexto seguro, CORS, cookie Secure/HttpOnly/None recibida
  y enviada automÃ¡ticamente, CAPTCHA aceptado y replay rechazado. Evidencia en
  `tmp/android-https/browser-evidence.json`. Perfil temporal, confianza Ãºnicamente
  en el SPKI del certificado demo, sin desactivar validaciÃ³n TLS globalmente.
  Chrome inicialmente bloqueÃ³ `LocalNetworkAccessPermissionDenied`; se concedieron
  `localNetwork` y `loopbackNetwork` solo a ese origen de prueba. No se cambiaron
  flags de protecciÃ³n de red. Esto no certifica Android WebView.
  La app apunta a SDK 36; el permiso obligatorio Android ACCESS_LOCAL_NETWORK
  aplica al target 37+, segÃºn la documentaciÃ³n de Android. No se agregaron permisos.
- CAPTCHA â†’ incidencia: misma sesiÃ³n PHP en POST invÃ¡lido, sin nueva cookie;
  este endpoint no consume CAPTCHA, como se explica arriba.
- CAPTCHA â†’ retiro: foto invÃ¡lida detiene la peticiÃ³n **despuÃ©s** del CAPTCHA y
  **antes** de archivos/BD. No se certificÃ³ INSERT real ni foto real en telÃ©fono.
- PHP HTTP existente: 40 comprobaciones correctas por localhost.
- Sintaxis de los 116 archivos PHP del backend: correcta.
- PHPUnit: 74 pruebas, 299 assertions, usando el vendor existente en
  `tmp/reverse/backend`, sin instalar dependencias; filtros HttpConfigTest,
  IncidenciaPublicTrackingTest, SolicitudRetiroControllerTest, SeguimientoTest,
  PublicFeedbackContractTest y FotoStorageTest. Persistencia simulada, sin BD real.
- Build correcto, solo advertencias SCSS existentes. `cap sync android` correcto.
- Suite mÃ³vil: 145/147 correctos inicialmente; dos timeouts en identificaciÃ³n GPS.
  RepeticiÃ³n aislada de `identificacion-previa.spec.ts`: 17/17 correctos sin cambiar
  implementaciÃ³n, tests ni lÃ­mites de tiempo. El resto de los 21 archivos pasÃ³.
- Sin SDK/local.properties detectados; Java disponible 22.0.2, no JDK 21.
  No se compila APK ni se instala tooling en esta tarea.

## RevalidaciÃ³n del 02/10/2026

La soluciÃ³n HTTPS y la autorizaciÃ³n CORS de 8101 ya estaban implementadas al
iniciar esta revisiÃ³n. No fue necesario modificar cÃ³digo funcional. El proxy
estaba apagado: la primera conexiÃ³n devolviÃ³ `ECONNREFUSED 172.28.57.181:8443`.
Se iniciÃ³ con `node scripts/android-https.cjs`; debe permanecer activo durante
la demo. Wi-Fi conserva `172.28.57.181/24` y la regla existente estÃ¡ habilitada
para entrada, limitada a esa direcciÃ³n y la subred `172.28.57.0/24`.

- `npm test -- --watch=false`: 158/158 tests en 22 archivos.
- `node --test scripts/android-https.test.cjs scripts/android-location.test.cjs`:
  4/4, con el proxy activo. Evidencia HTTP actualizada en
  `tmp/android-https/http-evidence.json`: URL, mÃ©todo, origen, status, body y
  cookies identificadas por hash, sin publicar identificadores reutilizables.
- `node scripts/android-browser-session.cjs`: correcto; evidencia actualizada
  en `tmp/android-https/browser-evidence.json`. Chromium envÃ­a automÃ¡ticamente
  PHPSESSID Secure/HttpOnly/None desde https://localhost. No certifica WebView fÃ­sico.
- `C:/xampp/php/php.exe backend/tests/http-session.php
  http://localhost/zentrax-project/03-proyecto-zemyna/programacion/backend/api`:
  40 comprobaciones correctas, sin escrituras en BD.
- PHPUnit con `tmp/reverse/backend/vendor/bin/phpunit`, autoload de ese vendor,
  `--no-configuration` y filtros HttpConfigTest, IncidenciaPublicTrackingTest,
  SolicitudRetiroControllerTest, SeguimientoTest, PublicFeedbackContractTest y
  FotoStorageTest sobre backend/tests: 76 tests, 329 assertions correctas.
- `npm run build`: correcto, advertencias existentes de presupuesto SCSS.
- `npx cap sync android`: correcto; assets actualizados y cinco plugins detectados.
- Java encontrado: 22.0.2. Sin JDK 21, Android SDK, local.properties ni APK debug.
  No se instalaron herramientas ni se intentÃ³ compilar sin SDK.

La comprobaciÃ³n HTTP de incidencia conserva sesiÃ³n al enviar datos invÃ¡lidos,
pero la app no usa CAPTCHA de servidor para ese flujo. No confundir esa prueba
con un alta real. Retiro acepta el CAPTCHA con la misma sesiÃ³n y rechaza replay;
la foto invÃ¡lida detiene el POST antes de persistir. Consultar estado devuelve
200 para INC-2026-C04B7 y 404 para RET-2026-00000. No se crearon trÃ¡mites ni se
certificÃ³ consulta de un RET real nuevo. Los tests de alta/navegaciÃ³n usan dobles.

Se corroborÃ³ el POST histÃ³rico 403 desde 8101 en el access.log de Apache. Su
body original no estÃ¡ disponible; el diagnÃ³stico de origen corresponde a la
reproducciÃ³n anterior documentada. Actualmente 8101 pasa CORS (POST invÃ¡lido
400 de validaciÃ³n). No se reprodujo un fallo actual de parsing, foto o navegaciÃ³n.

## RevalidaciÃ³n del 12/10/2026

- El arranque diario ahora es `iniciar-zemyna-local.bat`; valida IP, MariaDB,
  API HTTP de Apache, regla limitada del firewall, certificados y API HTTPS.
- Cerrando el proxy identificado y abriendo el `.bat`, Node iniciÃ³ en una ventana
  separada y la API HTTPS respondiÃ³. Una segunda ejecuciÃ³n reutilizÃ³ el mismo PID;
  no iniciÃ³ otra instancia.
- XAMPP local: Apache escucha en 80; MariaDB responde en 127.0.0.1:3310 y su
  configuraciÃ³n conserva `bind-address=127.0.0.1`. `tipos_residuo.php` devolviÃ³
  HTTP 200 tanto por Apache como por HTTPS. La consulta HTTPS de contenedores
  devolviÃ³ 200, `success=true` y 60 resultados; consulta de solo lectura.
- Firewall: regla habilitada para el ejecutable Node, TCP 8443, Wi-Fi,
  172.28.57.181 y remoto 172.28.57.0/24.
- `node --test scripts/android-https.test.cjs scripts/android-location.test.cjs`:
  4 tests correctos, sin escrituras en la base.
- La APK no se recompilÃ³ ni reinstalÃ³: `assembleDebug` requiere el toolchain
  Java 21 y solo se encontraron JDK 22/Java 25. SDK 36 existe, pero no hay
  `android/local.properties`; el dispositivo dejÃ³ de estar disponible en ADB
  durante la comprobaciÃ³n de pantalla. Carga visual en el telÃ©fono pendiente.

## Archivos de esta intervenciÃ³n

- MÃ³vil: `src/app/api.android.config.ts`, `ANDROID_LAN.md`.
- Android debug: `android/app/src/debug/java/io/ionic/starter/LanDebugActivity.java`,
  `android/app/src/debug/res/xml/zemyna_lan_network_security.xml`,
  `android/app/src/debug/res/raw/zemyna_demo_ca.crt` (pÃºblico).
- Scripts: `scripts/prepare-android-https.cjs`, `scripts/android-https.cjs`,
  `scripts/android-https-firewall.ps1`, `scripts/android-https.test.cjs`,
  `scripts/android-browser-session.cjs`.
- Backend: `backend/config/http.php`, `backend/tests/HttpConfigTest.php`.
- Generados: assets `www` y `android/app/src/main/assets` por build/sync;
  certificados privados y evidencia solo bajo `tmp/android-https` ignorado.
- No se editaron HTML, SCSS, textos visibles, rutas, servicios, mapas, QR,
  geocodificaciÃ³n, contenedores, base de datos ni WhatsApp.

Prueba fÃ­sica pendiente: con APK debug compilado y proxy/regla activos, una pasada
completa por reporte (QR/GPS/mapa/calle, foto opcional, confirmaciÃ³n y consulta INC)
y retiro (CAPTCHA, foto opcional, confirmaciÃ³n y consulta RET), guardando los cÃ³digos.
No declarar esta pasada aprobada por resultados de escritorio.

Referencias: [Capacitor config](https://capacitorjs.com/docs/config),
[Android CookieManager](https://developer.android.com/reference/android/webkit/CookieManager),
[Android Network Security Config](https://developer.android.com/privacy-and-security/security-config),
[Chrome acceso LAN](https://developer.chrome.com/blog/local-network-access),
[Android permiso LAN](https://developer.android.com/privacy-and-security/local-network-permission),
[SameSite/Secure](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Set-Cookie).
