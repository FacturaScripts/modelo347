# Modelo347: contexto del equipo

Esta carpeta guarda **por qué el código es como es**: las decisiones que no se ven leyendo una sola clase, para que
todo el equipo y los agentes trabajen con el mismo contexto. Lee este índice y abre solo el tema o la decisión que
necesites.

Se puede dividir en tantos archivos y subcarpetas como haga falta; este índice dice qué hay en cada uno y se actualiza
en el mismo trabajo que cambia el código.

## Temas

- [`01-arquitectura.md`](01-arquitectura.md): piezas del plugin, qué extiende del core y cómo encajan.
- [`02-calculo.md`](02-calculo.md): origen facturas o contabilidad, agrupación, importe 3.005,06, `excluir347`, IRPF y series sin IVA.
- [`03-fichero-aeat.md`](03-fichero-aeat.md): registros tipo 1 y 2, ISO-8859-1, justificante, hoja D y NIF del operador comunitario.
- [`04-api.md`](04-api.md): endpoint `GET /api/3/modelo347`, parámetros, respuesta y errores.
- [`05-emails.md`](05-emails.md): notificaciones, cola de trabajos, escalonado y envío selectivo.
- [`06-evolucion.md`](06-evolucion.md): etapas del plugin según el historial git.

## Decisiones

- [`0001-fichero-iso-8859-1.md`](Decisiones/0001-fichero-iso-8859-1.md): por qué el TXT va en ISO-8859-1 y sin escribirse en disco.
- [`0002-prefijo-vies-del-core.md`](Decisiones/0002-prefijo-vies-del-core.md): de dónde sale el prefijo del NIF del operador comunitario.
- [`0003-notificaciones-personalizables.md`](Decisiones/0003-notificaciones-personalizables.md): por qué el texto de los emails es una notificación editable.
- [`0004-calculo-en-lib-compartido.md`](Decisiones/0004-calculo-en-lib-compartido.md): por qué el cálculo está en `Lib/Modelo347`.
- [`0005-api-informativa.md`](Decisiones/0005-api-informativa.md): por qué la API solo lee y devuelve `resultado` nulo.
- [`0006-un-evento-por-destinatario.md`](Decisiones/0006-un-evento-por-destinatario.md): por qué un evento por tercero y un único worker.
- [`0007-escalonar-el-envio.md`](Decisiones/0007-escalonar-el-envio.md): por qué los emails se escalonan con `SEND_INTERVAL` y `MIN_GAP_MS`.
