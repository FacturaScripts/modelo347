# 0001. El fichero de la AEAT se genera en ISO-8859-1 y se envía sin escribirlo en disco

- Fecha: 2026-02-23
- Commits: `4c3833d`, `8cd6bd3`, `0f1bc22`
- Estado: vigente

## Contexto

El fichero `.347` de la AEAT tiene registros de posiciones fijas y la AEAT lo valida byte a byte. El contenido se montaba en UTF-8 y los nombres con Ñ o con caracteres multibyte producían registros de 499 bytes.

## Decisión

`Modelo347File::generate()` construye el texto en UTF-8, rellena y recorta por caracteres (`mb_*`) y convierte al final a ISO-8859-1. El controlador lo devuelve como descarga con `charset=ISO-8859-1`, sin escribirlo en `MyFiles`. La Ñ se conserva.

## Consecuencias

- No uses funciones por bytes (`strlen`, `substr`, `str_pad`) en el montaje del fichero.
- Los tests comprueban la codificación a nivel de bytes (`Test/main/Modelo347FileTest.php`).
