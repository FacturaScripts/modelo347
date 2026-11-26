# 0005. La API del 347 es de solo lectura e informativa

- Fecha: 2026-11-26
- Commits: `58bcff6`
- Estado: vigente

## Contexto

Se necesitaba consultar los datos del modelo desde `fs-mcp` sin pasar por la pantalla.

## Decisión

`GET /api/3/modelo347` (`Controller/ApiModelo347`, `Lib/Modelo347Api`) devuelve los terceros con sus importes, el resumen, las casillas 01 y 02 y los avisos. No genera ficheros ni envía emails. El campo `resultado` es siempre `null`: el 347 es informativo, no tiene cuota a ingresar. Es una deducción del código; el historial no lo explica con más detalle.

## Consecuencias

- Los métodos distintos de `GET` devuelven 405.
- Los valores de `origen` y `agrupar` de la API usan nombres en español y se traducen a las constantes de `Lib/Modelo347`.
