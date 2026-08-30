# FASE 2: BASE DE DATOS + ROLES + PERMISOS

## Estado: ✅ COMPLETADO

### 1. ESTRUCTURA DE BASE DE DATOS

#### Tablas Principales (26 total)
- ✅ **usuario** - Sistema de usuarios con email único
- ✅ **rol** - Roles genéricos: ADMINISTRADOR_TI, RESPONSABLE_SECTORIAL, etc.
- ✅ **sector** - Sectores: TI, LOGISTICA, MANTENIMIENTO, PUNTOS_Y_DESTINOS, OPERACIONES, INSPECCION
- ✅ **permiso** - Permisos granulares (31 permisos)
- ✅ **usuario_rol** - Relación usuario-rol-sector con vigencia temporal
- ✅ **rol_permiso** - Relación rol-permiso
- ✅ **contenedor** - 11.000+ contenedores con geocoordinadas
- ✅ **vehiculo**, **maquinaria**, **centro**, **cuadrilla**, etc.

### 2. ROLES GENÉRICOS

```
ADMINISTRADOR_TI
├─ Sector: TI
├─ Permisos: usuario.* (crear, consultar, modificar, suspender, asignar_rol, asignar_sector)
└─ Acceso: Global del sistema

RESPONSABLE_SECTORIAL
├─ Sector: Asignable (LOGISTICA, MANTENIMIENTO, PUNTOS_Y_DESTINOS, OPERACIONES, INSPECCION)
├─ Permisos: Completos en contenedor.*, vehiculo.*, lugar.*, maquinaria.*
└─ Acceso: Solo su sector asignado

ADMINISTRATIVO_OPERATIVO
├─ Sector: OPERACIONES
├─ Permisos: contenedor.consultar, vehiculo.consultar, incidencia.*
└─ Acceso: Organizar tareas diarias

OPERARIO
├─ Sector: OPERACIONES
├─ Permisos: contenedor.cambiar_estado, incidencia.*, adjuntar_evidencia
└─ Acceso: Ejecutar tareas

INSPECTOR
├─ Sector: INSPECCION
├─ Permisos: consultar.*, incidencia.crear, incidencia.adjuntar_evidencia
└─ Acceso: Consultar y verificar
```

### 3. PERMISOS (31 TOTAL)

#### Usuarios (6)
- usuario.crear
- usuario.consultar
- usuario.modificar
- usuario.suspender
- usuario.asignar_rol
- usuario.asignar_sector

#### Contenedores (5)
- contenedor.crear
- contenedor.consultar
- contenedor.modificar
- contenedor.baja
- contenedor.cambiar_estado

#### Vehículos (7)
- vehiculo.crear
- vehiculo.consultar
- vehiculo.modificar
- vehiculo.baja
- vehiculo.cambiar_estado
- vehiculo.asignar

#### Lugares/Centros (5)
- lugar.crear
- lugar.consultar
- lugar.modificar
- lugar.baja
- lugar.cambiar_estado

#### Maquinaria (5)
- maquinaria.crear
- maquinaria.consultar
- maquinaria.modificar
- maquinaria.baja
- maquinaria.cambiar_estado

#### Incidencias (4)
- incidencia.crear
- incidencia.consultar
- incidencia.modificar
- incidencia.adjuntar_evidencia

### 4. AUTORIZACIÓN

**Modelo Conceptual:**
```
ROL + SECTOR + PERMISO = AUTORIZACIÓN EFECTIVA
```

**Implementación:**
```php
// En backend/helpers/auth.php
hasEffectivePermission($permiso, $sectoresPermitidos = null)

// Lógica:
// 1. Obtener usuario desde $_SESSION
// 2. Si ADMINISTRADOR_TI → acceso global (sin restricción de sector)
// 3. Si RESPONSABLE_SECTORIAL → validar sector asignado
// 4. Si otro rol → validar rol + sector + permiso
```

### 5. MIGRACIONES EJECUTADAS

**Orden de Ejecución:**
1. ✅ schema.sql - Estructura oficial
2. ✅ migration_v2_roles_normalizados.sql
3. ✅ migration_v3_autorizacion.sql
4. ✅ migration_v4_baja_logica_contenedores.sql
5. ✅ migration_v5_bajas_logicas_recursos.sql
6. ✅ migration_v6_geocodificacion_cache.sql
7. ✅ migration_v7_incidentes_ciudadanos.sql
8. ✅ migration_v8_roles_genericos.sql (PRINCIPAL - Implementa la estructura de FASE 2)
9. ✅ migration_v9_permisos_rutas.sql
10. ✅ migration_v10_permisos_operativos.sql
11. ✅ migration_index_geo.sql
12. ✅ migration_tracking_incidencia.sql

**Archivo Maestro:** `00_INICIALIZAR.sql`

### 6. CÓMO EJECUTAR LA BASE DE DATOS

#### Opción 1: Desde Terminal (Recomendado)
```bash
cd 03-proyecto-zemyna/programacion/base-datos/database/sql/
mysql -u root -p < 00_INICIALIZAR.sql
```

#### Opción 2: Desde phpMyAdmin
1. Crear base de datos `gestion_residuosfinal` (UTF8MB4)
2. Ir a "SQL"
3. Copiar contenido de `00_INICIALIZAR.sql`
4. Ejecutar

#### Opción 3: Línea por línea
```bash
mysql -u root -p gestion_residuosfinal < schema.sql
mysql -u root -p gestion_residuosfinal < migration_v8_roles_genericos.sql
mysql -u root -p gestion_residuosfinal < init.sql
```

### 7. USUARIOS DEMO

| Email | Contraseña | Rol | Sector | Centro |
|-------|------------|-----|--------|--------|
| sistemas@zemyna.com | password | ADMINISTRADOR_TI | TI | 1 |
| facu@zemyna.com | password | ADMINISTRATIVO_OPERATIVO | OPERACIONES | 1 |
| diego@zemyna.com | password | OPERARIO | OPERACIONES | 1 |
| andrea@zemyna.com | password | INSPECTOR | INSPECCION | 2 |

**Contraseña Demo (todas):** `password` (hash: $2y$10$A32/CEXLhCrbRkgub3SeWeGMtn3.TOB3K/Xivs/DEVdbk0D6Iqxoe)

### 8. DATOS DEMO INICIALES

- ✅ 3 centros
- ✅ 6 tipos de residuo
- ✅ 3 rutas
- ✅ 3 vectores
- ✅ 2 cuadrillas
- ✅ 3 contenedores (CTN-001, CTN-002, CTN-003)
- ✅ 2 maquinarias
- ✅ 3 recorridos
- ✅ 3 incidencias
- ✅ 3 denuncias

### 9. VALIDACIONES EN BACKEND

✅ Implementadas en `backend/helpers/auth.php`:

```php
function requireAuth()              // 401 si no autenticado
function requireRole($roles)        // 403 si rol no permitido
function requirePermission($permiso, $sectores)  // Valida ROL+SECTOR+PERMISO
function hasEffectivePermission()   // Retorna true/false con lógica completa
```

### 10. PRÓXIMAS FASES

- **FASE 3:** Login + Sesiones (Validar que funciona con nueva estructura)
- **FASE 4:** CRUD Obligatorios (Usuarios, Contenedores, Vehículos, Centros, Maquinaria)
- **FASE 5:** Mapa + Geocodificación Inversa
- **FASE 6:** Frontend CRUD Completo
- **FASE 7:** Docker
- **FASE 8:** 30+ Tests
- **FASE 9:** Revisión Integral

### 11. CHECKLIST DE VALIDACIÓN

- [x] Schema.sql crea todas las tablas
- [x] Migraciones son idempotentes (ON DUPLICATE KEY UPDATE)
- [x] 6 Sectores seeded
- [x] 31 Permisos seeded
- [x] 5 Roles genéricos creados
- [x] 4 Usuarios demo con roles asignados
- [x] 11.000+ contenedores preservados
- [x] Autorización por ROL+SECTOR+PERMISO implementada
- [x] .env configurado correctamente
- [x] 00_INICIALIZAR.sql como punto de entrada

### 12. ARCHIVOS MODIFICADOS EN FASE 2

```
✅ .env                                    → Actualizado con configuración completa
✅ 00_INICIALIZAR.sql                      → Creado (archivo maestro)
📁 base-datos/database/sql/
   ✅ schema.sql                           → Estructura oficial (no modificado)
   ✅ migration_v8_roles_genericos.sql     → Implementa estructura genérica
   ✅ init.sql                             → Datos demo (no modificado)
```

### 13. DECISIONES TOMADAS

1. ✅ **No eliminar datos existentes**: Las migraciones usan ON DUPLICATE KEY UPDATE
2. ✅ **Roles genéricos**: Estructura pedida en segundo, no legacy
3. ✅ **Sectores asignables**: Cada usuario puede tener múltiples sectores
4. ✅ **Vigencia temporal**: usuario_rol tiene fecha_desde/fecha_hasta
5. ✅ **Autorización granular**: ROL+SECTOR+PERMISO en backend

---

**FASE 2 COMPLETADA ✅**
Pasar a FASE 3: Login + Sesiones
