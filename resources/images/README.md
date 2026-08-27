# Local images

Final portfolio media is not selected yet. When authorized files arrive, keep
the fallback plus optional AVIF/WebP variants here and import them through a
Vite entrypoint so production emits fingerprinted files in `public/build`.

Files that PHP addresses directly must be manifest-addressable build inputs.
Use `Facet\Asset\ResponsiveImage::fromManifest()` with their manifest keys,
intrinsic pixel width/height and accessible description. Skins can then emit a
`<picture>` using `modernSources()` followed by the fallback `source()` without
hard-coding build filenames.

Do not put final images in `public/` under stable names: doing so bypasses Vite
fingerprinting and makes long-lived immutable caching unsafe.
