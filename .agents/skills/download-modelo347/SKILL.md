---
name: download-modelo347
description: Descarga toda la documentación oficial actualizada del modelo 347 de la AEAT (ayuda técnica, instrucciones, diseño de registro, especificaciones de desarrolladores, anexos de errores y normativa del BOE) en Doc/Context/Normativa
---

Eres un asistente que mantiene actualizada la documentación OFICIAL del modelo 347 (declaración anual de operaciones con terceras personas, con presentación anual en febrero) de la Agencia Tributaria, tanto la técnica (diseño de registro, especificaciones de presentación, anexos de errores de validación) como la de usuario (instrucciones, ayuda de presentación, plazos, novedades) y la normativa (BOE).

DOMINIOS PERMITIDOS: sede.agenciatributaria.gob.es, www2.agenciatributaria.gob.es, www.agenciatributaria.es (solo /static_files/AEAT_Desarrolladores/ y el portal de desarrolladores), www.boe.es. Nada de webs de terceros, foros, blogs ni GitHub, aunque aparezcan en buscadores. Los formularios y servicios con identificación (www1.agenciatributaria.gob.es/wlpl/..., PAIN-M347, TGVI online, Cl@ve, certificados) NO se visitan ni se usan.

DESCARGA: usa SIEMPRE /usr/bin/curl (SecureTransport de macOS) con -L y un User-Agent de navegador. NUNCA uses -k/--insecure. Existe un script ya probado que implementa esta tarea; úsalo y revisa su salida:
  python3 -I /Users/daniel89fg/Trabajos/FacturaScripts/facturascripts/Plugins/Modelo347/.agents/skills/download-modelo347/scripts/sincronizar.py /Users/daniel89fg/Trabajos/FacturaScripts/facturascripts/Plugins/Modelo347/Doc/Context/Normativa
Su salida y Doc/Context/Normativa/.log_run.json incluyen: nuevos, modificados, iguales, errores, páginas nuevas/cambiadas/desaparecidas y los enlaces descubiertos.

PÁGINAS (guardar texto en paginas/, con la URL de origen en la primera línea). El script RASTREA RECURSIVAMENTE las páginas .html de estas secciones a partir de las semillas, así que las páginas nuevas se detectan solas:
- Ficha del procedimiento GI27 (solo se guarda; de ella salen la normativa, las instrucciones y los enlaces): /Sede/procedimientoini/GI27.shtml
- Ayuda técnica: /Sede/ayuda/consultas-informaticas/declaraciones-informativas-ayuda-tecnica/modelos-291-347/modelo-347* (presentación por formulario, hasta 40.000 registros, y por fichero con TGVI online), consulta y baja de declaraciones, número de justificante y presentación de documentación complementaria.
- Gestión del modelo en la Sede: /Sede/todas-gestiones/impuestos-tasas/declaraciones-informativas/modelo-347-*/ (instrucciones con todos sus capítulos —normativa aplicable, novedad 2025, plazo, obligados, excluidos, operaciones excluidas, desglose, importe, imputación temporal, forma de presentación y cumplimentación—, plazos de presentación, preguntas frecuentes y ejercicios anteriores) y /Sede/impuestos-tasas/declaraciones-informativas/modelo-347-*/ (por si la Sede publica ahí notas informativas).
- Campaña de informativas: /Sede/declaraciones-informativas-otros-impuestos-tasas/campana-declaraciones-informativas-2025 (normativa del 347 en `normativa/modelo-347.html`, novedades, plazos, cuestiones relevantes, notas informativas, proyectos de orden). Se descartan las páginas de otros modelos y las charlas informativas.
- Diseños de registro: /Sede/ayuda/disenos-registro/modelos-300-399.html (solo se descarga el fichero del 347).
- Si se amplía el rastreo, cambia CRAWL_PREFIXES / PAGES / EXCLUIR_PAGINAS en sincronizar.py.
- Las páginas guardadas antes que ya no aparecen se listan en "paginas_desaparecidas" (anótalas en el CHANGELOG; no se borran).
- Cuando cambie el ejercicio (campaña 2026 de informativas), cambia INF en sincronizar.py (`campana-declaraciones-informativas-2025` → `...-2026`) y revisa si la Sede ha movido las carpetas del modelo.

FICHEROS DIRECTOS (a actual/):
- Diseño de registro del 347 (Orden EHA/3012/2008 y sus modificaciones, ejercicio 2025 y siguientes, con la Orden HAC/1431/2025): https://sede.agenciatributaria.gob.es/static_files/Sede/Disenyo_registro/DR_300_399/archivos/347.pdf
- Portal de desarrolladores (base https://www.agenciatributaria.es/static_files/AEAT_Desarrolladores/EEDD/):
  Informativas/Especificaciones_Modelos/2025/Espec_347_2025.pdf (especificaciones del 347, ejercicio 2025 y siguientes; es la vigente, el portal aún no publica la de 2026)
  Informativas/Anexos_Errores/PAIN-M347_2025.xml (anexo de errores de validación, 2025)
  Informativas/Especificaciones_Modelos/2026/Anexo-errores-26/PAIN-M347_2026.xml (anexo de errores de validación, 2026)
  Informativas/General/Especificaciones_TGVI_Online.pdf (validación y presentación por Internet de las informativas con TGVI Online)
  General/EspecificacionesServiciosDeclaracionesAEAT_ES.pdf (servicios comunes de declaraciones: presentación directa, validación e impresión, consulta)
  General/consultas.xsd (esquema del servicio de consulta de declaraciones presentadas)
- El 347 no tiene documento propio de «firma y envío» en el portal (solo lo tienen el 349 y otros modelos de «Resto de modelos»): no lo busques.
- No se descargan (decisión de alcance): las especificaciones de ejercicios anteriores a 2025 (`Espec-347-2016` a `Espec-347-2024`, enlazadas en `Especificaciones_Modelos/Ejercicio_AAAA`), porque el diseño vigente es el de 2025 y siguientes. Si hacen falta, añádelas a DIRECT en sincronizar.py. Cuando el portal publique las de 2026 o 2027 (carpeta `Especificaciones_Modelos/2026/` o `2027/`; ojo, la del 349 de 2026 se publicó en la carpeta 2025) o sus anexos de errores (`Anexo-errores-27/PAIN-M347_2027.xml`), añádelas a DIRECT.
- Cualquier otro fichero (.pdf .zip .xls .xlsx .xsd .xml .doc .docx .txt) enlazado desde las páginas anteriores, salvo los de otros modelos (EXCLUIR_ENLACES): novedades y recordatorios de la campaña de informativas, etc.

NORMATIVA (guardar el texto consolidado en actual/normativa/, desde https://www.boe.es/buscar/act.php?id=BOE-A-...): las normas que la propia Sede enlaza como normativa del modelo en la ficha GI27 y en las instrucciones, más la base legal:
- Orden EHA/3012/2008 (BOE-A-2008-16973), que aprueba el modelo 347, sus diseños físicos y lógicos y el lugar, forma y plazo de presentación.
- Sus modificaciones y las órdenes que afectan a su presentación: EHA/3062/2010 (BOE-A-2010-18368, formas de presentación de las informativas), EHA/3378/2011 (BOE-A-2011-19397), HAP/2725/2012 (BOE-A-2012-15380), HAP/2194/2013 (BOE-A-2013-12385, procedimientos y condiciones generales de presentación), HAP/1732/2014 (BOE-A-2014-9740), HFP/1106/2017 (BOE-A-2017-13279), HAC/1148/2018 (BOE-A-2018-14901), HFP/1395/2021 (BOE-A-2021-20577) y HAC/1431/2025 (BOE-A-2025-25390, nuevo campo del número de convocatoria BDNS desde el ejercicio 2025).
- Base legal: Ley 58/2003 General Tributaria (BOE-A-2003-23186, art. 93: obligación de facilitar información), Reglamento General de las actuaciones y los procedimientos de gestión e inspección tributaria, RD 1065/2007 (BOE-A-2007-15984, art. 31 a 37: obligados, excluidos y operaciones de la declaración anual de operaciones con terceras personas) y Ley 37/1992 del IVA (BOE-A-1992-28740, régimen del IVA que condiciona las operaciones a declarar).
- En cada ejecución, mira en la ficha GI27, en las instrucciones y en la normativa de la campaña si aparece una orden nueva que no esté en la lista (p. ej. la del ejercicio siguiente o un proyecto de orden que se publique en el BOE) y añádela a DIRECT en sincronizar.py. Anota en el CHANGELOG si el texto consolidado de la Orden EHA/3012/2008 muestra nuevas modificaciones.

REGLAS COMUNES
1. Fuentes: SOLO los dominios oficiales listados en esta tarea.
2. Estructura dentro de la carpeta Doc/Context/Normativa del plugin:
   - actual/            → última versión de cada fichero (nombre original conservado)
   - actual/normativa/  → textos consolidados del BOE
   - Nombres: ningún nombre de archivo pasa de 60 caracteres (el script los acorta con `nombre_corto()` de `../FiscalES/.agents/skills/download-fiscales/scripts/nombres.py`, que añade una huella del nombre completo; el oficial queda en la URL del manifest), para que el plugin se pueda descargar en Windows. Si el script avisa de un `NOMBRE LARGO`, corrígelo antes de terminar.
   - historico/AAAA-MM-DD/ → versiones anteriores sustituidas (mover aquí, nunca borrar)
   - paginas/           → copia en texto/Markdown de cada página (primera línea: URL de origen) para detectar cambios
   - manifest.json      → por fichero: url, ruta, sha256, tamaño, fechas de descarga y de comprobación, cabeceras ETag/Last-Modified
   - CHANGELOG.md       → añade una entrada con fecha por cada ejecución
3. Procedimiento en cada ejecución:
   a) Descarga cada URL de la lista. Calcula SHA-256 y compáralo con manifest.json.
   b) Si el hash cambió: mueve la versión vieja a historico/AAAA-MM-DD/ y guarda la nueva en actual/.
   c) En cada página, extrae TODOS los enlaces a documentos y descarga los que no estén en el manifest (así detectas documentos nuevos con nombre distinto).
   d) Anota en el CHANGELOG: ficheros nuevos, modificados (con versión/fecha que figure dentro del documento), desaparecidos (404) y páginas cuyo texto cambió. Si una página muestra "Página actualizada: fecha", guárdala.
4. Seguridad: no ejecutes ni abras macros de ningún fichero descargado; no instales nada; no rellenes formularios ni inicies sesión; no accedas a nada que requiera certificado o contraseña. No desactives nunca la verificación SSL.
5. Hay que intentar descargar siempre todo (decisión del usuario del 27/10/2026). El portal de desarrolladores (www.agenciatributaria.es) usa la cadena de certificados de la FNMT (raíz «AC RAIZ FNMT-RCM SERVIDORES SEGUROS»), que `curl` puede no verificar en algunos equipos («SSL certificate problem: self signed certificate in certificate chain»): en ese caso no se usa -k. Sigue con el navegador integrado y, si tampoco, con la lista de descarga manual del usuario, que se registra con `registrar_descarga.py` del núcleo (`<base>` = `Doc/Context/Normativa`). Procedimiento común a las skills de descarga: `../FiscalES/.agents/skills/download-fiscales/NAVEGADOR.md`. El navegador entra como un visitante normal: no se resuelven ni se saltan CAPTCHAs ni comprobaciones anti-bots, no se falsean cabeceras ni el agente de usuario y no se inicia sesión. Apunta en el CHANGELOG qué se comprobó con el navegador y qué queda en "Descarga manual".
   Los tres ficheros comunes (TGVI Online, servicios de declaraciones y `consultas.xsd`) son los mismos que descargan Modelo111 y Modelo349: si el SHA-256 publicado coincide con el de uno de esos plugins, se pueden registrar copiándolos de allí (`--origen manual`).
   Páginas del portal de desarrolladores a revisar con el navegador (su texto no se guarda en paginas/): `AEAT.desarrolladores/Desarrolladores/_menu_/Documentacion/Declaraciones_Informativas/Especificaciones_Modelos/Especificaciones_Modelos.html` (y sus páginas `Ejercicio_AAAA` y `Documentacion_anexos_de_errores`), `.../Declaraciones_Informativas/General/General.html` y `.../Declaraciones_Informativas/Resto_de_modelos/Resto_de_modelos.html`; el tablón de anuncios (`Desarrolladores.html`) avisa cuando un modelo de informativas (347) estrena diseño o pasa al entorno de pruebas.
6. Resumen final (máx. 20 líneas): nº de ficheros comprobados, nuevos, modificados, errores y una lista destacada de los cambios relevantes para un desarrollador (nuevo diseño de registro del 347, versión del diseño, campos nuevos, órdenes nuevas, plazos, formas de presentación). Sección "DESCARGA MANUAL" con lo que el usuario tiene que bajar (URL, motivo y dónde guardarlo), o "Nada que descargar a mano". Si no hay cambios, dilo en una línea.
7. Si algún documento nuevo o modificado menciona un nuevo diseño de registro, nuevos campos, cambios en la forma de presentación o fechas de entrada en vigor, ponlo en una sección "ALERTA" al inicio del resumen, y avisa de que el código del plugin (Doc/Context/03-fichero-aeat.md) puede necesitar ajustes.
