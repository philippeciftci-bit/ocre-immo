#!/usr/bin/env python3
"""
V18.41 — splash screens iOS PWA (logo v1 centré 30 % largeur sur fond crème #F0E8D8).

10 résolutions portraits iPhone + iPad, noms cohérents avec les media queries du <head>.
Sortie : /root/workspace/ocre-immo/icons/splash/
"""
from __future__ import annotations
import sys
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parent.parent
FONTS = ROOT / 'fonts'
OUT = ROOT / 'icons' / 'splash'
OUT.mkdir(parents=True, exist_ok=True)

CORMORANT = FONTS / 'CormorantGaramond.ttf'
CAVEAT = FONTS / 'Caveat.ttf'
if not CORMORANT.is_file() or not CAVEAT.is_file():
    print('ERR: fonts manquantes — lance v18.40.1 generate_pwa_icons d\'abord', file=sys.stderr)
    sys.exit(2)

BG = (0xF0, 0xE8, 0xD8)            # fond app
COLOR_TOP = (0xFF, 0xFF, 0xFF)
COLOR_MID = (0xF5, 0xE8, 0xD1)
COLOR_BOT = (0x8B, 0x5E, 0x3C)
BORDER = (0x8B, 0x5E, 0x3C)
O_COLOR = (0x8B, 0x5E, 0x3C)
I_COLOR = (0x0D, 0x0A, 0x08)


def lerp(a, b, t):
    return tuple(int(a[i] + (b[i] - a[i]) * t) for i in range(3))


def build_logo(size: int) -> Image.Image:
    """Réplique OcreLogo variante 1 sur un carré `size` x `size` (RGB)."""
    img = Image.new('RGB', (size, size), COLOR_TOP)
    px = img.load()
    mid_stop = 0.4
    for y in range(size):
        t = y / max(1, size - 1)
        c = lerp(COLOR_TOP, COLOR_MID, t / mid_stop) if t <= mid_stop else lerp(COLOR_MID, COLOR_BOT, (t - mid_stop) / (1 - mid_stop))
        for x in range(size):
            px[x, y] = c
    draw = ImageDraw.Draw(img)
    thickness = max(2, round(size * 0.025))
    for i in range(thickness):
        draw.rectangle([i, i, size - 1 - i, size - 1 - i], outline=BORDER)
    o_size = max(24, round(size * 0.52))
    i_size = max(32, round(size * 0.67))
    font_o = ImageFont.truetype(str(CORMORANT), o_size)
    font_i = ImageFont.truetype(str(CAVEAT), i_size)
    o_bb = draw.textbbox((0, 0), 'O', font=font_o)
    i_bb = draw.textbbox((0, 0), 'i', font=font_i)
    o_w, o_h = o_bb[2] - o_bb[0], o_bb[3] - o_bb[1]
    i_w, i_h = i_bb[2] - i_bb[0], i_bb[3] - i_bb[1]
    gap = max(2, round(size * 0.04))
    total_w = o_w + gap + i_w
    base_x = (size - total_w) / 2
    cy = size / 2
    draw.text((base_x - o_bb[0], cy - o_h / 2 - o_bb[1]), 'O', font=font_o, fill=O_COLOR)
    draw.text((base_x + o_w + gap - i_bb[0], cy - i_h / 2 - i_bb[1] + round(size * 0.03)), 'i', font=font_i, fill=I_COLOR)
    return img


def make_splash(w: int, h: int) -> Image.Image:
    bg = Image.new('RGB', (w, h), BG)
    # Logo centré 30 % largeur.
    logo_size = round(min(w, h) * 0.30)
    logo = build_logo(logo_size)
    # Rounded corners synthétiques : Apple applique la squircle lui-même pour l'icône de
    # l'app, mais pour le splash le logo affiché dans la toile est plein carré — acceptable.
    bx = (w - logo_size) // 2
    by = (h - logo_size) // 2 - round(h * 0.03)   # légèrement haut pour aérer visuellement
    bg.paste(logo, (bx, by))
    return bg


# Résolutions portraits iPhone + iPad (width × height, names pour le filename)
TARGETS = [
    (1290, 2796, 'iphone-15-pro-max'),    # iPhone 15 Pro Max, 14 Pro Max
    (1179, 2556, 'iphone-15-pro'),        # iPhone 15 Pro, 14 Pro
    (1170, 2532, 'iphone-14'),            # iPhone 14, 13, 12
    (1125, 2436, 'iphone-x'),             # iPhone X/XS/11 Pro
    (828, 1792, 'iphone-xr'),             # iPhone XR, 11
    (750, 1334, 'iphone-8'),              # iPhone 8, 7, 6s
    (2048, 2732, 'ipad-pro-12'),          # iPad Pro 12.9"
    (1668, 2388, 'ipad-pro-11'),          # iPad Pro 11"
    (1620, 2160, 'ipad-air-10'),          # iPad Air 10.9"
    (1536, 2048, 'ipad-9'),               # iPad 9.7 / mini 5
]

def main():
    for w, h, name in TARGETS:
        img = make_splash(w, h)
        out = OUT / f'splash-{name}-{w}x{h}.png'
        img.save(out, format='PNG', optimize=True)
        print(f"✓ {out.name} ({out.stat().st_size // 1024} Ko)")

if __name__ == '__main__':
    main()
