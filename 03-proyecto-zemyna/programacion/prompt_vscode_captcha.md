# Prompt para VS Code — Captcha casero (login, solicitud, reclamo)

> Asumí el captcha casero tipo "¿cuánto es X + Y?" (sin dependencias externas, no necesita
> internet el día de la demo). Si preferís reCAPTCHA v2 en su lugar, avisame y te armo esa
> versión.

```
Actuá como desarrollador PHP 8 + frontend. Antes de generar nada, mostrame la estructura
actual de login.html, solicitud.html y el formulario de reclamo/incidencia (si ya existe),
además de sus controladores asociados, para reusar el mismo estilo de validación y el
mismo contrato JSON {success, data, message, errors} que venimos usando.

Necesito agregar un captcha casero (pregunta matemática simple, ej. "¿Cuánto es 4 + 7?")
en 3 lugares: login, solicitud de retiro especial y reclamo/incidencia. Implementalo así,
un paso a la vez, esperando mi confirmación entre cada uno:

1. Helper compartido (ej. helpers/captcha.php): función generarCaptcha() que crea dos
   números aleatorios (1-10), guarda el resultado esperado en
   $_SESSION['captcha_resultado'] y devuelve el texto de la pregunta. Función
   validarCaptcha($respuestaUsuario) que compara contra $_SESSION['captcha_resultado'],
   devuelve bool, y borra el valor de sesión después de usarlo (para que no se pueda
   reintentar con el mismo captcha).

2. En cada HTML (login.html, solicitud.html, reclamo.html): agregar un <div> que muestre
   la pregunta (renderizada desde el backend al cargar la página, o vía fetch a un
   endpoint tipo api/captcha.php que devuelva {pregunta}) + un <input type="number"
   name="captcha_respuesta"> con label claro. Mantené el mismo estilo Bootstrap que el
   resto del formulario.

3. En cada controlador (AuthController, SolicitudController, ReclamoController /
   IncidenciaController): antes de procesar el resto de la validación, llamar a
   validarCaptcha($data['captcha_respuesta']). Si falla, responder con success=false,
   código 400, message "Captcha incorrecto, intentá de nuevo" y regenerar una pregunta
   nueva para el siguiente intento (no dejar la misma pregunta repetida).

4. api/captcha.php: endpoint GET que llama a generarCaptcha() y devuelve
   {"success": true, "data": {"pregunta": "..."}} — lo usan los 3 formularios para pedir
   una pregunta nueva al cargar o tras un intento fallido.

Reglas: no agregues librerías externas ni JS de terceros. Mantené los códigos HTTP y el
contrato JSON ya usados en el resto del proyecto. No toques la lógica de negocio de cada
formulario (login, solicitud, reclamo), solo agregá la verificación de captcha como paso
previo a esa lógica.
```
