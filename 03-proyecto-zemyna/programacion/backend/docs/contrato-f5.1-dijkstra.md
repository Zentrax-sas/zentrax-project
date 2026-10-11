# F5.1 — motor independiente Dijkstra

## Alcance y convenciones

PHP puro (PHP >= 8.1), clases finales y `require_once`, siguiendo la carga
explícita de las pruebas existentes. Se crea `services/` porque el backend no
tenía esa carpeta. PHPUnit 10.5 y su autoload existente; sin nuevas dependencias.
No utiliza bootstrap, sesión, PDO, BD, migraciones, frontend, vehículos ni
cuadrillas. No modifica módulos operativos. El ejemplo es exclusivamente CLI.

## Contrato del grafo

`GrafoDijkstra` recibe un array validado o carga JSON con `fromJson`/`fromFile`.
El objeto raíz requiere exactamente `version`, `classification`, `cost_unit`,
`weight_meaning`, `nodes` y `edges`. Los cuatro metadatos son textos no vacíos.
`nodes` es una lista no vacía; `edges` puede ser una lista vacía.

- Nodo: `id` único, sensible a mayúsculas, patrón `[A-Za-z][A-Za-z0-9_-]*`.
  Opcionalmente `coordinates: {x, y}`, números finitos para un plano ilustrativo.
- Arista: `from`, `to`, `weight`; ambos extremos deben existir. Es dirigida:
  A → B no implica B → A. Se rechazan aristas duplicadas con el mismo par ordenado;
  los bucles son admisibles. No se infieren conexiones desde coordenadas.
- Peso: entero o flotante finito >= 0; admite cero. Rechaza negativos, NaN,
  infinitos, strings numéricos, booleanos y null. No interpreta unidades.
- Campos ausentes/desconocidos y estructuras inválidas: `InvalidArgumentException`.
  JSON mal formado: `JsonException`; archivo ilegible: `RuntimeException`.

La instancia no ofrece mutadores. Internamente ordena nodos y vecinos con
`SORT_STRING`. Las coordenadas no participan del algoritmo. El JSON versionado
`data/grafos/f5-demo-v1.json` contiene seis nodos y ocho aristas explícitas.
Su clasificación es **simulación académica**: no representa calles reales de
Montevideo. Los pesos son unidades de costo simulado, sin equivalencia con
metros, minutos, combustible ni capacidad operativa. F está aislado.
Cambiar nodos/aristas/pesos requiere una nueva versión del conjunto; no hay
registro externo ni comprobación automática de unicidad global de versiones.

## Algoritmo y desempate

`Dijkstra::shortestPath($graph, $origin, $destination)` valida ambos extremos,
inicializa el origen con 0 y los demás con infinito. Repite la selección del
nodo pendiente de menor costo y relaja sus aristas salientes. Al fijar un nodo,
su distancia es mínima: con pesos no negativos, pasar por nodos aún pendientes
no puede producir un costo inferior. Finaliza al fijar el destino o cuando no
quedan nodos alcanzables. Los predecesores permiten reconstruir el camino en
sentido inverso. Solo se actualizan nodos no fijados, evitando ciclos de
predecesores incluso con ciclos de peso cero.

Empate de selección: menor identificador en orden binario de strings.
Empate de relajación: conservar el primer predecesor; solo sustituir con costo
estrictamente menor. El resultado es reproducible aunque se reordenen nodos o
aristas de entrada. No promete el camino lexicográficamente menor completo ni
el de menos saltos entre todos los caminos de igual costo.

La selección lineal cuesta O(V²); recorrer vecinos cuesta O(E), por lo que el
tiempo total es O(V² + E). Memoria del grafo O(V + E), memoria adicional por
consulta O(V). Es una implementación deliberadamente sencilla para un conjunto
pequeño; una cola de prioridad sería apropiada para grafos grandes dispersos.

Salida: `graph_version`, `cost_unit`, `origin`, `destination`, `reachable`,
`cost`, `path`. Inaccesible: false/null/[]; origen igual a destino existente:
true/0/[origen], aun si está aislado. Nodo inexistente: excepción, no confundir
con inaccesibilidad. Los costos se calculan como flotantes PHP: pueden tener
redondeo binario; el empate compara igualdad numérica exacta, sin tolerancias.
Un desbordamiento del costo durante una relajación aborta con `OverflowException`,
sin devolver infinito como resultado. Para costos exactos futuros conviene
definir una unidad entera y límites antes de integrar datos operativos.

## Defensa reproducible

Desde `programacion/backend`, sin servidor ni BD:

```console
php scripts/demo_dijkstra.php A E
```

```json
{
  "graph_version": "f5-demo-v1",
  "cost_unit": "unidades de costo simulado",
  "origin": "A",
  "destination": "E",
  "reachable": true,
  "cost": 6,
  "path": ["A", "B", "D", "E"]
}
```

A → B → D → E cuesta 2 + 3 + 1 = 6. A → C → D → E también cuesta 6:
se fija B antes que C y se conserva B como predecesor de D. La arista directa
A → E cuesta 12 y la alternativa A → C → E cuesta 11. `A F` devuelve
inaccesible; `E A` demuestra direccionalidad; `F F` devuelve costo cero.

## Distancia mínima frente a múltiples visitas

Dijkstra minimiza el costo entre dos nodos sobre aristas ya definidas. Si los
pesos fueran distancias, calcularía distancia mínima; aquí minimiza costo
abstracto. No determina el orden óptimo de visitar varios contenedores ni
resuelve TSP/VRP, asignaciones, capacidades, ventanas horarias o restricciones
de vehículos. Concatenar caminos mínimos en un orden dado no demuestra que ese
orden de visitas sea óptimo. Las coordenadas de visualización no sustituyen
una red vial ni constituyen evidencia geográfica.

## Validación y continuidad

Pruebas dirigidas: mínimo y reconstrucción, aristas dirigidas, desconexión,
origen igual a destino, nodos inválidos, pesos inválidos/cero, ciclos de costo
cero, empates y reordenamientos, contrato JSON, metadatos, overflow y archivo
inexistente. Se ejecutan sin conexiones ni cambios de BD:

```console
php -l services/GrafoDijkstra.php
php -l services/Dijkstra.php
php -l scripts/demo_dijkstra.php
php -l tests/DijkstraTest.php
php vendor/phpunit/phpunit/phpunit --do-not-cache-result tests/DijkstraTest.php
git diff --check
git status --short
```

Validación realizada con PHP 8.1.25 y PHPUnit 10.5.64: **12 tests, 71
aserciones, PASS**, sin skips; los cuatro archivos PHP pasan `php -l`.
El ejemplo A → E devuelve costo 6 y camino A → B → D → E.

GO para continuar F5.2 sobre este contrato: las pruebas dirigidas pasan.
La integración operativa requiere definir previamente el alcance de F5.2,
una fuente válida de aristas y unidades, la política de versionado y los
límites de costos. F5.1 no habilita por sí misma uso en producción o en la BD habitual.
