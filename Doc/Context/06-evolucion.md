# Evolución

Etapas según el historial git (169 commits desde enero de 2020; no hay otras ramas relevantes aparte de una de trabajo `Tarea-753-enviar-email`).

| Etapa | Fechas | Qué cambia |
|---|---|---|
| Versión inicial | 2020-01 a 2022-03 | Controlador y vista twig con Excel. Arreglos de importes, columna `total` y selector de ejercicio (`e40e120`, `8c061bf`, `4db4067`). Descargar solo clientes o solo proveedores (`b22da96`) |
| Contabilidad y agrupación | 2023-02 a 2023-06 | Origen contable (`1244462`) y agrupación por CIF/NIF (`973f071`). Importe mínimo que no acabe en .06 |
| Fichero TXT | 2023-02 | Primera versión (`c2571bb`, v2.1) con docenas de correcciones de blancos, ceros e importes. Validaciones de datos de la empresa antes de descargar |
| Campo excluir347 | 2023-06 a 2025-06 | Campo en facturas (`20d33ee`, mods `531b5dc`), después en clientes y proveedores (`b2fdd84`) y herencia al crear facturas (`f0af468`). Series sin IVA excluidas (`acd26b2`) |
| Compatibilidad con el core | 2023-10, 2024-02, 2025-01, 2025-08, 2026-01 | Soporte para core 2023.06, 2023, 2024.5, 2025 y `hasColumn()` en lugar de `property_exists()` (`1080b6a`) |
| Tests y CI | 2024-07, 2025-06, 2026-05 | Tests de mods (`74ce345`), acciones de GitHub, workflow de releases y de tests por versiones de PHP |
| Corrección del TXT | 2026-02 | Errores AEAT E010330, 20501 y 1011, Ñ, ISO-8859-1, número de justificante y tipo de declaración (`cd631e1`..`6e02d7f`) |
| Envío de emails | 2026-05 y 2026-11 | Primer envío (`699e59c`), notificaciones (`16da290`), selección de destinatarios (`94f8501`), reescritura (`5fa9821`) y escalonado (`55246e6`) |
| Lib compartida y API | 2026-11-26 | Cálculo movido a `Lib/Modelo347`, `Txt347Export` renombrado a `Modelo347File`, endpoint de API (`58bcff6`) |

Otros cambios: submenú «modelos fiscales» (`304702c`), renombrado a «Fiscalidad España» (clave `taxation-spain`) al unificar el menú de informes con el resto de plugins fiscales, prefijo VIES del core (`ac95298`, `2a15c22`), generador de documentos con herencia de `excluir347` (`354437f`).

Partes abandonadas: `Txt347Export` (ahora `Modelo347File`), `Worker/Modelo347CustomersEmailWorker` y `Worker/Modelo347SuppliersEmailWorker` (sustituidos por `Modelo347EmailWorker`); sus eventos antiguos siguen soportados.

Limitación del análisis: se leyó el historial de este plugin (169 commits); no se consultaron las pull requests de GitHub.
