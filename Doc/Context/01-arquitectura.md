# Arquitectura

El plugin calcula el modelo 347 (operaciones con terceras personas) de la AEAT y lo ofrece de cuatro formas: pantalla, Excel, fichero TXT y emails a los terceros. Además expone un endpoint de API de solo lectura. Todo el cálculo vive en una sola clase de `Lib/` que comparten todas las salidas.

## Cómo es hoy

`facturascripts.ini`: versión 3.61, `min_version` 2026.72. No tiene `require`: no depende de otros plugins.

| Pieza | Archivo | Qué hace |
|---|---|---|
| Cálculo | `Lib/Modelo347.php` | Calcula clientes y proveedores a declarar, sus totales por trimestre y los avisos (`checkData()`). Origen facturas o contabilidad |
| Respuesta API | `Lib/Modelo347Api.php` | Valida parámetros, resuelve empresa y ejercicio y arma el JSON de `GET /api/3/modelo347` |
| Fichero AEAT | `Lib/Modelo347File.php` | Genera el TXT de presentación (registros tipo 1 y 2, ISO-8859-1) |
| Emails | `Lib/Modelo347Email.php` | Busca el email de cada tercero y encola un evento de la cola por destinatario, escalonado |
| Worker | `Worker/Modelo347EmailWorker.php` | Envía un email por evento con la notificación `modelo347-customers` o `modelo347-suppliers` |
| Pantalla | `Controller/Modelo347.php` | Filtros, tablas, Excel, TXT y envío completo de emails. Menú `reports` > submenú `taxation-spain` (Fiscalidad España) |
| Selección de destinatarios | `Controller/Modelo347Email.php` + `View/Modelo347Email.html.twig` + `Assets/JS/Modelo347Email.js` | Hereda de `Modelo347` y encola solo los terceros elegidos. Oculto del menú |
| API | `Controller/ApiModelo347.php` | Solo `GET`; delega en `Lib/Modelo347Api` |
| Campo `excluir347` | `Extension/Table/*.xml`, `Extension/Model/*`, `Extension/XMLView/*`, `Mod/*HeaderHTMLMod.php`, `Extension/Lib/BusinessDocumentGenerator.php` | Booleano en `clientes`, `proveedores`, `facturascli` y `facturasprov` para excluir del 347 |
| Arranque | `Init.php` | Carga extensiones y mods, registra el worker y la ruta de la API, crea las notificaciones |

`Init.php`:

- `init()` carga las cinco extensiones, añade los dos mods de cabecera, registra `Modelo347EmailWorker` para los eventos `Modelo347.SendCustomersEmails` y `Modelo347.SendSuppliersEmails`, y añade la ruta `/api/3/modelo347` con `ApiRoot::addCustomResource('modelo347')`.
- `update()` hace `new FacturaCliente()` y `new FacturaProveedor()` para forzar la comprobación de las tablas y que se creen las columnas (`a68fb12`), y crea las notificaciones por email.

Qué toca del core: extiende los modelos `Cliente`, `Proveedor`, `FacturaCliente`, `FacturaProveedor`, el `BusinessDocumentGenerator`, las vistas `EditCliente` y `EditProveedor`, y los mods `SalesHeaderHTML` y `PurchasesHeaderHTML`. Usa `WorkQueue`, `Kernel::lock`, `EmailNotification`, `MailNotifier`, `NewMail` y `Vies::vatPrefix()`.

## Por qué

- Hasta la versión 3.6 todo estaba en `Controller/Modelo347.php`. El commit `58bcff6` movió el cálculo a `Lib/Modelo347` para que pantalla, Excel, TXT, emails y API den las mismas cifras, y renombró `Txt347Export` a `Modelo347File` para seguir la estructura de los demás plugins de modelos fiscales (Modelo349, Modelo130).
- La API se añadió para `fs-mcp` (mensaje de `58bcff6`).
- Un único worker sustituye a los dos de clientes y proveedores (`5fa9821`); ver [05-emails.md](05-emails.md).

## Al cambiarlo

- Cualquier cambio de cálculo afecta a la vez a pantalla, Excel, TXT, emails y API: prueba las cinco salidas.
- `Controller/Modelo347Email` hereda de `Modelo347` y reutiliza `calculate()` y `loadFilters()`, y sustituye `defaultAction()` y `execAction()`.
- Un XMLView, una tabla o una clase nueva exigen reconstruir `Dinamic`.
- Los tests están en `Test/main/` (API, fichero, email, mods, facturas y generador). `install-plugins.txt` lista los plugins que necesitan los tests de los mods.
