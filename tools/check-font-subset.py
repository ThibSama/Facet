#!/usr/bin/env python3
"""Fail closed when Facet Sans exceeds its budget or loses required glyphs."""

from __future__ import annotations

import hashlib
import json
import sys
from pathlib import Path

from fontTools.ttLib import TTFont


ROOT = Path(__file__).resolve().parents[1]
FONT_DIR = ROOT / "resources" / "fonts"
TOTAL_BUDGET = 122_880
EXPECTED_METRICS = (1974, -426, 1610, -390)
EXPECTED_LAYOUT = {
    "GSUB": {
        "case", "dlig", "dnom", "frac", "liga", "lnum", "numr",
        "onum", "ordn", "pnum", "salt", "sinf", "ss01", "ss02", "ss03",
        "ss04", "subs", "sups", "tnum",
    },
    "GPOS": {"kern", "mark"},
}
FONTS = {
    "facet-lato-regular.woff2": {
        "weight": 400,
        "subfamily": "Regular",
        "full_name": "Facet Sans Regular",
        "postscript": "FacetSans-Regular",
        "sha256": "dfc98c03e2c875bc97861dbad715340a50a1641e6d8cc1218d343286a69725c1",
    },
    "facet-lato-bold.woff2": {
        "weight": 700,
        "subfamily": "Bold",
        "full_name": "Facet Sans Bold",
        "postscript": "FacetSans-Bold",
        "sha256": "a9a28fb4e84157480a5bdb19c6634b5b124dd5ae9a3f9b12d211d6f4f4e68884",
    },
}

# Printable ASCII, Latin-1 Supplement and Latin Extended-A cover normal
# English/French letters, accents, guillemets, symbols and punctuation.
LANGUAGE_RANGES = ((0x0020, 0x007E), (0x00A0, 0x017F), (0x0300, 0x036F))
TYPOGRAPHIC_PUNCTUATION = {
    0x2000, 0x2001, 0x2002, 0x2003, 0x2004, 0x2005, 0x2006, 0x2007,
    0x2008, 0x2009, 0x200A, 0x2010, 0x2012, 0x2013, 0x2014, 0x2015,
    0x2018, 0x2019, 0x201A, 0x201B, 0x201C, 0x201D, 0x201E, 0x2020,
    0x2021, 0x2022, 0x2026, 0x2030, 0x2032, 0x2033, 0x2034, 0x2039,
    0x203A, 0x203C, 0x203D, 0x203E, 0x2044, 0x20AC,
}

# Characters the site prints but the subset deliberately does not carry.
#
# Both belong to Satoshi Run's own iconography — the bitcoin sign it asks you to
# collect and the arrow it tells you to press — and both have always been drawn
# by the system fallback stack rather than by Facet Sans: the subset is built for
# the site's *text*, and two pictograms inside a deferred overlay are not worth
# the bytes on every page load that never opens it.
#
# They became visible to this gate in PORT-137, when the run's hint line moved
# from a TypeScript constant into the translation catalog so that it could be
# said in French. Nothing about what a visitor sees changed; what changed is that
# the exception is now stated here instead of being an accident of which files
# this script happens to read.
FALLBACK_SYMBOLS = {
    0x20BF,  # ₿  BITCOIN SIGN
    0x25BC,  # ▼  BLACK DOWN-POINTING TRIANGLE
}


def source_characters() -> set[int]:
    """Collect the versioned canonical corpus and every rendered UI source."""
    def json_strings(value: object):
        if isinstance(value, str):
            yield value
        elif isinstance(value, list):
            for item in value:
                yield from json_strings(item)
        elif isinstance(value, dict):
            for item in value.values():
                yield from json_strings(item)

    # Both the canonical corpus and its translations: the English site renders
    # the same pages, so the glyphs its prose needs are part of the contract.
    canonical = "".join(
        text
        for path in sorted((ROOT / "content").rglob("*.json"))
        for text in json_strings(json.loads(path.read_text(encoding="utf-8")))
    )
    ui_paths = sorted((ROOT / "resources" / "skins" / "evolving-interface" / "views").rglob("*.php"))
    ui_paths += sorted((ROOT / "src").rglob("*.php"))
    ui = "".join(path.read_text(encoding="utf-8") for path in ui_paths)
    return {
        ord(character)
        for character in canonical + ui
        if not character.isspace() or character == " "
    }


def expected_characters() -> set[int]:
    expected = set(TYPOGRAPHIC_PUNCTUATION)
    for start, end in LANGUAGE_RANGES:
        expected.update(range(start, end + 1))
    expected.update(source_characters())
    return expected - FALLBACK_SYMBOLS


def names(font: TTFont, name_id: int) -> set[str]:
    return {record.toUnicode() for record in font["name"].names if record.nameID == name_id}


def feature_tags(font: TTFont, table: str) -> set[str]:
    return {
        record.FeatureTag
        for record in font[table].table.FeatureList.FeatureRecord
    }


def main() -> int:
    failures: list[str] = []
    required = expected_characters()
    total = 0

    for filename, contract in FONTS.items():
        path = FONT_DIR / filename
        data = path.read_bytes()
        total += len(data)
        digest = hashlib.sha256(data).hexdigest()
        if digest != contract["sha256"]:
            failures.append(f"{filename}: SHA-256 {digest}, expected {contract['sha256']}")

        font = TTFont(path)
        cmap = {
            codepoint
            for table in font["cmap"].tables
            if table.isUnicode()
            for codepoint in table.cmap
        }
        missing = sorted(required - cmap)
        if missing:
            failures.append(
                f"{filename}: missing {len(missing)} required cmap entries: "
                + " ".join(f"U+{codepoint:04X}" for codepoint in missing)
            )

        if names(font, 1) != {"Facet Sans"}:
            failures.append(f"{filename}: family name is not exactly Facet Sans")
        for name_id, key in ((2, "subfamily"), (4, "full_name"), (6, "postscript")):
            if names(font, name_id) != {contract[key]}:
                failures.append(f"{filename}: name ID {name_id} does not match {contract[key]}")
        if font["OS/2"].usWeightClass != contract["weight"]:
            failures.append(f"{filename}: weight class is not {contract['weight']}")

        metrics = (
            font["hhea"].ascent,
            font["hhea"].descent,
            font["OS/2"].sTypoAscender,
            font["OS/2"].sTypoDescender,
        )
        if metrics != EXPECTED_METRICS:
            failures.append(f"{filename}: vertical metrics changed: {metrics}")
        for table, expected in EXPECTED_LAYOUT.items():
            if table not in font:
                failures.append(f"{filename}: missing {table} layout table")
            elif feature_tags(font, table) != expected:
                failures.append(f"{filename}: {table} feature set changed")

        print(f"{filename}: {len(data)} bytes ({len(data) / 1024:.2f} KiB), {len(cmap)} cmap entries, {digest}")

    print(f"required cmap entries: {len(required)} (canonical/UI plus French/English Latin contract)")
    print(f"total WOFF2: {total} bytes ({total / 1024:.2f} KiB) / {TOTAL_BUDGET} bytes (120.00 KiB)")
    if total > TOTAL_BUDGET:
        failures.append(f"font total is {total - TOTAL_BUDGET} bytes over budget")

    if failures:
        print("Font subset gate failed:\n- " + "\n- ".join(failures), file=sys.stderr)
        return 1
    print("Font subset size, cmap, naming, metrics, layout and checksum gate: PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
