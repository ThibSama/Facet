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
- Conversion: the pre-installed `python3-fonttools` module, invoked as
  `python3 -m fontTools.subset` with WOFF2 output, all Unicode codepoints,
  layout features and recommended glyphs retained. Because Lato is a Reserved
  Font Name under the OFL, the converted font's internal family/name records
  were changed to `Facet Sans`. No package or network access was used.

| File | Weight | Source SHA-256 | WOFF2 SHA-256 |
| --- | ---: | --- | --- |
| `facet-lato-regular.woff2` | 400 | `6f6940be0835c3ddec9199e5fc42be4cbc61ebcfd58c623fdf719366253f1780` | `2e1eff147a26eaba324a5991dea698fc3cc935157bb097961550b4481dcf114a` |
| `facet-lato-bold.woff2` | 700 | `bf1b8130069b44b9148eeece35e5423bedac49777ba746615b826b8276574a7b` | `3824666ebd10503bb52fa19a8fd7079d71c5c09d4acaaa1bcfa2fc57cbcf3f61` |

The CSS and internal family name `Facet Sans` is project-local. Both faces use
`font-display: swap` and are consumed by the rendered skin.

No font is preloaded. Regular and bold are both used above the fold, and the
stylesheet-driven discovery keeps the document shell independent of Vite's
fingerprinted font filenames without preloading multiple weights.
