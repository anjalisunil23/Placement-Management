#!/usr/bin/env python3
"""Remove black backgrounds from PCA letterhead/footer/signature PNGs for white paper."""

from __future__ import annotations

from pathlib import Path

from PIL import Image


def process_image(src: Path, dst: Path, *, black_threshold: int = 42, white_to_gray: bool = True) -> None:
    img = Image.open(src).convert('RGBA')
    px = img.load()
    w, h = img.size
    for y in range(h):
        for x in range(w):
            r, g, b, a = px[x, y]
            mx = max(r, g, b)
            mn = min(r, g, b)
            if mx <= black_threshold:
                px[x, y] = (0, 0, 0, 0)
                continue
            if white_to_gray and mn >= 215 and (mx - mn) <= 25:
                # Former white/light-grey text on black bar → readable on white paper.
                gray = 72 if mn >= 240 else 98
                px[x, y] = (gray, gray, gray, 255)
    dst.parent.mkdir(parents=True, exist_ok=True)
    img.save(dst, optimize=True)
    print(f'Wrote {dst}')


def main() -> None:
    root = Path(__file__).resolve().parents[2] / 'css' / 'img'
    files = [
        'pca-letterhead.png',
        'pca-footer.png',
        'principal-signature.png',
    ]
    for name in files:
        src = root / name
        if not src.is_file():
            raise SystemExit(f'Missing {src}')
        process_image(src, src, white_to_gray=name != 'principal-signature.png')


if __name__ == '__main__':
    main()
