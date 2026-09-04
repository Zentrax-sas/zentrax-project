# Zemyna Backend - PHP y PHPUnit

Estas instrucciones complementan el `AGENTS.md` de `programacion/` y se aplican a `backend/`.

## PHP puro

- Utilizar PHP 8 puro y las clases existentes.
- No incorporar frameworks web, ORM ni contenedores de dependencias.
- Mantener separacion entre modelo, controlador y endpoint.
- Escribir codigo comprensible para estudiantes que deberan defenderlo oralmente.
- Evitar abstracciones innecesarias y cambios masivos.

## Responsabilidades

### Modelos

- Contienen consultas y persistencia.
- Utilizan PDO y parametros enlazados.
- No imprimen respuestas HTTP.

### Controladores

- Validan entradas y reglas de negocio.
- Devuelven resultados estructurados.
- No leen directamente `php://input` ni deciden permisos HTTP.

### Endpoints de `api/`

- Validan metodo HTTP y JSON.
- Exigen autenticacion o permisos antes de operaciones protegidas.
- Invocan al controlador.
- Asignan codigo HTTP y serializan JSON.
- No contienen consultas SQL.

## PHPUnit y Composer

El modelo academico obligatorio es el ejemplo publico del profesor:

`https://github.com/jmrsm/php_pfullstack/tree/main/2025/ejercicios_clase/testing_ejemplo`

- Usar PHPUnit instalado con Composer en `require-dev`.
- PHPUnit es una herramienta de testing; no es el framework de la aplicacion.
- Seguir el estilo de `CalculadoraTest.php`: clases que heredan de `PHPUnit\Framework\TestCase`, `setUp()` y metodos `test...`.
- Ubicar pruebas PHPUnit en `backend/tests/`.
- Nombrar archivos como `ClaseTest.php`.
- Cada prueba debe ser independiente y repetible.
- `setUp()` prepara el escenario; no debe depender de otra prueba.
- Aplicar Arrange, Act, Assert cuando mejore la claridad.
- Probar resultados, validaciones, excepciones y efectos observables.
- No crear pruebas que siempre pasen o que solo repitan la implementacion.

## Tipos de pruebas prioritarias

1. Unitarias de controladores y helpers, aislando la persistencia con dobles de prueba cuando sea necesario.
2. Integracion de modelos contra una base exclusiva de testing.
3. Integracion HTTP para login, permisos y CRUD principales.

No llamar "unitaria" a una prueba que requiere servidor HTTP, sesion real y base completa. Documentar cada grupo con su tipo correcto.

## Cobertura funcional minima

Crear pruebas PHPUnit para:

- login correcto e incorrecto;
- usuario inactivo y usuario sin rol vigente;
- normalizacion de roles;
- permiso concedido y denegado por rol y sector;
- validaciones de usuarios;
- email duplicado;
- CRUD de usuarios;
- CRUD de contenedores;
- codigo de contenedor duplicado;
- CRUD de vehiculos;
- CRUD de centros;
- CRUD de maquinaria;
- bajas logicas;
- JSON invalido y campos faltantes;
- accesos no autenticados;
- accesos autenticados sin permiso.

No fijar una cantidad artificial de tests si deja comportamientos importantes sin cubrir. La prioridad es que cada requisito y riesgo tenga pruebas utiles.

## Configuracion esperada

- Mantener `composer.json` y `composer.lock` versionados.
- Mantener `vendor/` ignorado.
- Incorporar un `phpunit.xml` o `phpunit.xml.dist` reproducible.
- Configurar una base separada, por ejemplo `gestion_residuos_test`.
- Nunca ejecutar tests destructivos contra la base de desarrollo o los contenedores reales.
- El comando documentado debe poder ejecutarse desde `backend/`.

Comando objetivo:

```bash
composer install
vendor/bin/phpunit
```

Antes de aceptar una modificacion backend:

```bash
find . -name '*.php' -not -path './vendor/*' -exec php -l {} \;
vendor/bin/phpunit
```

Si alguna validacion no puede ejecutarse, informarlo claramente y no inventar resultados.

## Base y entorno de testing

- Los tests deben crear o cargar solo los datos necesarios.
- Deben poder repetirse sin acumular registros ni depender del orden.
- Limpiar sus propios datos o trabajar dentro de transacciones cuando corresponda.
- No depender de credenciales personales.
- Usar variables de entorno específicas de testing.
- Prohibido usar `DROP DATABASE` sobre una base cuyo nombre no haya sido validado como base de pruebas.

## Seguridad que debe probarse

- Hash y verificacion de contrasenas.
- Regeneracion de sesion tras login.
- Rechazo con 401 cuando falta autenticacion.
- Rechazo con 403 cuando falta permiso.
- Restriccion por sector.
- Validacion server-side aunque el frontend valide.
- Ausencia de datos sensibles en respuestas de usuario.
- Proteccion de todos los metodos administrativos.

## Compatibilidad con pruebas existentes

Antes de borrar o reemplazar los scripts de prueba actuales, identificar que comportamiento cubren. Migrar sus casos utiles a PHPUnit y conservar temporalmente las pruebas HTTP como suite de integracion si aportan evidencia diferente.

No afirmar que las pruebas existentes son PHPUnit hasta que hereden de `TestCase` y sean descubiertas por el ejecutor de PHPUnit.

