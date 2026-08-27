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

There is currently no authorized WOFF2 in the repository and therefore no
`@font-face` or font preload.
