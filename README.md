# Modelo347
Plugin para FacturaScripts con el modelo 347 para la hacienda española.
- https://facturascripts.com/plugins/modelo347

## Estructura y API
El plugin sigue la misma estructura que el resto de modelos fiscales: `Lib/Modelo347` (cálculo), `Lib/Modelo347Api` (respuesta de la API) y `Lib/Modelo347File` (fichero de la AEAT).

`GET /api/3/modelo347` es de solo lectura: `periodo` opcional (solo `ANUAL`), `codejercicio` o `idempresa` y `anio`, `importe` (por defecto 3.005,06), `origen` (`facturas` o `contabilidad`), `agrupar` (`tercero` o `cifnif`) y `excluir_irpf`. Devuelve el resumen, las casillas 01-02, el detalle de clientes y proveedores y los avisos. Es informativo: `resultado` vale `null`. Una clave API sin acceso completo necesita el permiso de lectura del recurso `modelo347`.

## Envío de emails
El botón «Enviar Email» de la pantalla (requiere permiso de modificación y el email configurado) encola un email por cliente o proveedor con sus importes trimestrales; `Lib/Modelo347Email` prepara los destinatarios y `Worker/Modelo347EmailWorker` los envía en segundo plano. El email se toma de la ficha y, si no tiene, de la dirección predeterminada o de cualquier otra dirección del tercero. Dos terceros con el mismo email reciben cada uno el suyo. El año es el de la fecha de inicio del ejercicio, el remitente es la empresa del ejercicio y no se puede volver a enviar mientras quede un envío pendiente del mismo ejercicio y tipo.

El asunto y el texto del email salen de las notificaciones por email `modelo347-customers` y `modelo347-suppliers`, que el plugin crea al instalarse y que el usuario puede personalizar con las etiquetas `{name}`, `{cifnif}`, `{email}`, `{year}`, `{amount}`, `{t1}`..`{t4}`, `{total}` y `{deadline}`. Si la notificación no existe o está desactivada no se encola nada.

Para no saturar el servidor de correo, los emails no salen todos a la vez: cada uno se programa en la cola `Lib/Modelo347Email::SEND_INTERVAL` segundos (2) después del anterior, a continuación del último email del 347 que siga pendiente. Como el cron ejecuta seguidos todos los eventos ya vencidos, el worker deja además al menos `Modelo347EmailWorker::MIN_GAP_MS` milisegundos (500) entre dos envíos del mismo proceso.

El botón «Seleccionar» abre `Controller/Modelo347Email`, donde se eligen en dos columnas con buscador los clientes o proveedores concretos a los que enviar el email, con una vista previa de la notificación.

## Issues / Feedback
https://facturascripts.com/contacto

## Links
- [Curso de FacturaScripts](https://www.youtube.com/watch?v=rGopZA3ErzE&list=PLNxcJ5CWZ8V6nfeVu6vieKI_d8a_ObLfY)
- [Programa de contabilidad gratis para autónomos](https://facturascripts.com/software-contabilidad)
- [Programa para hacer facturas gratis](https://facturascripts.com/programa-para-hacer-facturas)
- [Programa para hacer presupuestos gratis](https://facturascripts.com/programa-de-presupuestos)
