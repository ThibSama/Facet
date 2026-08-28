# Local fonts

Only licensed, project-local `.woff2` files belong here. Runtime downloads from
Google Fonts or another font host are not part of Facet's asset pipeline.

When a typeface is selected:

1. Record its family, weight/style, licence, source and checksum in this file.
2. Place the authorized WOFF2 binary in this directory.
3. Declare it in `fonts.css` with `src: url('./file.woff2') format('woff2')` and
   an explicit `font-display` value.
4. Apply the family in actually rendered CSS before considering preload.
5. Preload only the WOFF2 used in the initial viewport; do not preload every
   weight or an unused font.

## Selected family: Lato

- Copyright: © 2010–2011 Łukasz Dziedzic
- Licence: SIL Open Font License 1.1; see `LICENSE-Lato.txt`.
- Local source: Ubuntu package `fonts-lato`, files
  `/usr/share/fonts/truetype/lato/Lato-Regular.ttf` and `Lato-Bold.ttf`.
- Upstream recorded by the package: `http://www.latofonts.com`.
- Initial conversion: the pre-installed `python3-fonttools` module. Because
  Lato is a Reserved Font Name under the OFL, the converted font's internal
  family/name records were changed to `Facet Sans` before these subsets were
  made. The copyright, licence, designer, trademark and provenance name
  records remain present. No package, remote font service or network access
  was used.
- Subset source: the authorized, renamed WOFF2 faces committed at
  `6ed57e1bd357fbe3d7ca60ffb5270a0aa501152f`. Their SHA-256 values are recorded
  in the `Source WOFF2 SHA-256` column below. Subsetting used the already
  installed fonttools 4.61.1.

| File | Weight | Original TTF SHA-256 | Source WOFF2 SHA-256 | Subset WOFF2 SHA-256 | Bytes |
| --- | ---: | --- | --- | --- | ---: |
| `facet-lato-regular.woff2` | 400 | `6f6940be0835c3ddec9199e5fc42be4cbc61ebcfd58c623fdf719366253f1780` | `2e1eff147a26eaba324a5991dea698fc3cc935157bb097961550b4481dcf114a` | `dfc98c03e2c875bc97861dbad715340a50a1641e6d8cc1218d343286a69725c1` | 59,348 |
| `facet-lato-bold.woff2` | 700 | `bf1b8130069b44b9148eeece35e5423bedac49777ba746615b826b8276574a7b` | `3824666ebd10503bb52fa19a8fd7079d71c5c09d4acaaa1bcfa2fc57cbcf3f61` | `a9a28fb4e84157480a5bdb19c6634b5b124dd5ae9a3f9b12d211d6f4f4e68884` | 58,556 |

The retained Unicode contract is:

- `U+0020-007E`: printable Basic Latin;
- `U+00A0-017F`: Latin-1 Supplement and Latin Extended-A, including normal
  French/English diacritics, guillemets and punctuation;
- `U+0300-036F`: combining diacritical marks, so decomposed Latin text behaves
  like its precomposed equivalent;
- `U+2000-200A`, `U+2010`, `U+2012-2015`, `U+2018-201E`, `U+2020-2022`,
  `U+2026`, `U+2030`, `U+2032-2034`, `U+2039-203A`, `U+203C-203E`, `U+2044`
  and `U+20AC`: spaces, typographic quotation/dash marks, common editorial
  punctuation, fraction slash and euro sign.

This range contains every character currently found in the versioned
canonical portfolio corpus, rendered skin templates and PHP UI sources.
`tools/check-font-subset.py` derives that source set again on every run and
fails if either face lacks one of those characters, any declared language
character, its `Facet Sans` names, 400/700 weight, original vertical metrics,
required GSUB/GPOS behavior or exact checksum. It also fails when the two files
exceed 122,880 bytes total.

To reproduce the binaries from the committed authorized sources, from the
repository root:

```sh
git show 6ed57e1bd357fbe3d7ca60ffb5270a0aa501152f:resources/fonts/facet-lato-regular.woff2 > /tmp/facet-lato-regular-source.woff2
git show 6ed57e1bd357fbe3d7ca60ffb5270a0aa501152f:resources/fonts/facet-lato-bold.woff2 > /tmp/facet-lato-bold-source.woff2

python3 -m fontTools.subset /tmp/facet-lato-regular-source.woff2 --output-file=resources/fonts/facet-lato-regular.woff2 --flavor=woff2 --unicodes='U+0020-007E,U+00A0-017F,U+0300-036F,U+2000-200A,U+2010,U+2012-2015,U+2018-201E,U+2020-2022,U+2026,U+2030,U+2032-2034,U+2039-203A,U+203C-203E,U+2044,U+20AC' --layout-features='*' --layout-scripts='*' --glyph-names --symbol-cmap --legacy-cmap --notdef-glyph --notdef-outline --recommended-glyphs --name-IDs='*' --name-legacy --name-languages='*' --no-recalc-bounds --no-recalc-timestamp --no-recalc-average-width --canonical-order
python3 -m fontTools.subset /tmp/facet-lato-bold-source.woff2 --output-file=resources/fonts/facet-lato-bold.woff2 --flavor=woff2 --unicodes='U+0020-007E,U+00A0-017F,U+0300-036F,U+2000-200A,U+2010,U+2012-2015,U+2018-201E,U+2020-2022,U+2026,U+2030,U+2032-2034,U+2039-203A,U+203C-203E,U+2044,U+20AC' --layout-features='*' --layout-scripts='*' --glyph-names --symbol-cmap --legacy-cmap --notdef-glyph --notdef-outline --recommended-glyphs --name-IDs='*' --name-legacy --name-languages='*' --no-recalc-bounds --no-recalc-timestamp --no-recalc-average-width --canonical-order
python3 tools/check-font-subset.py
```

fonttools removes the original `calt` feature because none of its lookups
survives this Latin subset. All layout behavior applicable to retained glyphs
remains: kerning and mark positioning, standard/discretionary ligatures,
fractions and numerator/denominator forms, oldstyle/lining and
proportional/tabular numerals, ordinals, case forms, alternates, four stylistic
sets, and subscript/superscript forms.

The deliberate limitation is arbitrary user-generated Unicode outside this
contract: contact-message characters such as Cyrillic, Greek, IPA, emoji or
non-Latin scripts use the system fallback. They are not a reason to ship every
Lato glyph to every visitor.

The CSS and internal family name `Facet Sans` is project-local. Both faces use
`font-display: swap` and are consumed by the rendered skin.

No font is preloaded. Regular and bold are both used above the fold, and the
stylesheet-driven discovery keeps the document shell independent of Vite's
fingerprinted font filenames without preloading multiple weights.
