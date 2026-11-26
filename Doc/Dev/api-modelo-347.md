El plugin Modelo347 añade a la API REST un endpoint de **solo lectura** con los datos del modelo 347: `GET /api/3/modelo347`. Devuelve los clientes y proveedores a declarar de un ejercicio con los mismos importes que la pantalla del plugin, junto con los totales, las casillas del resumen y los avisos. No genera el fichero de la AEAT, no envía emails ni crea, modifica o borra ningún registro.

## 1. Autenticación y permisos

Como el resto de endpoints, se autentica con una clave API en la cabecera `Token`. Una clave con acceso completo puede llamarlo sin más; una con acceso limitado necesita el permiso de lectura del recurso `modelo347`.

El plugin registra la ruta y el recurso en `FacturaScripts\Plugins\Modelo347\Init`, con `Kernel::addRoute('/api/3/modelo347', 'ApiModelo347', -1)` y `ApiRoot::addCustomResource('modelo347')`, por lo que el recurso aparece también en el listado de la raíz de la API.

## 2. Parámetros

Todos son opcionales y se pasan en la query string:

| Parámetro | Qué indica |
|---|---|
| `codejercicio` | Ejercicio a declarar. Si se indica, manda sobre `idempresa` y `anio`: la empresa es la del ejercicio |
| `idempresa` | Empresa declarante. Por defecto, la empresa predeterminada |
| `anio` | Año natural, de cuatro cifras, del ejercicio de esa empresa cuya fecha de inicio cae en ese año. Por defecto, el actual |
| `periodo` | Solo `ANUAL`, que es el valor por defecto |
| `importe` | Importe mínimo anual por tercero. Por defecto, 3005.06; admite coma o punto decimal |
| `origen` | `facturas` (por defecto) o `contabilidad` |
| `agrupar` | `tercero` (por defecto) o `cifnif` |
| `excluir_irpf` | `1`, `true`, `si` u `on` para excluir las facturas con retención de IRPF |

El código de un ejercicio no tiene por qué coincidir con su año (`0001`, `GE25`...), por eso el año se busca por la fecha de inicio del ejercicio de la empresa.

## 3. Ejemplo

```bash
curl -s -H "Token: TU_CLAVE_API" \
  "https://tu-instalacion/api/3/modelo347?codejercicio=2026&importe=3005.06"
```

La respuesta, abreviada:

```json
{
    "modelo": "347",
    "plugin_version": 3.61,
    "empresa": {"idempresa": 1, "nombre": "Empresa Ejemplo SL", "cifnif": "00000000T"},
    "ejercicio": {
        "codejercicio": "2026",
        "nombre": "2026",
        "fechainicio": "2026-01-01",
        "fechafin": "2026-12-31",
        "estado": "ABIERTO"
    },
    "periodo": "ANUAL",
    "fechainicio": "2026-01-01",
    "fechafin": "2026-12-31",
    "parametros": {"importe": 3005.06, "origen": "facturas", "agrupar": "tercero", "excluir_irpf": false},
    "resumen": {
        "num_clientes": 2,
        "num_proveedores": 0,
        "total_clientes": 15942.47,
        "total_proveedores": 0,
        "clientes_t1": 13798.92,
        "clientes_t2": 1136.57,
        "clientes_t3": 475.1,
        "clientes_t4": 531.88,
        "proveedores_t1": 0,
        "proveedores_t2": 0,
        "proveedores_t3": 0,
        "proveedores_t4": 0
    },
    "casillas": {"01": 2, "02": 15942.47},
    "resultado": null,
    "detalle": {
        "clientes": [
            {
                "codigo": "2",
                "nombre": "Cliente Ejemplo",
                "cifnif": "00000001R",
                "codpais": "ESP",
                "codpostal": "30300",
                "ciudad": "Cartagena",
                "provincia": "Murcia",
                "t1": 11899.33,
                "t2": 386.37,
                "t3": 0,
                "t4": 32.04,
                "total": 12317.74
            }
        ],
        "proveedores": []
    },
    "avisos": []
}
```

## 4. Qué devuelve cada bloque

- **`resumen`**: número de clientes y proveedores, su total anual y su total por trimestre.
- **`casillas`**: las del registro del declarante del TXT. La `01` es el número de personas y entidades declaradas y la `02`, el importe total anual de sus operaciones.
- **`resultado`**: siempre `null`. El modelo 347 es informativo y no tiene importe a ingresar.
- **`detalle`**: un elemento por cliente y por proveedor con su código, nombre, CIF/NIF, país, dirección y los importes de cada trimestre y el total.
- **`avisos`**: textos en español con los mismos avisos que la pantalla (empresa sin administrador o teléfono, terceros sin provincia o país) y, además, los declarados sin CIF/NIF. Si no hay nada que declarar, un aviso lo indica.

El importe de cada factura es su total más la retención de IRPF menos los suplidos, y no se cuentan las facturas excluidas del 347 ni las de series sin IVA. Cuando `origen` es `contabilidad`, se suman las partidas de las subcuentas de clientes y proveedores, sin los asientos de esas mismas facturas excluidas. El detalle por cliente y proveedor se devuelve siempre: no hay parámetro para quitarlo.

## 5. Errores

Los errores devuelven `{"status": "error", "message": "..."}` con estos códigos:

| Código HTTP | `message` | Causa |
|---|---|---|
| 400 | `invalid-period` | `periodo` no es `ANUAL`. Incluye `allowed` |
| 400 | `invalid-parameter` | `importe`, `origen`, `agrupar` o `excluir_irpf` no son válidos. Incluye `parameter` y, si procede, `allowed` |
| 400 | `invalid-year` | `anio` no tiene cuatro cifras |
| 404 | `exercise-not-found` | No existe el ejercicio o la empresa no tiene ninguno que empiece en ese año |
| 404 | `company-not-found` | La empresa no existe |
| 405 | `method-not-allowed` | Se ha llamado con otro método distinto de `GET` |

## 6. Dónde está el código

- **`FacturaScripts\Plugins\Modelo347\Controller\ApiModelo347`**: valida el método, lee los parámetros y devuelve el JSON.
- **`FacturaScripts\Plugins\Modelo347\Lib\Modelo347Api`**: valida los parámetros, resuelve la empresa y el ejercicio y monta la respuesta con `Modelo347Api::build(array $params)`.
- **`FacturaScripts\Plugins\Modelo347\Lib\Modelo347`**: el cálculo, que comparten la pantalla y la API.

Desde otro plugin, usa siempre las clases a través de `FacturaScripts\Dinamic\Lib\...`.
