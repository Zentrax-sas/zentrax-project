# Alta disponibilidad y continuidad de Zemyna

Este documento separa el estado realmente implementado de la arquitectura que
sería necesaria para operar Zemyna con alta disponibilidad en producción. La
alta disponibilidad **no está implementada actualmente**.

## Conceptos

- **Disponibilidad:** capacidad de un servicio para responder cuando se lo
  necesita. Un reinicio automático puede mejorarla, aunque no elimine todos los
  puntos únicos de falla.
- **Alta disponibilidad (HA):** diseño redundante que mantiene o recupera el
  servicio automáticamente ante la falla de un componente, evitando que un solo
  nodo sea imprescindible.
- **Backup:** copia de datos utilizada para recuperar un estado anterior. No
  atiende tráfico ni sustituye automáticamente al sistema activo.
- **Recuperación ante desastres (DR):** procedimientos para reconstruir el
  servicio y sus datos después de una pérdida grave.
- **Continuidad:** capacidad organizativa y técnica de sostener o recuperar las
  funciones esenciales dentro de objetivos definidos.

Los backups permiten recuperación, pero no continuidad inmediata. Google Drive
aporta una copia externa y no alta disponibilidad.

## 1. Estado actual implementado

Zemyna se ejecuta en una única VM Rocky Linux. Docker Compose administra tres
servicios: aplicación, MariaDB y phpMyAdmin. Los datos de MariaDB, uploads y logs
se guardan en volúmenes persistentes. La política de reinicio de los contenedores
ayuda a recuperar procesos detenidos, pero no resuelve la caída completa de la
VM, su disco, su red o el host físico.

### Arquitectura actual

```text
Usuarios
   |
   v
Única VM Rocky Linux                       Google Drive
   |                                            ^
   +-- Docker Compose                          |
   |     +-- app                               +-- copia externa diaria
   |     +-- MariaDB
   |     `-- phpMyAdmin
   |
   +-- volúmenes: base, uploads y logs
   `-- backup completo local cada 4 horas
```

Controles implementados:

- volúmenes Docker persistentes;
- reinicio automático de contenedores;
- backup completo local cada cuatro horas;
- copia externa diaria mediante rclone a Google Drive;
- manifiesto SHA-256 y validación del backup;
- restauración descartable validada;
- despliegue reproducible validado.

Toda la ejecución sigue dependiendo de una sola VM. No existe un segundo nodo de
aplicación, una réplica de MariaDB, un balanceador redundante ni failover
automático.

### Regla 3-2-1 aplicada

Zemyna mantiene:

1. los datos activos en el volumen de MariaDB;
2. un backup comprimido local en el servidor;
3. una copia externa en Google Drive.

Esto aporta tres copias, dos medios o ubicaciones (almacenamiento local y Drive)
y una copia fuera del servidor. La regla reduce el riesgo de pérdida de datos,
pero por sí sola no proporciona HA.

### Objetivos actuales

- **RPO local máximo teórico:** 4 horas, condicionado a que el último trabajo de
  backup haya terminado correctamente.
- **RPO offsite máximo teórico:** 24 horas, condicionado a que la copia diaria
  haya terminado correctamente.
- **RTO:** todavía no medido formalmente. No se documenta una duración hasta
  realizar ejercicios cronometrados de recuperación completa.

Estos RPO son frecuencias máximas teóricas, no garantías contractuales. Deben
vigilarse los trabajos y comprobarse periódicamente la restauración.

## 2. Recuperación ante desastres

Ante una pérdida recuperable se debe identificar el último backup válido,
verificar gzip y SHA-256, restaurarlo primero en un entorno aislado, comprobar
conteos y recién después aprobar una restauración sobre el destino correcto. El
procedimiento seguro está documentado en `README.md`.

El backup local ofrece una recuperación más rápida cuando la VM y su disco siguen
accesibles. La copia externa protege frente a pérdida completa del servidor o de
sus backups locales. Si Google Drive no está disponible, los datos activos y el
backup local permanecen independientes; si se pierde la VM, la copia externa
permite reconstruir, pero requiere intervención y tiempo.

### Tipos de backup

- **Completo:** contiene toda la estructura y todos los datos en cada ejecución.
- **Incremental:** contiene los cambios desde el backup anterior, sea completo o
  incremental; para restaurar necesita una cadena ordenada.
- **Diferencial:** contiene los cambios desde el último backup completo; para
  restaurar necesita el completo y el diferencial elegido.

Zemyna usa backup completo porque el tamaño comprobado es pequeño,
aproximadamente **364 KB comprimido**, y porque una única copia simplifica la
restauración y reduce el riesgo operacional de cadenas incompletas.

## 3. Alta disponibilidad propuesta para producción

Una arquitectura futura debería eliminar los puntos únicos de falla:

```text
                         +--> nodo de aplicación A --+
Usuarios --> balanceador |                            |--> almacenamiento
             redundante  +--> nodo de aplicación B --+    compartido/replicado
                  |                    |
                  |                    +--> sesiones compartidas o app sin estado
                  |
                  `--> comprobaciones de salud

Aplicaciones --> MariaDB primaria + réplica
                 o clúster con quorum

Monitorización y alertas sobre todos los componentes
Backups locales y externos independientes de la replicación
```

Componentes propuestos:

- balanceador o proxy inverso con comprobaciones de salud;
- réplica del balanceador para evitar otro punto único de falla;
- al menos dos nodos de aplicación;
- sesiones compartidas o aplicación sin estado;
- almacenamiento compartido o replicado para uploads;
- MariaDB primaria y réplica, o un clúster con quorum;
- procedimiento de failover documentado y probado;
- monitorización, métricas y alertas;
- backups locales y externos independientes de la réplica.

La replicación no reemplaza los backups: también puede replicar eliminaciones,
corrupción o errores humanos. Los backups tampoco reemplazan el failover porque
su restauración no es inmediata.

### Fallos y respuesta

| Falla | Respuesta actual | Respuesta propuesta para HA |
|---|---|---|
| Proceso de un contenedor | Política de reinicio; interrupción posible | Reinicio más tráfico derivado a otro nodo saludable |
| Aplicación Docker | Reconstrucción en la misma VM | Balanceador retira el nodo y usa otro nodo de aplicación |
| MariaDB | Recuperación manual o restauración | Réplica/clúster y failover probado, conservando backups |
| Volumen o datos corruptos | Restauración desde backup válido | Aislar réplica afectada y restaurar con procedimiento probado |
| VM completa | Servicio fuera de línea hasta reconstrucción | Otro nodo mantiene servicio; reemplazo de la VM fallida |
| Balanceador | Actualmente no existe | Segundo balanceador o dirección virtual con failover |
| Uploads de un nodo | Recuperación desde volumen/backup | Almacenamiento compartido o replicado |
| Google Drive inaccesible | Continúan datos activos y backup local | Mantener proveedor externo independiente y alertar |
| Backup fallido | Menor cobertura hasta detectar y corregir | Alerta automática y verificación continua |

### Fases realistas

1. **Medir y observar:** monitorizar servicios, backups, espacio, certificados y
   salud; ejecutar recuperaciones cronometradas para obtener un RTO real.
2. **Preparar la aplicación:** separar configuración, hacer sesiones compartidas
   o eliminar estado local y definir almacenamiento replicado para uploads.
3. **Duplicar aplicación:** desplegar dos nodos y un proxy con healthchecks;
   probar pérdida controlada de un nodo.
4. **Proteger la base:** implementar réplica o clúster con quorum y documentar el
   failover, recuperación y retorno al estado normal.
5. **Eliminar el último punto único:** duplicar el balanceador, distribuir nodos
   entre hosts y redes independientes y ensayar fallos completos.
6. **Operar continuamente:** alertas, simulacros, revisión de RPO/RTO y pruebas
   periódicas de backups y failover.

## 4. Pendientes y limitaciones

- El RTO no está medido formalmente.
- Los RPO indicados dependen de ejecuciones exitosas y supervisadas.
- Una única VM continúa siendo el principal punto único de falla.
- No hay segundo nodo, réplica de MariaDB ni quorum.
- Las sesiones y uploads aún deben evaluarse para una ejecución multinodo.
- No existe failover automático documentado y probado.
- Falta monitorización central y alertas operativas.
- Google Drive puede recuperar datos, pero no servir la aplicación.

### Justificación académica

Implementar HA completa dentro de una única VM sería una simulación engañosa:
varios contenedores seguirían compartiendo host, disco, red y alimentación. Para
la etapa actual se priorizaron persistencia, backups verificables, copia externa,
restauración segura y despliegue reproducible. Estos controles demuestran
continuidad y recuperación dentro del alcance académico, mientras la HA real se
documenta como evolución que requiere infraestructura multinodo, pruebas de
failover y mediciones operativas.
