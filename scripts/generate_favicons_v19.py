#!/usr/bin/env python3
"""
V19 — Refonte favicons OCRE immo, haut contraste Safari iOS.

2 designs distincts :
  - Petit format (16/32/48 + ICO) : fond UNI ocre #8B5E3C, "O" blanc épais centré.
    DejaVu Sans Bold à 32px rend mieux que Cormorant trop fin — lisibilité switcher iOS.
  - Apple-touch (152/167/180) : fond crème #F5EFE6, logo Oi complet avec padding ~15%.

Tous les fichiers sont RGB strict (pas d'alpha) — Safari iOS refuse RGBA dans tab switcher.
ICO multi-size via PIL avec sizes kwargs.
"""
from __future__ import annotations
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parent.parent
FONTS = ROOT / 'fonts'

OCRE = (0x8B, 0x5E, 0x3C)
WHITE = (0xFF, 0xFF, 0xFF)
CREAM = (0xF5, 0xEF, 0xE6)
BLACK_SOFT = (0x0D, 0x0A, 0x08)

CORMORANT = FONTS / 'CormorantGaramond.ttf'
CAVEAT = FONTS / 'Caveat.ttf'
DEJAVU_BOLD = Path('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf')


def draw_small_icon(size: int) -> Image.Image:
    """Fond ocre uni + O blanc épais centré. DejaVu Sans Bold (lisible petit)."""
    img = Image.new('RGB', (size, size), OCRE)
    d = ImageDraw.Draw(img)
    # Taille du O : 70-75% de la hauteur pour dominer le carré.
    target = int(size * 0.75)
    # Recherche itérative de la taille de police qui donne une hauteur proche de target.
    font_size = target
    while font_size > 4:
        try:
            font = ImageFont.truetype(str(DEJAVU_BOLD), font_size)
        except Exception:
            font = ImageFont.load_default()
        bbox = d.textbbox((0, 0), 'O', font=font)
        th = bbox[3] - bbox[1]
        if th <= target:
            break
        font_size -= 1
    tw = bbox[2] - bbox[0]
    x = (size - tw) // 2 - bbox[0]
    y = (size - th) // 2 - bbox[1]
    d.text((x, y), 'O', fill=WHITE, font=font)
    return img


def draw_apple_touch(size: int) -> Image.Image:
    """Fond crème + logo Oi complet centré avec padding 15%."""
    img = Image.new('RGB', (size, size), CREAM)
    d = ImageDraw.Draw(img)
    pad = int(size * 0.15)
    inner = size - 2 * pad
    # O Cormorant + i Caveat, côte à côte.
    # Cherche fontsize O qui occupe ~50% de inner en hauteur.
    o_target = int(inner * 0.72)
    o_fs = o_target
    while o_fs > 4:
        font_o = ImageFont.truetype(str(CORMORANT), o_fs)
        bbox_o = d.textbbox((0, 0), 'O', font=font_o)
        if (bbox_o[3] - bbox_o[1]) <= o_target:
            break
        o_fs -= 1
    i_target = int(inner * 0.88)  # Caveat a plus de hauteur visuelle
    i_fs = i_target
    while i_fs > 4:
        font_i = ImageFont.truetype(str(CAVEAT), i_fs)
        bbox_i = d.textbbox((0, 0), 'i', font=font_i)
        if (bbox_i[3] - bbox_i[1]) <= i_target:
            break
        i_fs -= 1
    # Layout : O puis i séparés par gap ~5% de inner.
    gap = max(2, int(inner * 0.04))
    ow = bbox_o[2] - bbox_o[0]
    iw = bbox_i[2] - bbox_i[0]
    total_w = ow + gap + iw
    x0 = (size - total_w) // 2
    # Aligne baselines : placement vertical sur la baseline commune.
    # ImageFont baseline = hauteur ascender. On aligne les bas visuels.
    oh = bbox_o[3] - bbox_o[1]
    ih = bbox_i[3] - bbox_i[1]
    base_y = (size + max(oh, ih)) // 2
    y_o = base_y - bbox_o[3]
    y_i = base_y - bbox_i[3] - int(size * 0.02)  # Caveat 'i' descendu un poil
    d.text((x0 - bbox_o[0], y_o), 'O', fill=OCRE, font=font_o)
    d.text((x0 + ow + gap - bbox_i[0], y_i), 'i', fill=BLACK_SOFT, font=font_i)
    return img


def save_rgb(img: Image.Image, path: Path):
    if img.mode != 'RGB':
        img = img.convert('RGB')
    path.parent.mkdir(parents=True, exist_ok=True)
    img.save(path, format='PNG', optimize=True)
    return path.stat().st_size


def main():
    # Petit format : 16, 32, 48 (base ICO).
    small_16 = draw_small_icon(16)
    small_32 = draw_small_icon(32)
    small_48 = draw_small_icon(48)

    save_rgb(small_32, ROOT / 'icon-32.png')
    save_rgb(small_16, ROOT / 'icon-16.png')
    # ICO multi-size RGB strict (pas d'alpha dans les sous-PNG).
    small_48.save(ROOT / 'icon.ico', format='ICO', sizes=[(16, 16), (32, 32), (48, 48)])
    # Legacy names avec même contenu pour non-régression.
    save_rgb(small_32, ROOT / 'favicon-32.png')
    save_rgb(small_16, ROOT / 'favicon-16.png')
    small_48.save(ROOT / 'favicon.ico', format='ICO', sizes=[(16, 16), (32, 32), (48, 48)])

    # Apple-touch variants.
    for s in (152, 167, 180):
        at = draw_apple_touch(s)
        save_rgb(at, ROOT / f'ocre-ios-{s}.png')
        # Legacy : apple-touch-icon*.png
        save_rgb(at, ROOT / f'apple-touch-icon-{s}.png')
    # Fallback apple-touch-icon.png (180).
    at180 = draw_apple_touch(180)
    save_rgb(at180, ROOT / 'apple-touch-icon.png')
    save_rgb(at180, ROOT / 'apple-touch-icon-precomposed.png')

    # Audit sizes + mode.
    for f in ['icon.ico', 'icon-32.png', 'icon-16.png',
              'ocre-ios-180.png', 'ocre-ios-167.png', 'ocre-ios-152.png',
              'favicon.ico', 'favicon-32.png', 'favicon-16.png',
              'apple-touch-icon.png']:
        p = ROOT / f
        im = Image.open(p)
        print(f'  {f:28s} {p.stat().st_size:6d}B  mode={im.mode}  size={im.size}')


if __name__ == '__main__':
    main()
