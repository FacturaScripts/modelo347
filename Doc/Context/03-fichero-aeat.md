# Fichero TXT de la AEAT

`Lib/Modelo347File.php` genera el fichero de presentación del modelo 347 (`modelo_347_<codejercicio>.347`) con el diseño de registro de la AEAT. Solo tiene métodos estáticos.

## Cómo es hoy

`Modelo347File::generate($codejercicio, $customersData, $suppliersData, $justificante, $declarationType, $justificanteAnterior)` devuelve el contenido ya convertido de UTF-8 a **ISO-8859-1**. El controlador (`downloadTxtAction()`) lo envía como descarga directa con `Content-Type: text/plain; charset=ISO-8859-1`, sin escribirlo en `MyFiles`.

Estructura:

1. **Registro tipo 1** (declarante, una línea): modelo `347`, año de la fecha de inicio del ejercicio, NIF, nombre, teléfono y persona de contacto (administrador de la empresa), número de justificante, tipo de declaración (`C` complementaria, `S` sustitutiva o blanco), justificante anterior, número total de personas y entidades, e importe total anual.
2. **Registros tipo 2, hoja `D`**, uno por cliente y luego uno por proveedor. Clave de operación `B` para clientes (ventas) y `A` para proveedores (compras). Llevan importe anual, importes de los cuatro trimestres, provincia y país, NIF del declarado y NIF del operador comunitario.

Convenciones del formato:

- Texto en mayúsculas, sin acentos (`sanitize()`), recortado y rellenado por caracteres (no por bytes), de modo que la Ñ cuente una posición (`8cd6bd3`). La Ñ se conserva (`0f1bc22`). Los nombres llegan de la base de datos con las comillas escapadas por `Tools::noHtml()`: `&quot;` se cambia por un guion y `&#39;` (apóstrofo) y el acento agudo `´` por un apóstrofo.
- Importes (`formatAmount()`): 16 posiciones, primer carácter `N` si es negativo o blanco; el resto, céntimos sin separador, con ceros a la izquierda.
- Provincia: `getProvincia()` traduce el nombre a código de dos cifras; si no se reconoce o está vacía, `99`. País: vacío para España, código ISO de dos letras para el resto.
- NIF del declarado (`checkCifNif()`): nueve espacios si el país no es España; en caso contrario el CIF/NIF rellenado con ceros a la izquierda.
- NIF del operador comunitario (17 posiciones): solo para terceros de otros Estados miembros de la UE. Prefijo del NIF-IVA con `Vies::vatPrefix()` del core (EL para Grecia) y el número sin el prefijo. Ver [0002](Decisiones/0002-prefijo-vies-del-core.md).
- Número de justificante: si el usuario lo indica se rellena a 13 con ceros; si no, se genera `347` + año + `000001` (p. ej. `3472025000001`), porque la AEAT rechaza justificantes a cero en ficheros (error 1011, `a727e22`).
- Cada registro de tipo 2 se separa con salto de línea; las posiciones fijas están comentadas en el propio código.

Datos que pide el usuario en la pantalla: tipo de declaración, justificante y justificante anterior (`6e02d7f`). El controlador deja solo dígitos en los justificantes y limita el tipo a `C` o `S`. Antes de generar el TXT, `validateJustificantes()` exige que el justificante indicado tenga el formato de la AEAT (13 dígitos que empiezan por `347`, `isValidJustificante()`) y que una declaración complementaria o sustitutiva traiga el justificante anterior con ese formato; si no, avisa (`347-invalid-justificante`, `347-previous-justificante-required`) y no descarga nada, porque la AEAT rechazaría el fichero.

## Por qué

- El TXT se añadió en febrero de 2023 (`c2571bb`, tarea 2167) y tuvo muchas correcciones seguidas de blancos, ceros y formato de importes (febrero de 2023, febrero-marzo de 2024). Es la parte más corregida del plugin.
- En febrero de 2026 se corrigieron errores de validación de la AEAT en el registro tipo 2: E010330 y 20501 (`cd631e1`), campo BDNS a `000000`, importe de criterio de caja a ceros, NIF extranjero en blanco (el código de país del modelo es Alpha-3 y el ISO es Alpha-2, y se compara con `Paises::get()`) y alineación a la izquierda con ceros del total de personas.
- Codificación ISO-8859-1: [0001](Decisiones/0001-fichero-iso-8859-1.md).

## Al cambiarlo

- Cada registro debe medir 500 caracteres (500 bytes en ISO-8859-1). Hay tests por posición y por codificación en `Test/main/Modelo347FileTest.php`; actualízalos con el cambio.
- Prueba con nombres que tengan Ñ y caracteres multibyte, y con terceros de la UE, de fuera de la UE y sin país.
- El importe total del registro tipo 1 se acumula al generar los registros de tipo 2 (`self::$total`), por eso `generate()` los construye antes que el registro del declarante, aunque luego se escriba primero. Respeta ese orden.
