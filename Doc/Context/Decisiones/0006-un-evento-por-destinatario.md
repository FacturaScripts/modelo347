# 0006. Un evento de la cola por destinatario y un único worker

- Fecha: 2026-11-26
- Commits: `5fa9821`
- Estado: vigente

## Contexto

El envío de emails tenía varios fallos: el aviso de encolados salía aunque no se encolara nada, los terceros con el mismo email se saltaban sin avisar, solo se miraba el email de la ficha, todo el lote iba en un evento (si el proceso caía se repetía el envío a todos) y pulsar dos veces «Enviar» duplicaba los emails.

## Decisión

`Lib/Modelo347Email` prepara los destinatarios y encola un evento por cada uno. Busca el email en la ficha, en la dirección predeterminada y en el resto de direcciones. No encola si queda un envío pendiente del mismo ejercicio y tipo, y usa `Kernel::lock` contra peticiones simultáneas. Un único `Modelo347EmailWorker` sustituye a los dos anteriores y sigue procesando el formato antiguo de evento.

## Consecuencias

- Cada tercero recibe su propio email aunque comparta dirección con otro.
- Mientras quede un evento pendiente del mismo ejercicio y tipo, el envío completo no se puede repetir. El selectivo no hace esa comprobación.
