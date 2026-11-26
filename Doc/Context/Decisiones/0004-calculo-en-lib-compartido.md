# 0004. El cálculo vive en `Lib/Modelo347`, compartido por todas las salidas

- Fecha: 2026-11-26
- Commits: `58bcff6`
- Estado: vigente

## Contexto

El cálculo (más de 500 líneas) estaba en el controlador. Hacía falta darle a la API las mismas cifras que la pantalla y seguir la estructura de los demás plugins de modelos fiscales.

## Decisión

El cálculo pasa a `Lib/Modelo347`. Lo usan la pantalla, el Excel, el TXT, los emails y la API. La empresa y el año salen del ejercicio (`codejercicio`) y no se filtra por empresa ni por fechas aparte: el ejercicio ya identifica ambas. `Txt347Export` pasa a llamarse `Modelo347File` con el método `generate()`.

## Consecuencias

- Un cambio de cálculo cambia todas las salidas a la vez.
- Los tests antiguos se renombraron (`Txt347ExportTest` pasa a `Modelo347FileTest`).
