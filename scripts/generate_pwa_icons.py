#!/usr/bin/env python3
"""
V18.40.1 — génère les icônes PWA Ocre Immo (logo variante 1 cohérent avec OcreLogo React).

Sortie : /root/workspace/ocre-immo/icons/
- apple-touch-icon.png (180)
- apple-touch-icon-precomposed.png (copie)
- icon-192.png
- icon-512.png
- favicon-32.png
- favicon-16.png
(+ favicon.ico généré à partir des favicon-32/16 et posé à la racine /ocre/).

Logo spec v18.36 variante 1 validée par Philippe :
- Gradient vertical ciel blanc → crème → terre ocre : #FFFFFF 0% → #F5E8D1 40% → #8B5E3C 100%
- Border 2-3% épaisseur #8B5E3C
- O Cormorant Garamond Bold, taille 52% du côté, color #8B5E3C
- i Caveat (variable wght 500 équivalent), taille 67% du côté, color #0D0A08
- Gap 3-5% entre lettres, centrées avec compensation ascender Caveat (marginTop 3%)
- RGB (pas RGBA — iOS transparence foireuse)
- Pas de border-radius (iOS squircle auto)
"""
from __future__ import annotations
import os
import sys
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parent.parent
FONTS = ROOT / 'fonts'
ICONS = ROOT / 'icons'
ICONS.mkdir(parents=True, exist_ok=True)

CORMORANT = FONTS / 'CormorantGaramond.ttf'
CAVEAT = FONTS / 'Caveat.ttf'

if not CORMORANT.is_file() or not CAVEAT.is_file():
    print(f"ERR: fontes manquantes dans {FONTS}", file=sys.stderr)
    sys.exit(2)

# Couleurs gradient (RGB tuples)
COLOR_TOP = (0xFF, 0xFF, 0xFF)
COLOR_MID = (0xF5, 0xE8, 0xD1)
COLOR_BOT = (0x8B, 0x5E, 0x3C)
BORDER = (0x8B, 0x5E, 0x3C)
O_COLOR = (0x8B, 0x5E, 0x3C)
I_COLOR = (0x0D, 0x0A, 0x08)


def lerp(a, b, t):
    return tuple(int(a[i] + (b[i] - a[i]) * t) for i in range(3))


def make_icon(size: int) -> Image.Image:
    img = Image.new('RGB', (size, size), COLOR_TOP)
    px = img.load()
    # Gradient vertical : 0% → TOP, 40% → MID, 100% → BOT
    mid_stop = 0.4
    for y in range(size):
        t = y / max(1, size - 1)
        if t <= mid_stop:
            c = lerp(COLOR_TOP, COLOR_MID, t / mid_stop)
        else:
            c = lerp(COLOR_MID, COLOR_BOT, (t - mid_stop) / (1 - mid_stop))
        for x in range(size):
            px[x, y] = c

    draw = ImageDraw.Draw(img)

    # Border : 2-3% épaisseur selon size. Minimum 1px pour petites icônes.
    thickness = max(1, round(size * 0.03))
    for i in range(thickness):
        draw.rectangle([i, i, size - 1 - i, size - 1 - i], outline=BORDER)

    # Lettres : O Cormorant 52%, i Caveat 67%.
    o_size = max(8, round(size * 0.52))
    i_size = max(10, round(size * 0.67))
    try:
        font_o = ImageFont.truetype(str(CORMORANT), o_size)
    except Exception:
        font_o = ImageFont.load_default()
    try:
        font_i = ImageFont.truetype(str(CAVEAT), i_size)
    except Exception:
        font_i = ImageFont.load_default()

    # Mesures pour centrage.
    o_bbox = draw.textbbox((0, 0), 'O', font=font_o)
    i_bbox = draw.textbbox((0, 0), 'i', font=font_i)
    o_w = o_bbox[2] - o_bbox[0]
    o_h = o_bbox[3] - o_bbox[1]
    i_w = i_bbox[2] - i_bbox[0]
    i_h = i_bbox[3] - i_bbox[1]

    gap = max(1, round(size * 0.04))
    total_w = o_w + gap + i_w
    base_x = (size - total_w) / 2

    # Centrage vertical : on cible le centre du viewport. Compenser les bearings
    # (bbox top/left ne part pas de 0, il faut soustraire).
    o_x = base_x - o_bbox[0]
    i_x = base_x + o_w + gap - i_bbox[0]

    # Ligne de base commune. Le Caveat a un ascender prononcé ; on décale légèrement
    # vers le bas (+3% size) pour que l'œil lise le point du i aligné.
    center_y = size / 2
    o_y = center_y - (o_h / 2) - o_bbox[1]
    i_y = center_y - (i_h / 2) - i_bbox[1] + round(size * 0.03)

    draw.text((o_x, o_y), 'O', font=font_o, fill=O_COLOR)
    draw.text((i_x, i_y), 'i', font=font_i, fill=I_COLOR)

    return img


def main():
    targets = {
        'apple-touch-icon.png': 180,
        'icon-192.png': 192,
        'icon-512.png': 512,
        'favicon-32.png': 32,
        'favicon-16.png': 16,
    }
    for name, size in targets.items():
        img = make_icon(size)
        out = ICONS / name
        img.save(out, format='PNG', optimize=True)
        print(f"✓ {name} ({size}x{size}) → {out.stat().st_size} bytes")

    # Copie apple-touch-icon-precomposed.
    import shutil
    shutil.copy(ICONS / 'apple-touch-icon.png', ICONS / 'apple-touch-icon-precomposed.png')
    print(f"✓ apple-touch-icon-precomposed.png (copie)")

    # favicon.ico multi-size (16 + 32).
    im32 = Image.open(ICONS / 'favicon-32.png').convert('RGB')
    im16 = Image.open(ICONS / 'favicon-16.png').convert('RGB')
    ico_out = ICONS / 'favicon.ico'
    im32.save(ico_out, format='ICO', sizes=[(16, 16), (32, 32)], append_images=[im16])
    print(f"✓ favicon.ico → {ico_out.stat().st_size} bytes")


if __name__ == '__main__':
    main()
