# Envío de emails a clientes y proveedores

El plugin puede enviar a cada tercero declarado una carta informativa con sus importes trimestrales. El envío pasa por la cola de trabajos del core, un evento por destinatario, escalonado en el tiempo.

## Cómo es hoy

Flujo:

1. **Disparo**: el botón «Enviar Email» de la pantalla (`Controller/Modelo347::sendEmailsAction()`, acción `send-emails`, con `sendtype` = `customers`, `suppliers` o `both`) o el botón «Seleccionar» (`Controller/Modelo347Email`, acción `send-selected` por fetch). El envío completo exige permiso de modificación y el email configurado (`NewMail::canSendMail()`); el selectivo, además, valida el token del formulario. El botón «Seleccionar» solo se muestra con permiso de modificación.
2. **Destinatarios** (`Lib/Modelo347Email::recipients()`): el email se toma de la ficha y, si no es válido, de la dirección predeterminada o de cualquier otra dirección del tercero (`findEmail()`). Dos terceros con el mismo email reciben cada uno el suyo. Los que no tienen email se avisan en el log.
3. **Encolado** (`queue()` y `queueRecipients()`): comprueba que la notificación exista y esté activa, toma el bloqueo `modelo347-emails-<ejercicio>-<tipo>` con `Kernel::lock`, rechaza el envío si queda uno pendiente del mismo ejercicio y tipo (`hasPending()`) y crea un evento `Modelo347.SendCustomersEmails` o `Modelo347.SendSuppliersEmails` por destinatario con `WorkQueue::sendFuture()`. El valor del evento es el código del ejercicio y los parámetros llevan email, nombre, CIF/NIF, `t1`..`t4`, total, importe mínimo, año e `idempresa`. El envío selectivo usa `queueRecipients()` sin la comprobación de pendientes.
4. **Escalonado**: cada evento se programa `SEND_INTERVAL` (2) segundos después del anterior, a continuación del último email del 347 pendiente, de clientes o de proveedores (`firstDelay()`). Como el cron ejecuta seguidos todos los eventos vencidos, `Worker/Modelo347EmailWorker` deja además al menos `MIN_GAP_MS` (500) milisegundos entre dos envíos del mismo proceso (`waitForGap()`). Un envío suelto no espera.
5. **Envío** (`Worker/Modelo347EmailWorker::sendEmail()`): carga la notificación, formatea los importes con `Tools::money()`, sustituye las etiquetas con `MailNotifier::getText()` y envía con `NewMail`. El remitente es la empresa del ejercicio (`idempresa` del evento) con su nombre corto. El año es el de la fecha de inicio del ejercicio.

Notificaciones (`Init::createEmailNotifications()`): `modelo347-customers` y `modelo347-suppliers`, creadas al instalar o actualizar con asunto y cuerpo traducidos y activadas. Si ya existen solo se reescribe un asunto o cuerpo vacío o igual a su clave de traducción, para no pisar el texto personalizado. Etiquetas: `{name}`, `{cifnif}`, `{email}`, `{year}`, `{amount}`, `{t1}`..`{t4}`, `{total}` y `{deadline}` (20 de febrero del año siguiente al declarado). Si la notificación no existe o está desactivada no se encola ni se envía nada.

El worker también procesa eventos con el formato anterior (todos los destinatarios en el parámetro `data`) para no perder envíos pendientes de versiones anteriores.

La vista previa de `Controller/Modelo347Email` sustituye `{amount}` y `{year}` y resalta el resto de etiquetas. La lista de destinatarios (dos columnas con buscador, términos con `%`) la gestiona `Assets/JS/Modelo347Email.js`; los importes se recalculan en el servidor, no se fían del navegador.

## Por qué

Ver [0006](Decisiones/0006-un-evento-por-destinatario.md), [0003](Decisiones/0003-notificaciones-personalizables.md) y [0007](Decisiones/0007-escalonar-el-envio.md). Resumen: primero se añadió un worker por tipo con la carta fija (`699e59c`), después notificaciones editables (`16da290`) y selección de destinatarios (`94f8501`), se reescribió el envío por fallos de fiabilidad (`5fa9821`) y se escalonó para no saturar el correo (`55246e6`).

## Al cambiarlo

- `Test/main/Modelo347EmailTest.php` cubre destinatarios, pendientes y escalonado.
- Si cambias los nombres de eventos (`EVENT_*`), mantén el registro del worker en `Init.php`: los eventos ya encolados llevan el nombre antiguo.
- El core no reintenta los eventos: si un worker falla, el evento queda hecho. Por eso, cuando `NewMail::send()` falla, el worker encola otro evento igual `RETRY_DELAY` segundos (300) después, con el número de intento en el parámetro `attempt` (así no se toma por duplicado), hasta `MAX_ATTEMPTS` (3) intentos contando el primero. Cada reintento deja el aviso `347-email-retry` y el último fallo, `347-email-not-sent`. No se reintentan los destinatarios sin email ni los envíos con la notificación inexistente o desactivada: volver a intentarlo no los arreglaría. El ejercicio, que va en el valor del evento, se pasa a `sendEmail()` como `codejercicio` para poder reencolarlo.
- El envío selectivo (`Controller/Modelo347Email`) no comprueba si hay envíos pendientes (`hasPending()`), a diferencia del envío completo: está pensado para reenviar la carta a terceros concretos.
