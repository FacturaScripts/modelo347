# Modelo347
Plugin para FacturaScripts con el modelo 347 para la hacienda española.
- https://facturascripts.com/plugins/modelo347

## Estructura y API
El plugin sigue la misma estructura que el resto de modelos fiscales: `Lib/Modelo347` (cálculo), `Lib/Modelo347Api` (respuesta de la API) y `Lib/Modelo347File` (fichero de la AEAT).

`GET /api/3/modelo347` es de solo lectura: `periodo` opcional (solo `ANUAL`), `codejercicio` o `idempresa` y `anio`, `importe` (por defecto 3.005,06), `origen` (`facturas` o `contabilidad`), `agrupar` (`tercero` o `cifnif`) y `excluir_irpf`. Devuelve el resumen, las casillas 01-02, el detalle de clientes y proveedores y los avisos. Es informativo: `resultado` vale `null`. Una clave API sin acceso completo necesita el permiso de lectura del recurso `modelo347`.

## Issues / Feedback
https://facturascripts.com/contacto

## Links
- [Curso de FacturaScripts](https://www.youtube.com/watch?v=rGopZA3ErzE&list=PLNxcJ5CWZ8V6nfeVu6vieKI_d8a_ObLfY)
- [Programa de contabilidad gratis para autónomos](https://facturascripts.com/software-contabilidad)
- [Programa para hacer facturas gratis](https://facturascripts.com/programa-para-hacer-facturas)
- [Programa para hacer presupuestos gratis](https://facturascripts.com/programa-de-presupuestos)
