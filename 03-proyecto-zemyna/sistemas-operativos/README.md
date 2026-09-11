# Operación de Zemyna en la VM

## Backup versionado de MariaDB

`scripts/backup_zemyna.sh` ejecuta `mariadb-dump` dentro del servicio Compose
`db`. Obtiene `MARIADB_DATABASE`, `MARIADB_USER` y `MARIADB_PASSWORD` del
contenedor configurado mediante el `.env.docker` privado; no guarda secretos.

Publica `/var/backups/zemyna/zemyna_AAAAMMDD_HHMMSS.sql.gz` sólo después de
validar tamaño, gzip, `CREATE TABLE` e `INSERT INTO`. Incluye estructura, datos,
triggers, rutinas y eventos con transacción consistente. `flock` evita ejecuciones
simultáneas. Elimina sólo backups válidos que superen `RETENTION_DAYS` (7 por
defecto); conserva los inválidos para revisión. Directorios, log y archivos usan
permisos restrictivos.

### Instalación

Desde la raíz del repositorio en la VM:

```bash
sudo install -d -m 700 /etc/zemyna /var/backups/zemyna /var/log/zemyna
sudo install -m 755 03-proyecto-zemyna/sistemas-operativos/scripts/backup_zemyna.sh /usr/local/bin/backup_zemyna.sh
sudo install -m 600 03-proyecto-zemyna/sistemas-operativos/config/backup.conf.example /etc/zemyna/backup.conf
sudo chown root:root /usr/local/bin/backup_zemyna.sh /etc/zemyna/backup.conf
```

Revisar `/etc/zemyna/backup.conf`; `ENV_FILE` debe apuntar al `.env.docker`
privado. Probar sin mostrar su contenido:

```bash
sudo /usr/local/bin/backup_zemyna.sh
sudo find /var/backups/zemyna -maxdepth 1 -type f -name 'zemyna_*.sql.gz' -ls
sudo gzip -t /var/backups/zemyna/zemyna_AAAAMMDD_HHMMSS.sql.gz
sudo tail -n 20 /var/log/zemyna/backup.log
```

Requiere el proyecto Compose `zemyna_compose_test` y el servicio `db` activos.

### Cron cada cuatro horas

Agregar mediante `sudo crontab -e`:

```cron
0 */4 * * * /usr/local/bin/backup_zemyna.sh
```

El script devuelve un código distinto de cero ante configuración inválida,
bloqueo concurrente, fallo del dump o validación fallida. Los temporales fallidos
se eliminan y el log nunca incluye credenciales ni contenido del respaldo.

La restauración es destructiva y deliberadamente no se automatiza: antes de
restaurar se debe verificar el destino y respaldar su estado actual.

## Respaldo externo con Google Drive y rclone

`scripts/offsite_backup_zemyna.sh` toma el `.sql.gz` local más reciente, lo
valida otra vez, genera un manifiesto SHA-256 y sube ambos archivos a una carpeta
dedicada de Google Drive. No accede a MariaDB y un fallo remoto nunca elimina ni
invalida el backup local.

La organización remota es:

```text
REMOTE_BASE_PATH/
├── daily/YYYY-MM-DD/
├── weekly/YYYY-Www/
└── monthly/YYYY-MM/
```

Siempre crea la copia diaria. Los domingos agrega la semanal y el primer día de
cada mes agrega la mensual. La retención propuesta es 7 días, 5 semanas y 12
meses (365 días). Está desactivada por defecto. Al habilitarla, `rclone delete`
se limita a esas tres subcarpetas y usa la papelera de Google Drive; nunca se usa
`sync` ni `purge`. La recuperación desde la papelera depende de las políticas de
la cuenta de Drive.

### Configuración headless

Rocky Linux 10 no ofrece necesariamente `rclone` en los repositorios habilitados.
Instalarlo con el instalador oficial multiplataforma y verificar el resultado:

```bash
sudo -v
curl -fsSLo /tmp/rclone-install.sh https://rclone.org/install.sh
sudo bash /tmp/rclone-install.sh
rm /tmp/rclone-install.sh
rclone version
```

En un equipo con navegador, crear el remoto con `rclone config`. En la VM sin
interfaz, transferir de forma segura solamente su configuración al archivo
privado `/etc/zemyna/rclone.conf`. No copiarlo al repositorio:

```bash
sudo install -d -m 700 /etc/zemyna /var/log/zemyna
sudo install -m 600 /ruta/segura/rclone.conf /etc/zemyna/rclone.conf
sudo install -m 600 03-proyecto-zemyna/sistemas-operativos/config/offsite-backup.conf.example /etc/zemyna/offsite-backup.conf
sudo install -m 755 03-proyecto-zemyna/sistemas-operativos/scripts/offsite_backup_zemyna.sh /usr/local/bin/offsite_backup_zemyna.sh
sudo chown root:root /etc/zemyna/rclone.conf /etc/zemyna/offsite-backup.conf /usr/local/bin/offsite_backup_zemyna.sh
```

Editar `/etc/zemyna/offsite-backup.conf` y comprobar con `rclone listremotes
--config /etc/zemyna/rclone.conf` que `REMOTE_NAME` existe. La ruta base debe ser
una carpeta exclusiva, nunca la raíz completa de Drive.

Probar manualmente:

```bash
sudo /usr/local/bin/offsite_backup_zemyna.sh
sudo tail -n 20 /var/log/zemyna/offsite-backup.log
sudo rclone --config /etc/zemyna/rclone.conf lsf gdrive_zemyna:Zemyna/backups/daily
```

Programar después del backup local:

```cron
30 0 * * * /usr/local/bin/offsite_backup_zemyna.sh
```

### Recuperación y validación

Descargar a un directorio vacío tanto el backup como su manifiesto, sin ejecutar
todavía SQL:

```bash
rclone --config /etc/zemyna/rclone.conf copyto REMOTO:RUTA/zemyna_FECHA.sql.gz ./zemyna_FECHA.sql.gz
rclone --config /etc/zemyna/rclone.conf copyto REMOTO:RUTA/zemyna_FECHA.sql.gz.sha256 ./zemyna_FECHA.sql.gz.sha256
sha256sum --check zemyna_FECHA.sql.gz.sha256
gzip -t zemyna_FECHA.sql.gz
```

El dump completo contiene `CREATE DATABASE gestion_residuosfinal;` y
`USE gestion_residuosfinal;`. Por eso, indicar otra base con `--database` o como
argumento de `mariadb` **no aísla la restauración**: el `USE` interno prevalece y
puede sobrescribir la base principal. Nunca importar el `.sql.gz` original para
una prueba descartable.

El hash confirma integridad, pero no reemplaza la revisión del destino ni una
prueba periódica de restauración.

### Restauración descartable segura

Este procedimiento usa exclusivamente `gestion_residuosfinal_restore_test`. Se
detiene si el nombre no coincide exactamente o si se intenta usar
`gestion_residuosfinal`. Debe ejecutarse desde el directorio `programacion`, con
el Compose y su `.env.docker` privado disponibles.

Primero validar el archivo y preparar una copia SQL temporal:

```bash
set -Eeuo pipefail

RESTORE_DB=gestion_residuosfinal_restore_test
PRODUCTION_DB=gestion_residuosfinal
BACKUP_FILE=/ruta/validada/zemyna_FECHA.sql.gz
WORK_DIR="$(mktemp -d /tmp/zemyna-restore-test.XXXXXX)"
trap 'rm -rf -- "$WORK_DIR"' EXIT

if [[ "$RESTORE_DB" != "gestion_residuosfinal_restore_test" || "$RESTORE_DB" == "$PRODUCTION_DB" ]]; then
    echo "Destino descartable inválido" >&2
    exit 1
fi

gzip -t "$BACKUP_FILE"
gzip -cd "$BACKUP_FILE" > "$WORK_DIR/original.sql"

test "$(grep -Ec '^CREATE DATABASE( IF NOT EXISTS)? `?gestion_residuosfinal`?;$' "$WORK_DIR/original.sql")" -eq 1
test "$(grep -Ec '^USE `?gestion_residuosfinal`?;$' "$WORK_DIR/original.sql")" -eq 1
test "$(grep -Ec '^CREATE DATABASE' "$WORK_DIR/original.sql")" -eq 1
test "$(grep -Ec '^USE ' "$WORK_DIR/original.sql")" -eq 1

awk '
    /^CREATE DATABASE( IF NOT EXISTS)? `?gestion_residuosfinal`?;$/ { next }
    /^USE `?gestion_residuosfinal`?;$/ { next }
    { print }
' "$WORK_DIR/original.sql" > "$WORK_DIR/restore-safe.sql"

if grep -Eq '^CREATE DATABASE|^USE ' "$WORK_DIR/restore-safe.sql"; then
    echo "La copia saneada aún contiene instrucciones de selección de base" >&2
    exit 1
fi
```

Las comprobaciones anteriores exigen exactamente una directiva `CREATE
DATABASE` y una `USE` para la base canónica, rechazan cualquier variante no
esperada y eliminan únicamente esas dos líneas en la copia temporal.

Crear e importar exclusivamente la base descartable:

```bash
docker compose --project-name zemyna_compose_test --env-file .env.docker -f compose.yaml exec -T db \
  sh -eu -c 'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" -e "CREATE DATABASE \`$1\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"' \
  sh "$RESTORE_DB"

docker compose --project-name zemyna_compose_test --env-file .env.docker -f compose.yaml exec -T db \
  sh -eu -c 'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$1"' \
  sh "$RESTORE_DB" < "$WORK_DIR/restore-safe.sql"
```

Verificar los conteos restaurados y compararlos con los registrados al crear el
backup. Para el respaldo comprobado el 11 de septiembre de 2026, los valores son
26 tablas, 11.214 contenedores, 4 usuarios, 9 tipos de residuo y 47 permisos:

```bash
docker compose --project-name zemyna_compose_test --env-file .env.docker -f compose.yaml exec -T db \
  sh -eu -c 'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$1" -e "
    SELECT COUNT(*) AS tablas FROM information_schema.tables WHERE table_schema = DATABASE();
    SELECT COUNT(*) AS contenedores FROM contenedor;
    SELECT COUNT(*) AS usuarios FROM usuario;
    SELECT COUNT(*) AS tipos_residuo FROM tipo_residuo;
    SELECT COUNT(*) AS permisos FROM permiso;
  "' sh "$RESTORE_DB"
```

Finalmente volver a comprobar el nombre y eliminar solamente la base temporal:

```bash
if [[ "$RESTORE_DB" != "gestion_residuosfinal_restore_test" || "$RESTORE_DB" == "$PRODUCTION_DB" ]]; then
    echo "Se rechazó la limpieza por destino inválido" >&2
    exit 1
fi

docker compose --project-name zemyna_compose_test --env-file .env.docker -f compose.yaml exec -T db \
  sh -eu -c 'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" -e "DROP DATABASE \`$1\`"' \
  sh "$RESTORE_DB"
```

La restauración descartable comprobada con este filtrado no alteró la base
principal, que conservó 26 tablas, 11.214 contenedores, 4 usuarios, 9 tipos de
residuo y 47 permisos.

### Estrategia 3-2-1 y tipo de backup

La regla 3-2-1 recomienda tres copias de los datos, en dos medios distintos y al
menos una fuera del sitio. Zemyna conserva la base activa, backups locales
comprimidos y una copia externa en Drive.

- Completo: guarda toda la estructura y los datos en cada ejecución.
- Incremental: guarda cambios desde cualquier backup anterior.
- Diferencial: guarda cambios desde el último backup completo.

Zemyna usa backups completos porque el respaldo actual es pequeño, simplifica la
restauración y reduce el riesgo operacional de depender de una cadena de copias.
Google Drive agrega una copia externa, pero no alta disponibilidad: una caída de
la aplicación o la base no provoca conmutación automática hacia Drive.

## Acceso SSH endurecido

`config/sshd/00-zemyna-hardening.conf` conserva el acceso administrativo por
clave pública y deshabilita el ingreso de root, las contraseñas y la autenticación
interactiva por teclado. No cambia el puerto ni la configuración de firewalld.

El prefijo `00-` es obligatorio en esta VM: existe
`/etc/ssh/sshd_config.d/01-permitrootlogin.conf` con `PermitRootLogin yes` y
OpenSSH conserva el primer valor encontrado para estas opciones. El archivo de
Zemyna debe leerse antes que ese drop-in.

### Requisitos y prueba previa

Antes de instalar:

- confirmar que `sshd` esté activo y que el puerto vigente esté permitido;
- confirmar que el usuario administrativo ya tenga una clave pública autorizada;
- comprobar permisos `700` para `.ssh` y `600` para `authorized_keys`;
- mantener abierta la sesión administrativa original durante todo el proceso.

Desde una segunda terminal, comprobar el acceso usando exclusivamente la clave:

```bash
ssh -o PreferredAuthentications=publickey -o PasswordAuthentication=no zentrax@SERVIDOR
```

No continuar si esa segunda sesión no abre correctamente. No cerrar la sesión
original hasta completar también todas las verificaciones posteriores.

### Instalación y validación

Desde la raíz del repositorio, conservar una copia previa si el destino ya
existe e instalar con propietario `root:root` y permisos `600`:

```bash
if sudo test -e /etc/ssh/sshd_config.d/00-zemyna-hardening.conf; then
  sudo cp -a /etc/ssh/sshd_config.d/00-zemyna-hardening.conf /etc/ssh/sshd_config.d/00-zemyna-hardening.conf.rollback
fi

sudo install -o root -g root -m 600 \
  03-proyecto-zemyna/sistemas-operativos/config/sshd/00-zemyna-hardening.conf \
  /etc/ssh/sshd_config.d/00-zemyna-hardening.conf

sudo sshd -t
sudo systemctl reload sshd
```

`sshd -t` debe finalizar correctamente antes de ejecutar la recarga. Una recarga
evita cortar las sesiones existentes, pero la sesión original igualmente debe
permanecer abierta como vía de recuperación.

Verificar la configuración efectiva:

```bash
sudo sshd -T | grep -E '^(permitrootlogin|pubkeyauthentication|passwordauthentication|kbdinteractiveauthentication) '
```

El resultado esperado es:

```text
permitrootlogin no
pubkeyauthentication yes
passwordauthentication no
kbdinteractiveauthentication no
```

Abrir otra sesión nueva y repetir la prueba positiva por clave:

```bash
ssh -o PreferredAuthentications=publickey -o PasswordAuthentication=no zentrax@SERVIDOR
```

Luego comprobar que una conexión sin clave sea rechazada, sin introducir ninguna
contraseña:

```bash
ssh -o BatchMode=yes -o PubkeyAuthentication=no zentrax@SERVIDOR
```

La segunda orden debe finalizar con acceso denegado. Sólo después de comprobar
la prueba positiva, la negativa y los valores efectivos puede cerrarse la sesión
administrativa original.

### Rollback

Si la validación o una nueva conexión falla, volver a la sesión original que
permanece abierta. Restaurar la copia previa cuando exista:

```bash
if sudo test -e /etc/ssh/sshd_config.d/00-zemyna-hardening.conf.rollback; then
  sudo mv /etc/ssh/sshd_config.d/00-zemyna-hardening.conf.rollback /etc/ssh/sshd_config.d/00-zemyna-hardening.conf
else
  sudo rm -f /etc/ssh/sshd_config.d/00-zemyna-hardening.conf
fi

sudo sshd -t
sudo systemctl reload sshd
```

No cerrar la sesión original hasta volver a validar `sshd -t` y confirmar una
nueva conexión por clave después del rollback.
