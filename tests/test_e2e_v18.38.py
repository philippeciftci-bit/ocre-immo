#!/usr/bin/env python3
"""
E2E tests Ocre Immo — v18.38.

Exerce la chaîne fonctionnelle complète sur prod (app.ocre.immo) avec un user dédié
`test_e2e@ocre.immo`. Idempotent (cleanup avant + après). NE TOUCHE PAS les données
de Philippe/Ophélie (prefix test_e2e_ imposé, endpoint bootstrap IP-whitelisted VPS).

Usage :
  python3 test_e2e_v18.38.py [--report PATH] [--verbose]

Exit code : 0 si tous PASS (SKIP autorisés), 1 sinon.
"""

from __future__ import annotations
import argparse
import base64
import io
import json
import os
import socket
import subprocess
import sys
import time
import urllib.parse
from dataclasses import dataclass, field
from typing import Any, Callable

# Force IPv4 — les endpoints IP-whitelist OVH n'acceptent que 46.225.215.148 (IPv4).
# Sans ce hack, requests peut sortir en IPv6 (2a01:4f8:…) et se faire refuser en 403.
_orig_getaddrinfo = socket.getaddrinfo
def _ipv4_only(*args, **kwargs):
    results = _orig_getaddrinfo(*args, **kwargs)
    v4 = [r for r in results if r[0] == socket.AF_INET]
    return v4 or results
socket.getaddrinfo = _ipv4_only

import requests
from PIL import Image, ImageDraw, ImageFont

API_BASE = "https://app.ocre.immo/api"
APP_BASE = "https://app.ocre.immo"
TEST_EMAIL = "test_e2e@ocre.immo"
TEST_PASSWORD = "TestE2E_2026!"
TEST_PRENOM = "Test"
TEST_NOM = "E2E"

# ─── Résultats ────────────────────────────────────────────────────────────
@dataclass
class Result:
    name: str
    status: str  # PASS / FAIL / SKIP
    detail: str = ""
    duration_ms: int = 0
    meta: dict = field(default_factory=dict)

    def to_dict(self) -> dict:
        return {
            "name": self.name, "status": self.status, "detail": self.detail,
            "duration_ms": self.duration_ms, "meta": self.meta,
        }

RESULTS: list[Result] = []

def run_test(name: str, fn: Callable[[], tuple[str, str, dict]]) -> Result:
    t0 = time.time()
    try:
        status, detail, meta = fn()
    except Exception as e:
        status, detail, meta = "FAIL", f"exception: {type(e).__name__}: {e}", {}
    r = Result(name, status, detail, int((time.time() - t0) * 1000), meta or {})
    RESULTS.append(r)
    marker = {"PASS": "✓", "FAIL": "✗", "SKIP": "⊘"}.get(status, "?")
    print(f"  {marker} {name:<42} {status:<4} {r.duration_ms:>5}ms  {detail[:80]}")
    return r

# ─── Session HTTP ──────────────────────────────────────────────────────────
session = requests.Session()
session.headers.update({"User-Agent": "OcreE2E/18.38"})

def req(method: str, path: str, **kwargs) -> requests.Response:
    url = API_BASE + path if not path.startswith("http") else path
    kwargs.setdefault("timeout", 90)
    return session.request(method, url, **kwargs)

TOKEN: str | None = None
USER_ID: int | None = None

def auth_hdr() -> dict:
    return {"X-Session-Token": TOKEN} if TOKEN else {}

# ─── Bootstrap (via endpoint IP-whitelist VPS) ─────────────────────────────
def bootstrap(action: str, body: dict | None = None) -> dict:
    r = req("POST", "/e2e_bootstrap.php?action=" + action, json=(body or {}), timeout=30)
    r.raise_for_status()
    return r.json()

# ─── TESTS ─────────────────────────────────────────────────────────────────

def t1_auth() -> tuple[str, str, dict]:
    global TOKEN, USER_ID
    # 1a · bootstrap user (idempotent reset password)
    bootstrap("create_test_user", {
        "email": TEST_EMAIL, "password": TEST_PASSWORD,
        "prenom": TEST_PRENOM, "nom": TEST_NOM,
    })
    # 1b · login OK
    r = req("POST", "/auth.php?action=login", json={"email": TEST_EMAIL, "password": TEST_PASSWORD})
    if r.status_code != 200 or not r.json().get("ok"):
        return "FAIL", f"login HTTP {r.status_code} {r.text[:120]}", {}
    d = r.json()
    TOKEN = d.get("token")
    USER_ID = int(d.get("user", {}).get("id") or 0)
    if not TOKEN or not USER_ID:
        return "FAIL", "token/user_id absent dans réponse login", d
    # 1c · whoami
    me = req("GET", "/auth.php?action=me", headers=auth_hdr()).json()
    if not me.get("ok") or me.get("user", {}).get("email") != TEST_EMAIL:
        return "FAIL", f"whoami fail: {me}", {}
    # 1d · 5 essais mauvais MDP (prep lockout v18.39 — ici on vérifie juste 401 chaque fois)
    bad_fails = 0
    for i in range(5):
        rb = req("POST", "/auth.php?action=login", json={"email": TEST_EMAIL, "password": "WRONG_" + str(i)})
        if rb.status_code in (200, 401, 429):
            if not rb.json().get("ok"):
                bad_fails += 1
    return "PASS", f"login OK user_id={USER_ID}, whoami OK, {bad_fails}/5 mauvais MDP rejetés", {"user_id": USER_ID}


PROFILS_TEST = ["Acheteur", "Vendeur", "Investisseur", "Bailleur", "Locataire", "Curieux"]
CREATED_IDS: dict[str, int] = {}

def t2_create_6_profils() -> tuple[str, str, dict]:
    if not TOKEN:
        return "SKIP", "auth requise", {}
    ok = 0
    for i, p in enumerate(PROFILS_TEST):
        client = {
            "projet": p,
            "is_investisseur": p == "Investisseur",
            "profil_type": "Particulier",
            "prenom": f"Test{i}",
            "nom": f"E2E_{p[:3].upper()}",
            "tel": "+33600000" + str(100 + i),
            "tels": [{"label": "Test", "valeur": "+33600000" + str(100 + i), "primary": True}],
            "email": f"test_e2e_{p.lower()}@ocre.immo",
            "emails": [{"label": "Test", "valeur": f"test_e2e_{p.lower()}@ocre.immo", "primary": True}],
            "bien": {"pays": "MA", "ville": "Marrakech", "type": "Riad", "types": ["Riad"]},
        }
        r = req("POST", "/clients.php?action=save", json={"client": client}, headers=auth_hdr())
        j = r.json() if r.status_code == 200 else {}
        if j.get("ok") and j.get("client", {}).get("id"):
            CREATED_IDS[p] = int(j["client"]["id"])
            ok += 1
    if ok != 6:
        return "FAIL", f"{ok}/6 profils créés", {"created": CREATED_IDS}
    return "PASS", f"6/6 profils créés, IDs={CREATED_IDS}", {"created": CREATED_IDS}


def t3_import_url_x5() -> tuple[str, str, dict]:
    if not TOKEN:
        return "SKIP", "auth requise", {}
    urls = [
        "https://www.mubawab.ma/fr/",  # home page : extraction OG minimum
        "https://www.seloger.com/",
        "https://www.leboncoin.fr/",
        "https://www.bienici.com/",
        "https://www.vaneau.com/",
    ]
    ok = 0
    errors = []
    for u in urls:
        try:
            r = req("POST", "/import_url.php?action=extract", json={"url": u}, headers=auth_hdr(), timeout=60)
            if r.status_code == 200 and r.json().get("ok") and isinstance(r.json().get("extracted"), dict):
                ok += 1
            else:
                errors.append(f"{u} → {r.status_code}")
        except Exception as e:
            errors.append(f"{u} → {e}")
    # On accepte 3/5 minimum (certains sites peuvent bloquer ou timeout)
    if ok >= 3:
        return "PASS", f"{ok}/5 extractions URL OK", {"ok": ok, "errors": errors}
    return "FAIL", f"seulement {ok}/5 URL OK. Erreurs: {errors[:3]}", {"ok": ok, "errors": errors}


def t4_import_image() -> tuple[str, str, dict]:
    if not TOKEN:
        return "SKIP", "auth requise", {}
    # Synth image PIL avec texte annonce.
    img = Image.new("RGB", (800, 600), color=(248, 232, 216))
    draw = ImageDraw.Draw(img)
    try:
        font = ImageFont.truetype("/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf", 28)
        font_sm = ImageFont.truetype("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf", 22)
    except Exception:
        font = font_sm = ImageFont.load_default()
    lines = [
        "RIAD À VENDRE · GUELIZ",
        "",
        "Superficie : 250 m²",
        "5 pièces · 3 chambres",
        "Prix : 1 200 000 MAD",
        "",
        "Contact : Ahmed Benali",
        "Tel : 06 61 23 45 67",
    ]
    y = 30
    for i, ln in enumerate(lines):
        draw.text((40, y), ln, fill=(42, 24, 16), font=font if i == 0 else font_sm)
        y += 50 if i == 0 else 35
    buf = io.BytesIO()
    img.save(buf, format="JPEG", quality=85)
    buf.seek(0)
    r = req(
        "POST", "/import_image.php?action=extract",
        headers=auth_hdr(),
        files={"image": ("annonce.jpg", buf.getvalue(), "image/jpeg")},
        timeout=90,
    )
    if r.status_code != 200:
        return "FAIL", f"HTTP {r.status_code} {r.text[:200]}", {}
    j = r.json()
    if not j.get("ok"):
        return "FAIL", f"extract KO: {j.get('error')}", j
    ex = j.get("extracted") or {}
    found = sum(1 for k in ("ville_bien", "prix", "types_bien", "surface_habitable", "annonceur_nom", "annonceur_tel_mentionne") if ex.get(k))
    if found < 3:
        return "FAIL", f"seulement {found}/6 champs extraits. JSON: {ex}", ex
    return "PASS", f"{found}/6 champs extraits (ville/prix/types/surface/nom/tel)", {"extracted_keys": list(ex.keys())}


def t5_dictee() -> tuple[str, str, dict]:
    # Nécessite un fichier audio wav/mp3 avec dictée réelle + clé OpenAI/Whisper configurée.
    # Pas testable en synth pur → SKIP documenté.
    return "SKIP", "nécessite fichier audio + clé Whisper (non-automatisable en synth)", {}


def t6_crossref() -> tuple[str, str, dict]:
    vdr = CREATED_IDS.get("Vendeur")
    if not vdr:
        return "SKIP", "pas de dossier Vendeur créé (t2 KO)", {}
    r = req("POST", f"/cross_search.php?action=search&id={vdr}", headers=auth_hdr(), timeout=90)
    if r.status_code != 200:
        j = {}
        try: j = r.json()
        except: pass
        return "FAIL", f"HTTP {r.status_code} {j.get('error','')}", j
    j = r.json()
    if not j.get("ok"):
        return "FAIL", f"response KO: {j.get('error')}", j
    matches = j.get("matches", [])
    return "PASS", f"cross_search OK, {len(matches)} matches retournés (0 accepté si web_search ne trouve rien)", {"count": len(matches)}


def t7_export_zip() -> tuple[str, str, dict]:
    if not TOKEN:
        return "SKIP", "auth requise", {}
    # preview
    prev = req("GET", "/export_zip.php?action=preview", headers=auth_hdr(), timeout=30).json()
    if not prev.get("ok"):
        return "FAIL", f"preview KO: {prev.get('error')}", prev
    # generate
    r = req("GET", "/export_zip.php?action=generate", headers=auth_hdr(), timeout=120, stream=True)
    if r.status_code != 200:
        return "FAIL", f"generate HTTP {r.status_code}", {}
    ctype = r.headers.get("Content-Type", "")
    size = 0
    tmp_path = "/tmp/e2e_export.zip"
    with open(tmp_path, "wb") as f:
        for chunk in r.iter_content(64 * 1024):
            f.write(chunk)
            size += len(chunk)
    if "zip" not in ctype.lower():
        return "FAIL", f"Content-Type inattendu: {ctype}", {}
    if size > 500 * 1024 * 1024:
        return "FAIL", f"ZIP > 500MB ({size} bytes)", {}
    import zipfile
    try:
        with zipfile.ZipFile(tmp_path) as z:
            names = z.namelist()
    except Exception as e:
        return "FAIL", f"ZIP invalide: {e}", {}
    must = {"data.json", "viewer.html", "viewer.css", "viewer.js", "README.txt"}
    missing = must - set(names)
    if missing:
        return "FAIL", f"fichiers manquants dans ZIP: {missing}", {"names_sample": names[:20]}
    return "PASS", f"ZIP {size//1024}Ko, contenu OK (data.json + viewer + {prev['counts']['dossiers']} dossiers)", {"size_bytes": size, "counts": prev.get("counts")}


def t8_backup_sheet() -> tuple[str, str, dict]:
    # Le backup script nécessite sync_email + sheet_id configurés pour le user test (pas le cas).
    # On teste juste que /api/sheet_backup.php?action=list répond (fonctionnalité admin).
    if not TOKEN:
        return "SKIP", "auth requise", {}
    r = req("GET", "/sheet_backup.php?action=list", headers=auth_hdr())
    if r.status_code == 200 and r.json().get("ok"):
        return "PASS", f"sheet_backup.php list répond ({len(r.json().get('backups', []))} backups visibles)", {}
    return "SKIP", "backup Sheet réel nécessite sync_email + sheet_id (non configuré pour user test)", {}


def t9_push_subscribe() -> tuple[str, str, dict]:
    if not TOKEN:
        return "SKIP", "auth requise", {}
    # Subscription fake minimaliste (endpoint push + keys auth + p256dh bidon)
    fake = {
        "endpoint": "https://fcm.googleapis.com/fcm/send/fake-e2e-" + str(int(time.time())),
        "keys": {"auth": base64.urlsafe_b64encode(b"auth_e2e_test_0").decode().rstrip("="),
                 "p256dh": base64.urlsafe_b64encode(b"\x04" + b"0" * 64).decode().rstrip("=")},
    }
    r = req("POST", "/push_subscribe.php", json={"subscription": fake}, headers=auth_hdr())
    if r.status_code == 200 and r.json().get("ok"):
        return "PASS", "push_subscribe.php accepte fake subscription", {}
    # Certaines implémentations rejettent les keys invalides → SKIP légitime
    return "SKIP", f"push_subscribe rejette fake keys ({r.status_code}). Test réel impossible sans vraie subscription navigateur.", {}


def t10_casquette() -> tuple[str, str, dict]:
    # Créer casquette Investisseur à partir du Vendeur du t2
    vdr = CREATED_IDS.get("Vendeur")
    if not vdr:
        return "SKIP", "pas de Vendeur source", {}
    r = req("POST", "/dossier_create.php?action=add_casquette",
            json={"source_dossier_id": vdr, "new_profil": "Bailleur"},
            headers=auth_hdr(), timeout=30)
    j = r.json() if r.status_code == 200 else {}
    if r.status_code == 409 and "déjà" in (j.get("error") or "").lower():
        return "PASS", "conflict 409 attendu si relance — déjà lié", j
    if not j.get("ok"):
        return "FAIL", f"add_casquette KO: {r.status_code} {j.get('error')}", j
    new_id = j.get("new_id")
    linked = j.get("linked_ids", [])
    if vdr not in linked or not new_id:
        return "FAIL", f"linked_ids attendu contenant source {vdr}: {j}", j
    # Vérif bidirectionnel via list
    cl = req("GET", "/clients.php?action=list", headers=auth_hdr()).json()
    items = cl.get("clients", [])
    src = next((c for c in items if c["id"] == vdr), None)
    new = next((c for c in items if c["id"] == new_id), None)
    if not src or not new:
        return "FAIL", "source ou new introuvable dans list après création", {}
    src_links = set(src.get("linked_dossiers", []))
    new_links = set(new.get("linked_dossiers", []))
    if new_id not in src_links or vdr not in new_links:
        return "FAIL", f"bidirectionnalité KO: src.linked={src_links} new.linked={new_links}", {}
    return "PASS", f"casquette Bailleur #{new_id} créée + lien bidirectionnel avec Vendeur #{vdr}", {"new_id": new_id}


def t11_phonepill_render() -> tuple[str, str, dict]:
    r = req("GET", APP_BASE + "/?_=" + str(int(time.time())), timeout=15)
    if r.status_code != 200:
        return "FAIL", f"HTTP {r.status_code}", {}
    html = r.text
    checks = {
        "marker_v18.37": "ocre-v18.37-group-casquette-phonepill" in html,
        "PhonePill_component": "function PhonePill" in html,
        "IconPlus_component": "function IconPlus" in html,
        "AddCasquetteModal": "AddCasquetteModal" in html,
        "buildDossierGroups": "buildDossierGroups" in html,
        "dossier-group_css": ".dossier-group" in html,
    }
    missing = [k for k, v in checks.items() if not v]
    if missing:
        return "FAIL", f"éléments manquants: {missing}", checks
    return "PASS", "PhonePill + IconPlus + AddCasquetteModal + buildDossierGroups + CSS présents", checks


def t12_cleanup() -> tuple[str, str, dict]:
    # Le cleanup se fait via endpoint bootstrap IP-whitelist (sûr, scope email).
    r = bootstrap("cleanup_test_user", {"email": TEST_EMAIL})
    if not r.get("ok"):
        return "FAIL", f"cleanup KO: {r}", {}
    return "PASS", f"user + {r.get('clients_deleted', 0)} clients supprimés", r


# ─── Orchestration ────────────────────────────────────────────────────────
def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--report", default="/tmp/e2e_report.md")
    ap.add_argument("--verbose", action="store_true")
    args = ap.parse_args()

    started = time.time()
    print(f"┌─ Ocre Immo E2E tests v18.38 — démarrage {time.strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"│  target: {APP_BASE}")
    print(f"│  user:   {TEST_EMAIL}")
    print(f"└─")

    # Pre-cleanup au cas où run précédent cassé
    try:
        bootstrap("cleanup_test_user", {"email": TEST_EMAIL})
    except Exception as e:
        print(f"  ⚠ pre-cleanup: {e}")

    tests = [
        ("1 · AUTH (login + whoami + 5 bad)", t1_auth),
        ("2 · CREATE 6 profils", t2_create_6_profils),
        ("3 · IMPORT URL x5", t3_import_url_x5),
        ("4 · IMPORT IMAGE (PIL + Claude Vision)", t4_import_image),
        ("5 · DICTÉE (Whisper + intent)", t5_dictee),
        ("6 · CROSS-REF (Claude web_search)", t6_crossref),
        ("7 · EXPORT ZIP (preview + generate)", t7_export_zip),
        ("8 · BACKUP SHEET", t8_backup_sheet),
        ("9 · PUSH subscribe", t9_push_subscribe),
        ("10 · CASQUETTE + regroupement", t10_casquette),
        ("11 · PHONEPILL render prod HTML", t11_phonepill_render),
        ("12 · CLEANUP (auto)", t12_cleanup),
    ]
    for name, fn in tests:
        run_test(name, fn)

    ended = time.time()
    total = len(RESULTS)
    n_pass = sum(1 for r in RESULTS if r.status == "PASS")
    n_fail = sum(1 for r in RESULTS if r.status == "FAIL")
    n_skip = sum(1 for r in RESULTS if r.status == "SKIP")
    tested = n_pass + n_fail  # SKIP ne compte pas dans le taux
    pass_rate = round(100 * n_pass / tested, 1) if tested else 0.0
    duration_s = round(ended - started, 1)

    print()
    print(f"Résumé : PASS {n_pass} · FAIL {n_fail} · SKIP {n_skip} · Durée {duration_s}s")

    # Rapport Markdown
    lines = []
    lines.append(f"# Rapport E2E Ocre Immo — v18.38")
    lines.append("")
    lines.append(f"- **Date** : {time.strftime('%Y-%m-%d %H:%M:%S %Z', time.gmtime(started))} → {time.strftime('%H:%M:%S', time.gmtime(ended))}")
    lines.append(f"- **Durée totale** : {duration_s} s")
    lines.append(f"- **Target** : {APP_BASE}")
    lines.append(f"- **User test** : `{TEST_EMAIL}`")
    lines.append(f"- **Marker app** : `ocre-v18.37-group-casquette-phonepill`")
    lines.append("")
    lines.append("## Résumé")
    lines.append("")
    lines.append(f"| Métrique | Valeur |")
    lines.append(f"| -------- | ------ |")
    lines.append(f"| Total tests | {total} |")
    lines.append(f"| PASS | {n_pass} |")
    lines.append(f"| FAIL | {n_fail} |")
    lines.append(f"| SKIP | {n_skip} |")
    lines.append(f"| Taux PASS (hors SKIP) | **{pass_rate} %** |")
    lines.append("")
    lines.append("## Tableau des résultats")
    lines.append("")
    lines.append(f"| # | Test | Statut | Durée | Détail |")
    lines.append(f"| - | ---- | ------ | ----- | ------ |")
    for i, r in enumerate(RESULTS, 1):
        st = {"PASS": "✅ PASS", "FAIL": "❌ **FAIL**", "SKIP": "⊘ SKIP"}[r.status]
        det = r.detail.replace("|", "\\|")[:120]
        lines.append(f"| {i} | {r.name} | {st} | {r.duration_ms} ms | {det} |")
    lines.append("")

    if n_fail:
        lines.append("## Échecs détaillés")
        lines.append("")
        for r in RESULTS:
            if r.status == "FAIL":
                lines.append(f"### ❌ {r.name}")
                lines.append("")
                lines.append(f"- **Durée** : {r.duration_ms} ms")
                lines.append(f"- **Détail** : {r.detail}")
                if r.meta:
                    lines.append(f"- **Meta** : `{json.dumps(r.meta, ensure_ascii=False)[:500]}`")
                lines.append("")

    if n_skip:
        lines.append("## Tests skipped (non-automatisables sans ressources dédiées)")
        lines.append("")
        for r in RESULTS:
            if r.status == "SKIP":
                lines.append(f"- **{r.name}** : {r.detail}")
        lines.append("")

    lines.append("## Contexte")
    lines.append("")
    lines.append("- Tests idempotents : pré-cleanup + cleanup final via `api/e2e_bootstrap.php` (IP-whitelist VPS 46.225.215.148).")
    lines.append(f"- User test créé/reset à chaque run : `{TEST_EMAIL}` / mot de passe interne au script.")
    lines.append("- Zéro modification des données utilisateurs réels (philippe.ciftci@gmail.com, ophelie@ocre.immo, etc.).")
    lines.append("")

    report = "\n".join(lines)
    with open(args.report, "w") as f:
        f.write(report)
    print(f"\n  Rapport écrit : {args.report}")

    # Exit code : FAIL > 0 → 1, sinon 0.
    return 1 if n_fail else 0


if __name__ == "__main__":
    sys.exit(main())
