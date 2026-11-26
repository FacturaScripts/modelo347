# 0002. El NIF del operador comunitario usa el prefijo VIES del core

- Fecha: 2026-10-27 (primera versión); 2026-11-16 (uso del core)
- Commits: `2a15c22`, `ac95298`
- Estado: vigente

## Contexto

El NIF del operador comunitario empieza por las dos letras de su NIF-IVA. Coinciden con el código ISO salvo en Grecia (EL, no GR). Además, el core todavía incluye el Reino Unido en `Paises::MIEMBROS_UE`, y sus clientes y proveedores salían como operadores comunitarios.

## Decisión

Primero el plugin tenía su propia función `vatPrefix()` (EL para Grecia, el ISO para el resto), a la espera de que el core la tuviera (referencia en el commit: NeoRazorX/facturascripts#2059). Después se sustituyó por `Vies::vatPrefix()` del core. Del número se quitan tanto el prefijo como el código ISO, y se pasa a mayúsculas antes de quitar separadores.

## Consecuencias

- El plugin necesita un core con `Vies::vatPrefix()` (por eso `min_version` 2026.72).
- La exclusión del Reino Unido ya no está en el plugin; según el commit `2a15c22` se resolvía allí. El código actual comprueba `Paises::miembroUE()` del core: depende de que el core lo mantenga correcto.
