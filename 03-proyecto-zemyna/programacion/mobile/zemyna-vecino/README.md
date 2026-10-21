# Zemyna Vecino

App móvil ciudadana de Zemyna (gestión de residuos). Ionic 9 + Angular 22 + Capacitor 8, con empaquetado Android. Consume el backend PHP del proyecto (`programacion/backend/api`).

## Requisitos

- Node.js 20 o superior (probado con 24) y npm.
- JDK 21 (Gradle exige toolchain 21; con otra versión el APK no compila).
- Android SDK (variable `ANDROID_HOME`) para compilar el APK.
- Backend Zemyna en ejecución (XAMPP/Apache + MariaDB o Docker).

## Instalación y desarrollo web

```bash
npm ci
npm start        # http://localhost:4200, usa el proxy proxy.conf.cjs hacia /backend
```

`proxy.conf.cjs` redirige `/backend/**` al backend local (ver el destino en ese archivo).

## Compilación

```bash
npm run build    # genera www/
npm run lint
npm test -- --watch=false
```

## Android

```powershell
npm run build
$env:JAVA_HOME = '<ruta al JDK 21>'   # solo para esta sesión; no cambia la configuración global
$env:ANDROID_HOME = "$env:LOCALAPPDATA\Android\Sdk"
npx cap sync android
cd android
.\gradlew.bat assembleDebug         # APK en android/app/build/outputs/apk/debug/
```

La URL del backend en Android está en `src/app/api.android.config.ts` (`ANDROID_API_BASE_URL`). Debe apuntar a una IP/host alcanzable por el teléfono y se ajusta por entorno. Guías de red local y QR: [ANDROID_LAN.md](ANDROID_LAN.md) y [ANDROID_QR.md](ANDROID_QR.md).

## Funcionalidades implementadas

Implementadas en el código y cubiertas por pruebas unitarias con HTTP simulado (`npm test`). Esto no prueba la integración con el backend real.

- Inicio y novedades municipales (`novedades.php`).
- Buscar contenedor por calle, esquina, mapa o QR (`contenedores.php`, escáner nativo).
- Reportar incidencia con foto, ubicación y captcha (`incidencias.php`, `foto.php`, `captcha.php`).
- Solicitar retiro de residuos voluminosos con dirección georreferenciada, foto y captcha (`solicitud-retiro.php`, `direcciones-retiro.php`, `tipos_residuo.php`).
- Consultar estado por código de seguimiento (INC/RET/REF).
- Editar y cancelar un trámite propio con clave de edición (`edicion-tramite.php`, `cancelacion.php`).
- Consentimiento de avisos por WhatsApp (plugin nativo `WhatsAppExternoPlugin`).
- Mensajes de error y reintentos ante fallos de red.

## Verificación contra el backend

| Endpoint | Presente en el backend integrado | Verificado de extremo a extremo |
|---|---|---|
| `contenedores`, `incidencias`, `foto`, `captcha`, `tipos_residuo` | Sí (`origin/main`) | Pendiente de registrar |
| `novedades`, `solicitud-retiro`, `direcciones-retiro`, `edicion-tramite`, `cancelacion` | Sí, en la integración local de `main` aún sin publicar | Pendiente |

Antes de publicar hay que probar cada flujo contra el backend integrado y actualizar esta tabla.

## Pendiente o limitado

- Sin HTTPS: en Android debug se permite HTTP a una IP LAN fija; falta configurar un dominio con TLS para producción.
- La URL del backend está fija en `api.android.config.ts`; no hay variable por entorno.
- Identificador Android por defecto (`io.ionic.starter`) y sin firma de release.
- `scripts/android-https.test.cjs` falla en este entorno (timeout de red).
- No hay login ni cuentas de ciudadano: el seguimiento se basa en el código y la clave del trámite.
- Los endpoints `novedades`, `solicitud-retiro`, `direcciones-retiro`, `edicion-tramite` y `cancelacion` requieren que el backend integrado (que los incluye) esté publicado antes que la app.

## Seguridad

No se versionan `.env`, keystores, `local.properties`, APK ni `www/`. El certificado `zemyna_demo_ca.crt` es solo la CA pública de demostración para builds debug.
