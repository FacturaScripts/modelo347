#!/usr/bin/env python3
"""Sincroniza la documentación oficial del modelo 347 de la AEAT en la carpeta Normativa (primer argumento).

Uso:
  python3 -I sincronizar.py <carpeta Normativa>

Descarga, solo de dominios oficiales (Sede de la AEAT, portal de desarrolladores de la AEAT y BOE):
- las páginas de ayuda técnica, instrucciones, plazos, preguntas frecuentes, novedades de la campaña de informativas y
  ejercicios anteriores del modelo 347 (se guarda su texto en paginas/),
- el diseño de registro (.pdf), las especificaciones técnicas y los documentos enlazados,
- la normativa del BOE (órdenes que aprueban y modifican el modelo, Ley General Tributaria, su Reglamento de gestión e
  inspección y la Ley del IVA).

Estructura de salida: actual/ (última versión), historico/AAAA-MM-DD/ (versiones sustituidas), paginas/ (texto de las
páginas), manifest.json (url, sha256, tamaño, fechas y cabeceras) y .log_run.json (resultado de la última ejecución).
La TSL y la LOTL no se descargan aquí: son comunes a los plugins fiscales y las mantiene la skill download-fiscales
del núcleo FiscalES.

@author Daniel Fernández Giménez <contacto@danielfg.es>
"""
import hashlib, json, os, re, shutil, sys, subprocess, html
from datetime import date
from urllib.parse import urljoin, urlparse, unquote

# Nombres cortos, comunes a los plugins fiscales (../FiscalES/.agents/skills/download-fiscales/scripts/nombres.py):
# Windows no admite rutas largas, así que ningún archivo guardado pasa de 60 caracteres.
sys.path.insert(0, os.path.join(os.path.dirname(os.path.realpath(__file__)), *[".."] * 5, "FiscalES", ".agents", "skills",
                                "download-fiscales", "scripts"))
from nombres import avisar, nombre_corto  # noqa: E402

ROOT = os.path.abspath(sys.argv[1])
TODAY = date.today().isoformat()
ALLOWED = {"sede.agenciatributaria.gob.es", "www2.agenciatributaria.gob.es", "www.agenciatributaria.es", "www.boe.es"}
UA = "Mozilla/5.0 (compatible; doc-sync)"
EXT = re.compile(r"\.(pdf|zip|7z|xsd|wsdl|xls|xlsx|xml|json|doc|docx|txt)(\?|$)", re.I)

SEDE = "https://sede.agenciatributaria.gob.es"
SEDE_HOST = urlparse(SEDE).netloc
AYUDA = SEDE + "/Sede/ayuda/consultas-informaticas"
AYUDA_INF = AYUDA + "/declaraciones-informativas-ayuda-tecnica"
INF = SEDE + "/Sede/declaraciones-informativas-otros-impuestos-tasas/campana-declaraciones-informativas-2025"
# Carpeta del modelo 347 en la Sede (el nombre lleva caracteres sustituidos por guiones bajos, tal como lo publica la AEAT)
M347 = "modelo-347-decla_____racion-anual-operaciones-personas_"
GESTIONES = SEDE + "/Sede/todas-gestiones/impuestos-tasas/declaraciones-informativas/" + M347

# Páginas semilla: desde ellas se rastrean recursivamente las de CRAWL_PREFIXES.
PAGES = [
    # ficha del procedimiento GI27 (solo se guarda; sus enlaces a ficheros sí se descargan)
    SEDE + "/Sede/procedimientoini/GI27.shtml",
    # ayuda técnica del 347: formulario (hasta 40.000 registros) y fichero (TGVI online)
    AYUDA_INF + "/modelos-291-347/modelo-347.html",
    AYUDA_INF + "/modelos-291-347/modelo-347-presentacion-mediante-formulario.html",
    AYUDA_INF + "/modelos-291-347/modelo-347-fichero.html",
    # ayuda técnica común a las declaraciones informativas y a la presentación
    AYUDA_INF + "/consulta-baja-declaraciones-informativas.html",
    AYUDA_INF + "/numero-justificante-debe-ser-cumplimentado-correcto.html",
    AYUDA + "/presentacion-declaraciones-ayuda-tecnica/presentacion-documentacion-complementaria.html",
    # gestión del modelo en la Sede: instrucciones (con sus capítulos y la novedad de 2025), plazos, preguntas frecuentes
    # y ejercicios anteriores
    GESTIONES + "/instrucciones.html",
    GESTIONES + "/plazos-presentacion.html",
    GESTIONES + "/preguntas-frecuentes.html",
    GESTIONES + "/ejercicios-anteriores.html",
    # normativa y novedades del 347 en la campaña de informativas
    INF + "/normativa/modelo-347.html",
    SEDE + "/Sede/declaraciones-informativas/campana-declaraciones-informativas.html",
    # diseños de registro: solo se descarga el del modelo 347 (ver FILTROS_ENLACES)
    SEDE + "/Sede/ayuda/disenos-registro/modelos-300-399.html",
]
# Secciones oficiales que se rastrean completas: se siguen los enlaces .html cuya ruta empieza por uno de estos prefijos.
CRAWL_PREFIXES = (
    "/Sede/ayuda/consultas-informaticas/declaraciones-informativas-ayuda-tecnica/modelos-291-347/modelo-347",
    "/Sede/todas-gestiones/impuestos-tasas/declaraciones-informativas/modelo-347-",
    "/Sede/impuestos-tasas/declaraciones-informativas/modelo-347-",
    "/Sede/declaraciones-informativas-otros-impuestos-tasas/campana-declaraciones-informativas-2025",
)
# Solo interesa el modelo 347 y las páginas generales: se descartan las de otros modelos (/modelo-190.html,
# /modelos-159-238-..., etc.) y las charlas informativas por comunidad.
EXCLUIR_PAGINAS = re.compile(r"charlas-informativas|/modelos?-(?!347)\d{3}")
# Ficheros enlazados que no son del 347 (notas de otros modelos que enlazan las páginas generales de la campaña).
EXCLUIR_ENLACES = re.compile(r"(?i)198|289|CRS")
# Fichas .shtml (procedimientos): se guardan y se descargan sus ficheros, pero no se rastrean sus páginas.
SOLO_GUARDAR = re.compile(r"\.shtml$")
# De algunas páginas (listas de diseños de registro de todos los modelos) solo se descargan los enlaces que cumplan el filtro.
FILTROS_ENLACES = {
    "/Sede/ayuda/disenos-registro/modelos-300-399.html": re.compile(r"(?i)(?<!\d)347(?!\d)"),
}
MAX_PAGES = 120
DEV = "https://www.agenciatributaria.es/static_files/AEAT_Desarrolladores/EEDD/"
ESPEC = DEV + "Informativas/Especificaciones_Modelos/"

DIRECT = [
    # diseño de registro del 347 (Orden EHA/3012/2008 y sus modificaciones; se descubre también desde la página de
    # diseños, pero se fija aquí por si cambia el enlace)
    (SEDE + "/static_files/Sede/Disenyo_registro/DR_300_399/archivos/347.pdf", "actual", None),
    # portal de desarrolladores (www.agenciatributaria.es): especificaciones del 347 (ejercicio 2025, la vigente), anexos
    # de errores de validación (XML) de 2025 y 2026 y especificaciones comunes de las informativas.
    # Su certificado (cadena FNMT) no lo verifica siempre curl: lo que falle va a la lista de descarga manual de SKILL.md.
    (ESPEC + "2025/Espec_347_2025.pdf", "actual", None),
    (DEV + "Informativas/Anexos_Errores/PAIN-M347_2025.xml", "actual", None),
    (ESPEC + "2026/Anexo-errores-26/PAIN-M347_2026.xml", "actual", None),
    (DEV + "Informativas/General/Especificaciones_TGVI_Online.pdf", "actual", None),
    (DEV + "General/EspecificacionesServiciosDeclaracionesAEAT_ES.pdf", "actual", None),
    (DEV + "General/consultas.xsd", "actual", None),
    # normativa: órdenes que aprueban y modifican el modelo 347 (los enlaces que da la Sede en la ficha GI27)
    ("https://www.boe.es/buscar/act.php?id=BOE-A-2008-16973", "actual/normativa", "BOE-A-2008-16973_Orden-EHA-3012-2008_mod347.html"),
    ("https://www.boe.es/buscar/act.php?id=BOE-A-2010-18368", "actual/normativa", "BOE-A-2010-18368_Orden-EHA-3062-2010.html"),
    ("https://www.boe.es/buscar/act.php?id=BOE-A-2011-19397", "actual/normativa", "BOE-A-2011-19397_Orden-EHA-3378-2011.html"),
    ("https://www.boe.es/buscar/act.php?id=BOE-A-2012-15380", "actual/normativa", "BOE-A-2012-15380_Orden-HAP-2725-2012.html"),
    ("https://www.boe.es/buscar/act.php?id=BOE-A-2013-12385", "actual/normativa", "BOE-A-2013-12385_Orden-HAP-2194-2013.html"),
    ("https://www.boe.es/buscar/act.php?id=BOE-A-2014-9740", "actual/normativa", "BOE-A-2014-9740_Orden-HAP-1732-2014.html"),
    ("https://www.boe.es/buscar/act.php?id=BOE-A-2017-13279", "actual/normativa", "BOE-A-2017-13279_Orden-HFP-1106-2017.html"),
    ("https://www.boe.es/buscar/act.php?id=BOE-A-2018-14901", "actual/normativa", "BOE-A-2018-14901_Orden-HAC-1148-2018.html"),
    ("https://www.boe.es/buscar/act.php?id=BOE-A-2021-20577", "actual/normativa", "BOE-A-2021-20577_Orden-HFP-1395-2021.html"),
    ("https://www.boe.es/buscar/act.php?id=BOE-A-2025-25390", "actual/normativa", "BOE-A-2025-25390_Orden-HAC-1431-2025.html"),
    # base legal: Ley General Tributaria (art. 93, obligación de informar), Reglamento de gestión e inspección (art. 31 a
    # 37, declaración anual de operaciones con terceras personas) y Ley del IVA
    ("https://www.boe.es/buscar/act.php?id=BOE-A-2003-23186", "actual/normativa", "BOE-A-2003-23186_Ley-58-2003-LGT.html"),
    ("https://www.boe.es/buscar/act.php?id=BOE-A-2007-15984", "actual/normativa", "BOE-A-2007-15984_RD-1065-2007-Reglamento-gestion.html"),
    ("https://www.boe.es/buscar/act.php?id=BOE-A-1992-28740", "actual/normativa", "BOE-A-1992-28740_Ley-37-1992-IVA.html"),
]

mpath = os.path.join(ROOT, "manifest.json")
manifest = json.load(open(mpath)) if os.path.exists(mpath) else {}
log = {"nuevos": [], "modificados": [], "iguales": 0, "errores": [], "paginas_cambiadas": [], "paginas_nuevas": [],
       "paginas_desaparecidas": [], "enlaces": {}}


def fetch(url):
    """Descarga con /usr/bin/curl (SecureTransport de macOS). Devuelve código HTTP, cuerpo, cabeceras y error."""
    url = url.replace(" ", "%20")  # algunos enlaces de la Sede llevan espacios en el nombre del fichero
    hdr = os.path.join(ROOT, ".hdr.tmp"); body = os.path.join(ROOT, ".body.tmp")
    r = subprocess.run(["/usr/bin/curl", "-sS", "-L", "-m", "120", "-A", UA, "-D", hdr, "-o", body, "-w", "%{http_code}", url],
                       capture_output=True, text=True)
    code = r.stdout.strip()
    heads = {}
    if os.path.exists(hdr):
        blocks = [b for b in open(hdr, errors="ignore").read().split("\r\n\r\n") if b.strip()]
        for ln in (blocks[-1] if blocks else "").splitlines():
            if ":" in ln:
                k, v = ln.split(":", 1); heads[k.strip().lower()] = v.strip()
    data = open(body, "rb").read() if os.path.exists(body) else b""
    for f in (hdr, body):
        if os.path.exists(f): os.remove(f)
    return code, data, heads, r.stderr.strip()


def store(url, data, heads, dest_dir, fname):
    """Guarda el fichero en dest_dir; si ya existía y cambió, mueve el anterior a historico/AAAA-MM-DD/."""
    if fname.lower().endswith(".html"):
        # el BOE añade parámetros aleatorios (?rnd=NNN) a sus scripts: se eliminan para no detectar cambios falsos
        data = re.sub(rb"\?rnd=\d+", b"", data)
    sha = hashlib.sha256(data).hexdigest()
    os.makedirs(os.path.join(ROOT, dest_dir), exist_ok=True)
    rel = os.path.join(dest_dir, fname)
    old = manifest.get(url)
    if old and old["sha256"] == sha:
        old["comprobado"] = TODAY
        log["iguales"] += 1
        return "igual"
    status = "nuevo"
    if old and os.path.exists(os.path.join(ROOT, old["ruta"])):
        hd = os.path.join(ROOT, "historico", TODAY, os.path.dirname(old["ruta"]))
        os.makedirs(hd, exist_ok=True)
        shutil.move(os.path.join(ROOT, old["ruta"]), os.path.join(hd, os.path.basename(old["ruta"])))
        status = "modificado"
    open(os.path.join(ROOT, rel), "wb").write(data)
    manifest[url] = {"url": url, "ruta": rel, "sha256": sha, "tamano": len(data), "descargado": TODAY, "comprobado": TODAY,
                     "etag": heads.get("etag"), "last_modified": heads.get("last-modified")}
    log["nuevos" if status == "nuevo" else "modificados"].append(rel)
    return status


def to_text(h):
    """Convierte el HTML de una página en Markdown sencillo conservando los enlaces."""
    h = re.sub(r"(?is)<(script|style|noscript)[^>]*>.*?</\1>", "", h)
    h = re.sub(r"(?i)<br\s*/?>|</(p|div|li|tr|h\d|ul|ol|table)>", "\n", h)
    h = re.sub(r"(?is)<a\s[^>]*href=[\"']([^\"']+)[\"'][^>]*>(.*?)</a>",
               lambda m: "[%s](%s)" % (re.sub(r"<[^>]+>", "", m.group(2)).strip(), m.group(1)), h)
    h = re.sub(r"<[^>]+>", "", h)
    h = html.unescape(h)
    h = re.sub(r"[ \t\r\f\v]+", " ", h)
    h = re.sub(r"\n\s*\n+", "\n\n", h)
    return h.strip() + "\n"


def slug(url):
    """Nombre del Markdown de una página: su ruta en la sede, acortada con nombre_corto() si pasa de 60 caracteres."""
    ruta = urlparse(url).path.split("/Sede/")[-1]
    ruta = re.sub(r"\.s?html$", "", ruta)
    return nombre_corto(re.sub(r"[^A-Za-z0-9._-]+", "_", ruta) + ".md")


links = {}
# 1) páginas: rastreo recursivo dentro de CRAWL_PREFIXES a partir de las semillas
queue, visited, saved = list(PAGES), set(), set()
while queue and len(visited) < MAX_PAGES:
    url = queue.pop(0)
    if url in visited: continue
    visited.add(url)
    code, data, heads, err = fetch(url)
    if code != "200" or not data:
        log["errores"].append((url, code, err)); continue
    h = data.decode("utf-8", errors="ignore")
    txt = "<!-- Fuente: %s -->\n\n" % url + to_text(h)
    fname = slug(url)
    saved.add(fname)
    pth = os.path.join(ROOT, "paginas", fname)
    os.makedirs(os.path.dirname(pth), exist_ok=True)
    prev = open(pth).read() if os.path.exists(pth) else None
    if prev is None: log["paginas_nuevas"].append(fname)
    elif prev != txt: log["paginas_cambiadas"].append(fname)
    open(pth, "w").write(txt)
    m = re.search(r"(?i)p[aá]gina actualizada[^\n]{0,80}", txt)
    if m: log.setdefault("actualizada", {})[fname] = re.sub(r"\s+", " ", m.group(0)).strip()
    seguir = not SOLO_GUARDAR.search(urlparse(url).path)
    filtro = FILTROS_ENLACES.get(urlparse(url).path)
    for href in re.findall(r"href=[\"']([^\"'#]+)[\"']", h):
        full = urljoin(url, html.unescape(href))
        pu = urlparse(full)
        if seguir and pu.netloc == SEDE_HOST and pu.path.endswith(".html") and pu.path.startswith(CRAWL_PREFIXES) \
                and not EXCLUIR_PAGINAS.search(pu.path):
            clean = SEDE + pu.path
            if clean not in visited and clean not in queue: queue.append(clean)
        elif pu.netloc in ALLOWED and EXT.search(pu.path):
            if (filtro is None or filtro.search(os.path.basename(pu.path))) and not EXCLUIR_ENLACES.search(os.path.basename(pu.path)):
                links.setdefault(full, url)
if queue:
    log["errores"].append(("rastreo", "", "se alcanzó MAX_PAGES=%d; quedan %d páginas sin revisar" % (MAX_PAGES, len(queue))))
# páginas guardadas en ejecuciones anteriores que ya no se encuentran (borradas o sin enlace)
if os.path.isdir(os.path.join(ROOT, "paginas")):
    for f in sorted(os.listdir(os.path.join(ROOT, "paginas"))):
        if f.endswith(".md") and f not in saved:
            log["paginas_desaparecidas"].append(f)

# 2) ficheros directos
direct_urls = set()
for item in DIRECT:
    url, d = item[0], item[1]
    fname = nombre_corto(item[2] if len(item) > 2 and item[2] else unquote(os.path.basename(urlparse(url).path)))
    direct_urls.add(url)
    code, data, heads, err = fetch(url)
    if code != "200" or not data:
        log["errores"].append((url, code, err)); continue
    if fname.lower().endswith((".xsd", ".wsdl", ".xml")) and not data.lstrip(b"\xef\xbb\xbf \r\n\t").startswith(b"<?xml"):
        log["errores"].append((url, code, "no empieza por <?xml (posible HTML de mantenimiento); no guardado")); continue
    store(url, data, heads, d, fname)


# 3) enlaces extraídos de las páginas que no estén ya en la lista de ficheros directos
def norm(u): return u.split("?")[0].lower()


known = {norm(u) for u in direct_urls}
for full, origen in sorted(links.items()):
    if norm(full) in known: continue
    fname = nombre_corto(unquote(os.path.basename(urlparse(full).path)).replace(" ", "_"))
    code, data, heads, err = fetch(full)
    if code != "200" or not data:
        log["errores"].append((full, code, err)); continue
    store(full, data, heads, "actual", fname)
    log["enlaces"][full] = origen

json.dump(manifest, open(mpath, "w"), indent=1, ensure_ascii=False)
log["nombres_largos"] = [rel for rel, _ in avisar(ROOT)]
json.dump(log, open(os.path.join(ROOT, ".log_run.json"), "w"), indent=1, ensure_ascii=False)
print(json.dumps({k: (v if k not in ("enlaces", "actualizada") else len(v)) for k, v in log.items()}, indent=1, ensure_ascii=False))
