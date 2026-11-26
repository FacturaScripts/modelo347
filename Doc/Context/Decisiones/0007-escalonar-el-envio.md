# 0007. Los emails se programan escalonados en el tiempo

- Fecha: 2026-11-26
- Commits: `55246e6`
- Estado: vigente

## Contexto

Al enviar el 347 a cientos de terceros, todos los eventos salían a la vez y el servidor de correo, o el del destinatario, podía bloquearlos por envío masivo.

## Decisión

`Modelo347Email::queueRecipients()` programa cada email con `WorkQueue::sendFuture()` `SEND_INTERVAL` (2) segundos después del anterior, a continuación del último email del 347 pendiente (clientes y proveedores comparten servidor). Como el cron ejecuta seguidos los eventos vencidos, `Modelo347EmailWorker` deja al menos `MIN_GAP_MS` (500) milisegundos entre dos envíos del mismo proceso.

## Consecuencias

- Un envío de N emails tarda al menos unos 2·N segundos en completarse.
- Los dos valores son constantes de clase; el historial no indica que se puedan configurar.
