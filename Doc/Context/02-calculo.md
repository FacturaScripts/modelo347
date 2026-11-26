# Cálculo del modelo 347

`Lib/Modelo347.php` obtiene, para un ejercicio, los clientes y proveedores cuyo volumen anual alcanza el importe mínimo, con el desglose por trimestres.

## Cómo es hoy

Constructor: `new Modelo347($codejercicio, $amount, $examine, $grouping, $excludeIrpf)`; después `calculate()` rellena `customersData`, `suppliersData` y sus totales. Cada fila lleva `cifnif`, nombre (`cliente` o `proveedor`), `codpais`, `codpostal`, `ciudad`, `provincia`, `tipoidfiscal`, `t1`..`t4` y `total`, indexada por código del tercero.

- **Importe mínimo**: `DEFAULT_AMOUNT = 3005.06`. Se descartan las filas con `total` menor que el importe (el total igual al importe se incluye). Se aplica después de agrupar.
- **Origen** (`examine`):
  - `invoices` (facturas): consulta SQL sobre `facturascli` / `facturasprov` filtrada por `codejercicio`. El ejercicio ya identifica a la empresa. Excluye las facturas con `excluir347` y las series sin IVA (`series.siniva`). Importe: `total + totalirpf - totalsuplidos`. Con `excluir_irpf` descarta además las facturas con `irpf` distinto de 0.
  - `accounting` (contabilidad): suma el debe (clientes, cuenta especial `CLIENT`) o el haber (proveedores, `PROVEE` y `ACREED`) de las subcuentas del ejercicio, agrupado por mes. Se ignoran los asientos con `operacion` (apertura, cierre, regularización). El tercero se busca por `codsubcuenta`; si no existe, se salta. Para que los dos orígenes den lo mismo, `getExcludedEntriesSql()` quita además los asientos (`idasiento`) de las facturas de venta y compra del ejercicio que el cálculo por facturas tampoco cuenta: las marcadas con `excluir347`, las de series sin IVA y, con `excluir_irpf`, las que llevan retención. Los asientos que no vienen de una factura (cobros, pagos, asientos manuales) no se filtran.
- **Agrupación** (`grouping`): `customer-supplier` (una fila por código) o `cifnif`. Con `cifnif`, los terceros con el mismo CIF/NIF se acumulan en la fila del primero encontrado (`getCodeForTotal()`).
- **Trimestre**: `groupTotals()` asigna el mes al trimestre y suma al total.
- **Avisos** (`checkData()`): empresa sin administrador o sin teléfono, declaración sin datos y terceros sin provincia o país. Devuelve claves de traducción con contexto; la pantalla las pasa al log y la API las traduce al español.
- La dirección sale de `getDefaultAddress()` del tercero.

## Por qué

- Origen contable y agrupación por CIF/NIF: añadidos en `1244462` (2023-02) y `973f071` (2023-06). La agrupación por CIF permite sumar fichas duplicadas de un mismo tercero.
- Excluir series sin IVA (`acd26b2`, 2023-10): el historial no detalla el motivo.
- Acreedores de la contabilidad (`b0ec201`): bug corregido al obtener sus datos.
- Empresa y año salen del ejercicio: ver [0004](Decisiones/0004-calculo-en-lib-compartido.md).
- El importe de la factura suma `totalirpf` y resta `totalsuplidos`; por el código parece que se busca el importe de la operación sin retención, pero el historial no lo explica.
- Cambios de SQL repetidos: columna `irpf` ambigua (`371ff63`), conflicto con `codejercicio` (`3d83918`), sustitución de `totaleuros` por `total` (`4db4067`), importes (`d2b468b`, `3f7d50f`). Al tocar la consulta, comprueba columnas con prefijo de tabla y las dos bases de datos (MySQL y PostgreSQL: `getAccountingInfo()` tiene una consulta para cada una).

## Al cambiarlo

- Prueba facturas y contabilidad, con y sin agrupar por CIF/NIF, en MySQL y PostgreSQL.
- Si añades un filtro, decide si aplica también al origen contable.
- Las filas son arrays con claves distintas para clientes (`cliente`) y proveedores (`proveedor`).

## Campo excluir347

Booleano (por defecto falso) en `clientes`, `proveedores`, `facturascli` y `facturasprov` (`Extension/Table/*.xml`). Se edita en las fichas (`Extension/XMLView/`) y en el modal de cabecera de la factura (`Mod/SalesHeaderHTMLMod.php`, `Mod/PurchasesHeaderHTMLMod.php`). Una factura nueva hereda el valor del tercero (`setSubject()` de `Extension/Model/Factura*`); si se genera desde pedido o albarán, que no tienen la columna, lo hereda `Extension/Lib/BusinessDocumentGenerator.php` (`354437f`, roadmap 5452). Las consultas usan `$model->hasColumn()` en lugar de `property_exists()` para dar compatibilidad con los modelos nuevos (`1080b6a`).
