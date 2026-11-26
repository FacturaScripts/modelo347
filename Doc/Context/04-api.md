# API de solo lectura

`GET /api/3/modelo347` devuelve los datos del modelo en JSON. Es informativa: no genera ficheros, no envía emails ni modifica registros.

## Cómo es hoy

- Ruta: `Kernel::addRoute('/api/3/modelo347', 'ApiModelo347', -1)` y `ApiRoot::addCustomResource('modelo347')` en `Init.php`. Una clave API sin acceso completo necesita permiso de lectura del recurso `modelo347`.
- `Controller/ApiModelo347.php` solo comprueba el método (cualquiera distinto de `GET` da 405 `method-not-allowed`), recoge los parámetros de la query string y llama a `Lib/Modelo347Api::build()`.
- Parámetros (todos opcionales): `codejercicio` (manda sobre los demás), `idempresa` (por defecto la predeterminada), `anio` (por defecto el actual), `periodo` (solo `ANUAL`), `importe` (por defecto 3005.06, acepta coma decimal, no negativo), `origen` (`facturas` o `contabilidad`), `agrupar` (`tercero` o `cifnif`) y `excluir_irpf` (`1`, `true`, `si`, `yes`, `on`; `0`, `false`, `no`, `off` o vacío).
- Con `idempresa` y `anio` se elige el ejercicio de esa empresa cuya fecha de inicio cae en el año.
- Respuesta 200: `modelo`, `plugin_version`, `empresa`, `ejercicio`, `periodo`, fechas, `parametros`, `resumen` (número y totales de clientes y proveedores y su desglose trimestral), `casillas` (`01` número de personas y entidades, `02` importe total anual, iguales al registro tipo 1 del TXT), `resultado` (siempre `null`), `detalle` (clientes y proveedores) y `avisos` (texto en español).
- Errores: 400 `invalid-period`, `invalid-parameter` (con `parameter` y, si procede, `allowed`) o `invalid-year`; 404 `exercise-not-found` o `company-not-found`; 405 si no es GET. Formato `{status: error, message, ...}`.
- Los avisos son los de `Modelo347::checkData('es_ES')` más los terceros a declarar sin CIF/NIF.
- `normalizePeriod()` acepta alias (`1T`..`4T`, `1`..`12`, `ANNUAL`) pero `build()` solo admite `ANUAL`; el resto devuelve `invalid-period`.

## Por qué

- Se creó junto con el traslado del cálculo a la Lib (`58bcff6`) para `fs-mcp`. Ver [0005](Decisiones/0005-api-informativa.md).
- La estructura (`Lib/Modelo347Api` con `build()`, `normalizePeriod()` y `resolveExercise()`) es la misma que usan los demás modelos fiscales. El historial no explica por qué se aceptan periodos de otros modelos (T1-T4, meses) si el 347 solo es anual; por el código, parece que se mantiene el normalizador común.

## Al cambiarlo

- Mantén la respuesta alineada con `Test/main/Modelo347ApiTest.php` y con el README del plugin.
- No hay parámetro `detalle`: a diferencia de los modelos trimestrales (303, 111, 130), el detalle por cliente y proveedor es el contenido del 347 y se devuelve siempre, igual que en el 349 y el 190. Si llega en la query string, se ignora.
