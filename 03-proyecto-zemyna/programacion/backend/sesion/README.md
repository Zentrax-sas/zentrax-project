# Registro de sesiones

Esta carpeta conserva eventos mínimos de autenticación en formato JSON Lines.
PHP escribe `sesiones.log` mediante append con bloqueo exclusivo. El archivo rota
al superar 5 MB y se conservan como máximo cinco históricos `sesiones-*.log`.

Los archivos de ejecución no se versionan. `.htaccess` impide descargarlos con
Apache/XAMPP. En Nginx se debe agregar una regla equivalente para denegar toda URL
que apunte a `/backend/sesion/`; no alcanza con ocultar enlaces desde el frontend.

El registro contiene solamente fecha/hora de Montevideo, evento, resultado, ID de
usuario y roles normalizados. No debe recibir contraseñas, hashes, cookies, IDs de
sesión, tokens, payloads completos ni información de conexión.

Al ser un archivo dentro del proyecto, persiste al reiniciar PHP o XAMPP. El
`compose.yaml` de Zemyna monta esta carpeta en el volumen `session_logs`.
