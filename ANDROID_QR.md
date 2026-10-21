# Lectura QR Android

## Configuración

- Dependencias exactas: `@capacitor/android@8.5.2` y `@capacitor/barcode-scanner@3.1.2`.
- Plataforma generada en `android/`; `appId` y namespace conservados: `io.ionic.starter`.
- `android/variables.gradle`: `minSdkVersion = 26`; compile/target SDK 36 del proyecto generado.
- `capacitor.config.ts` y `src/app/api.config.ts` permanecen sin cambios.
- El manifest de la dependencia Maven `io.ionic.libs:ionbarcode-android:2.1.1@aar` declara `android.permission.CAMERA`. Se verificó el manifest del AAR exacto; no se duplica el permiso en el manifest de la app. Gradle debe incorporarlo mediante manifest merger. El scanner gestiona la solicitud del permiso en tiempo de ejecución.

## Adaptadores

`crearLectorQr()` selecciona `LectorQrNative` solo si Capacitor informa plataforma nativa Android. Navegador y otras plataformas mantienen `LectorQrWeb` (BarcodeDetector/getUserMedia). El módulo del plugin se importa de forma diferida al iniciar la lectura nativa; no se utiliza su implementación web ni su CSS.

El adaptador Android solicita exclusivamente `QR_CODE`, cámara trasera y lectura automática. Reutiliza las instrucciones y mensajes existentes. Verifica el formato devuelto y entrega el texto original a la lógica actual de `codigo-qr.ts`, sin interpretarlo ni navegar. No se modifican las plantillas, estilos, entrada manual o servicios de búsqueda.

## Cancelación y errores

- Cancelar/atrás en la actividad nativa finaliza la lectura sin emitir un código ni mostrar error (`OS-PLUG-BARC-0006`).
- Permiso denegado (`OS-PLUG-BARC-0007`) usa el mensaje existente de permisos. Los demás errores usan el mensaje existente de lectura y permiten reintentar.
- Al cancelar la suscripción se ignoran resultados y errores tardíos; si todavía se estaba importando el plugin, no se abre la cámara.
- La API 3.1.2 solo expone `scanBarcode`, sin `stopScan` ni cancelación programática de la actividad. Mientras esa actividad siga pendiente no se abre otra sesión; el usuario la cierra con sus controles nativos. No se afirma que cancelar una suscripción cierre físicamente la cámara. La pantalla nativa del plugin cubre temporalmente la WebView, sin alterar la navegación Angular ni la UI de Zemyna.
- Códigos de error documentados por la [biblioteca nativa de OutSystems](https://github.com/OutSystems/OSBarcodeLib-Android#errors); API contrastada con los tipos y el código del paquete 3.1.2 instalado.

## Validación y compilación

```powershell
npm test -- --watch=false
npm run build
npx cap sync android
cd android
.\gradlew.bat assembleDebug --no-daemon -Dorg.gradle.java.installations.auto-download=false
```

El plugin declara toolchain JDK 21 en su Gradle. El entorno inspeccionado dispone de Java/javac 22.0.2; no se detectó Android SDK en la ubicación habitual ni `ANDROID_HOME`. No se instalan JDK ni SDK automáticamente. Para compilar y probar físicamente se necesita Android SDK con API 36 y herramientas compatibles, JDK 21 disponible para el toolchain y un dispositivo Android 8/API 26 o superior. Los tests del adaptador simulan el puente nativo; no sustituyen una prueba de cámara y permisos en dispositivo.

Resultados de esta implementación:

- Suite completa: 126 tests aprobados en 19 archivos, incluidos los 12 casos nuevos de selección de adaptador y scanner nativo. Hay avisos de sourcemaps ausentes en la dependencia transitiva html5-qrcode.
- Build web: aprobado, con las advertencias existentes de presupuesto SCSS.
- `npx cap sync android`: completado, plugin 3.1.2 registrado y assets actualizados.
- Build Android: se intentó `assembleDebug`; se descargó Gradle 8.14.3 y se interrumpió la preparación del wrapper al confirmar que no está disponible el entorno requerido (SDK Android y toolchain JDK 21). No se generó ni validó un APK; tampoco se verificó aún el manifest fusionado por Gradle.
- Se compararon hashes antes/después: HTML, SCSS/CSS, rutas, API config y archivos de solicitud de retiro, novedades y consultar estado permanecen intactos.

## Pendiente: acceso al backend desde un celular físico

`API_BASE_URL` sigue siendo `/backend/api`. Dentro de Capacitor esa ruta relativa apunta al origen local de la WebView, no al servidor PHP de desarrollo. El proxy de `ng serve` no se incluye en el APK.

Queda pendiente definir y autorizar una URL del backend accesible desde el teléfono (servidor HTTPS o dirección de red alcanzable), comprobar conectividad/firewall y configurar CORS para el origen de Capacitor. `localhost` en el teléfono no apunta al equipo de desarrollo. Si se opta por HTTP, será necesaria una decisión explícita sobre la política de tráfico sin cifrar; no se habilita globalmente en esta tarea. No se modifica API config, proxy, backend ni configuración de red Android para resolverlo.
