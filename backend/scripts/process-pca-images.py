#!/usr/bin/env python3
"""Remove black/dark backgrounds from PCA letterhead/footer/signature PNGs for white paper."""

from __future__ import annotations

from pathlib import Path

from PIL import Image


def process_image(
    src: Path,
    dst: Path,
    *,
    dark_threshold: int = 115,
    white_to_gray: bool = True,
) -> None:
    img = Image.open(src).convert('RGBA')
    px = img.load()
    w, h = img.size

    for y in range(h):
        for x in range(w):
            r, g, b, a = px[x, y]
            if a == 0:
                continue

            mx = max(r, g, b)
            mn = min(r, g, b)
            spread = mx - mn

            # Drop black/near-black background and dark anti-aliasing fringes.
            if mx <= dark_threshold:
                px[x, y] = (0, 0, 0, 0)
                continue

            # Former white/light-grey text on black bars → readable dark grey on white paper.
            if white_to_gray and mn >= 210 and spread <= 28:
                gray = 55 if mn >= 245 else 78
                px[x, y] = (gray, gray, gray, 255)
                continue

            # Semi-dark grey halos around removed background.
            if mx <= dark_threshold + 25 and spread <= 18:
                px[x, y] = (0, 0, 0, 0)

    dst.parent.mkdir(parents=True, exist_ok=True)
    img.save(dst, optimize=True)
    print(f'Wrote {dst} ({w}x{h})')


def main() -> None:
    root = Path(__file__).resolve().parents[2] / 'css' / 'img'

    def source(name: str) -> Path:
        pristine = root / name.replace('.png', '-pristine.png')
        return pristine if pristine.is_file() else root / name

    process_image(source('pca-letterhead.png'), root / 'pca-letterhead.png', dark_threshold=120)
    process_image(source('pca-footer.png'), root / 'pca-footer.png', dark_threshold=120)
    process_image(
        source('principal-signature.png'),
        root / 'principal-signature.png',
        dark_threshold=90,
        white_to_gray=False,
    )


if __name__ == '__main__':
    main()
