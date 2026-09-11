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
