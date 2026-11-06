# Internacionalización

El frontend sirve las traducciones desde `public/assets/js/i18n/`. Los catálogos `es.js` y `en.js` comparten claves organizadas por módulo. El español es la fuente y el respaldo: si una clave no existe en el idioma seleccionado, se conserva su texto español.

`public/assets/js/i18n.js` expone `window.ZemynaI18n.t(clave, variables)` y el selector que se carga en las seis páginas públicas. El traductor recorre texto y atributos visibles del DOM, incluidos elementos que se agregan dinámicamente, y usa `textContent`/nodos de texto para no interpretar traducciones como HTML. Los mensajes con variables admiten marcadores como `{count}`.

La preferencia se guarda en una cookie `zemyna_language` limitada al directorio del frontend, con `SameSite=Lax` y duración de un año. No contiene datos de autenticación ni altera la sesión del backend. Los valores de formularios, estados transmitidos a las APIs y datos guardados no se modifican; solo se traduce su presentación.

## Agregar un idioma

1. Crear `public/assets/js/i18n/<código>.js`, siguiendo las claves de `es.js` y `en.js`.
2. Traducir las mismas claves; las claves ausentes mostrarán el español de respaldo.
3. Agregar el código a `supportedLanguages` en `public/assets/js/i18n.js` y una opción en el selector.
4. Cargar el nuevo archivo junto a `es.js` y `en.js` en cada página pública que incluya `i18n.js`.
5. Ejecutar `node --test tests/i18n.test.cjs` y ampliar las pruebas con las nuevas cadenas.

Las cadenas que todavía no estén catalogadas permanecen deliberadamente en español. Los nombres, direcciones y otros datos proporcionados por usuarios se muestran tal cual.
