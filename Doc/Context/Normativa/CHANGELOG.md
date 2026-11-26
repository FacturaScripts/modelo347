# CHANGELOG – Documentación oficial del modelo 347

## 2026-11-26 (primera ejecución, carpeta vacía)

### Qué se ha descargado
- **Páginas (41)** en `paginas/`, con la URL de origen en la primera línea: ficha del procedimiento GI27, ayuda técnica del
  347 (presentación por formulario y por fichero, consulta y baja de declaraciones, número de justificante, documentación
  complementaria), las instrucciones con todos sus capítulos (normativa aplicable, novedad 2025, plazo, obligados,
  excluidos, operaciones excluidas, desglose, importe, imputación temporal, forma de presentación y cumplimentación),
  plazos de presentación, preguntas frecuentes, ejercicios anteriores, la lista de diseños de registro (modelos 300 al 399)
  y las páginas de la campaña de informativas 2025 (normativa del 347, novedades, plazos, cuestiones relevantes, notas
  informativas y proyectos de orden).
- **Diseño de registro** (`actual/`): `347.pdf` (ejercicio 2025 y siguientes, modificado por la Orden HAC/1431/2025, de 3 de
  diciembre; publicado el 04/02/2026).
- **Otros documentos** (`actual/`): `NOVEDADES_2025.pdf` y `RECORDATORIOS_INF_2025.pdf` de la campaña de informativas.
- **Del portal de desarrolladores** (comunes a otros modelos, copiados de Modelo111 con el SHA-256 verificado contra el
  fichero publicado, `origen_descarga: manual`): `Especificaciones_TGVI_Online.pdf`,
  `EspecificacionesServiciosDeclaracionesAEAT_ES.pdf` y `consultas.xsd`.
- **Normativa del BOE** (`actual/normativa/`, 13 textos consolidados): Orden EHA/3012/2008 (modelo 347), las órdenes que la
  modifican o regulan su presentación (EHA/3062/2010, EHA/3378/2011, HAP/2725/2012, HAP/2194/2013, HAP/1732/2014,
  HFP/1106/2017, HAC/1148/2018, HFP/1395/2021 y HAC/1431/2025) y la base legal (Ley 58/2003 General Tributaria, RD
  1065/2007 Reglamento de gestión e inspección y Ley 37/1992 del IVA). Los identificadores del BOE se comprobaron uno a uno
  contra el título de cada texto.

### Versiones vigentes comprobadas
- Modelo 347: diseño de registro **ejercicio 2025 y siguientes** (Orden EHA/3012/2008 modificada por la **Orden
  HAC/1431/2025**, BOE del 12/12/2025). Ficha GI27 actualizada el 16/11/2026; lista de diseños de registro, el 26/10/2026;
  instrucciones, el 08/09/2026; ayuda técnica del 347, el 19/11/2026 (formulario y fichero) y el 09/01/2026.
- **Novedad 2025**: nuevo campo «Número de convocatoria BDNS» (posiciones 300-305 del registro tipo 2), con el código de la
  convocatoria en la Base de Datos Nacional de Subvenciones de las subvenciones y ayudas concedidas por las
  Administraciones Públicas. Solo se cumplimenta con la clave de operación `E` (subvenciones, auxilios y ayudas satisfechos
  por Administraciones públicas). **El plugin solo genera las claves `A` y `B`, así que no le afecta.**
- Plazo: anual, del 1 de febrero al 2 de marzo de 2026 (ejercicio 2025); si por razones técnicas no se puede presentar por
  Internet, se admiten los cuatro días naturales siguientes.
- Presentación: por formulario web (hasta 40.000 registros) o por fichero con TGVI online; ambas por Internet.
- Especificaciones del portal de desarrolladores: la vigente es `Espec_347_2025.pdf` (11/12/2025). El portal todavía no
  publica la de 2026 (sí el anexo de errores `PAIN-M347_2026.xml`).

### Descarga manual (el portal de desarrolladores no se pudo leer con curl) – HECHA el 2026-11-26
`www.agenciatributaria.es` devuelve «SSL certificate problem: self signed certificate in certificate chain» (la raíz
«AC RAIZ FNMT-RCM SERVIDORES SEGUROS» no está en la lista de confianza de este equipo) y no se desactiva la verificación.
Con el navegador integrado se comprobó que los tres ficheros propios del 347 existen (HTTP 200) y se calculó su SHA-256; el
navegador no los guarda en disco: el usuario los descargó a mano y se registraron con `registrar_descarga.py`
(`--origen manual`); los tres SHA-256 coinciden con los publicados y están en `actual/`:

| Fichero (enlace directo de descarga) | Tamaño | Last-Modified | SHA-256 publicado |
|---|---|---|---|
| [`Espec_347_2025.pdf`](https://www.agenciatributaria.es/static_files/AEAT_Desarrolladores/EEDD/Informativas/Especificaciones_Modelos/2025/Espec_347_2025.pdf) (347, ejercicio 2025 y siguientes) | 760.251 | 11/12/2025 | `1f1d5f42fe0f2065f69e85072f34e7c40abf0e7c02aa9ba820247b2a6123fce5` |
| [`PAIN-M347_2025.xml`](https://www.agenciatributaria.es/static_files/AEAT_Desarrolladores/EEDD/Informativas/Anexos_Errores/PAIN-M347_2025.xml) (anexo de errores 2025) | 51.108 | 23/10/2025 | `7863e95887be20d154cef92410ecbaa940688c3d1d6a5b7ce322ab2aa042f462` |
| [`PAIN-M347_2026.xml`](https://www.agenciatributaria.es/static_files/AEAT_Desarrolladores/EEDD/Informativas/Especificaciones_Modelos/2026/Anexo-errores-26/PAIN-M347_2026.xml) (anexo de errores 2026) | 50.965 | 16/10/2026 | `c34783589e3ed0d95e77a67f0904897b6f936979d5e03b8410c39c88585f9a08` |

Páginas del portal leídas con el navegador (su texto no se guarda en `paginas/`): `Documentacion/Declaraciones_Informativas/Especificaciones_Modelos`
(y sus páginas de ejercicio 2025 a 2027 y de anexos de errores), `Declaraciones_Informativas/General` y
`Declaraciones_Informativas/Resto_de_modelos` (no tiene documento de firma y envío del 347).

### Fuera de alcance (decisión de la primera ejecución)
No se descargan las especificaciones del 347 de ejercicios anteriores a 2025 (`Espec-347-2016` a `Espec-347-2024`, enlazadas
en `Especificaciones_Modelos/Ejercicio_AAAA`): el diseño vigente es el de 2025 y siguientes. Se añaden a `DIRECT` de
`sincronizar.py` si hacen falta.

### Resultado
- 22 ficheros en el manifest (16 por script, 3 comunes registrados desde Modelo111 y 3 manuales del portal de
  desarrolladores). Sin nombres largos. Nada pendiente de descarga manual.
