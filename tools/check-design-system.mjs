import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const root = resolve(import.meta.dirname, '..');
const skinPath = resolve(root, 'resources/skins/evolving-interface/skin.css');
const fontsPath = resolve(root, 'resources/fonts/fonts.css');
const skin = await readFile(skinPath, 'utf8');
const fonts = await readFile(fontsPath, 'utf8');

const failures = [];
const requiredTokens = [
  'font-sans', 'text-base', 'text-display', 'space-1', 'space-9',
  'radius-sm', 'radius-lg', 'border-width', 'shadow-sm', 'shadow-md',
  'duration-fast', 'duration-slow', 'ease-standard', 'surface-elevated',
  'ink-muted', 'ink-subtle', 'border-strong', 'accent-hover', 'accent-soft',
  'focus', 'danger', 'danger-soft', 'disabled', 'disabled-surface',
];

for (const token of requiredTokens) {
  if (!skin.includes(`--facet-${token}:`)) failures.push(`missing --facet-${token}`);
}

const declarations = new Map();
for (const match of skin.matchAll(/--(facet-(?:light|dark)-[\w-]+):\s*(#[0-9a-f]{6})\s*;/gi)) {
  declarations.set(match[1], match[2]);
}

function channel(value) {
  const linear = value / 255;
  return linear <= 0.04045 ? linear / 12.92 : ((linear + 0.055) / 1.055) ** 2.4;
}

function luminance(hex) {
  const values = hex.slice(1).match(/../g).map((part) => Number.parseInt(part, 16));
  return 0.2126 * channel(values[0]) + 0.7152 * channel(values[1]) + 0.0722 * channel(values[2]);
}

function contrast(a, b) {
  const [high, low] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (high + 0.05) / (low + 0.05);
}

const contrastResults = [];
for (const theme of ['light', 'dark']) {
  const token = (name) => declarations.get(`facet-${theme}-${name}`);
  const pairs = [
    ['normal text', 'ink', 'surface', 4.5],
    ['muted text', 'muted', 'surface', 4.5],
    ['subtle text', 'subtle', 'surface', 4.5],
    ['link', 'accent', 'surface', 4.5],
    ['primary control', 'accent-ink', 'accent', 4.5],
    ['focus', 'focus', 'surface', 3],
    ['error', 'danger', 'surface', 4.5],
    ['control border', 'border-strong', 'surface', 3],
    ['disabled', 'disabled', 'disabled-surface', 4.5],
  ];

  for (const [label, foreground, background, minimum] of pairs) {
    const fg = token(foreground);
    const bg = token(background);
    if (!fg || !bg) {
      failures.push(`${theme} ${label}: missing colour token`);
      continue;
    }
    const ratio = contrast(fg, bg);
    contrastResults.push(`${theme} ${label}: ${ratio.toFixed(2)}:1 (minimum ${minimum}:1)`);
    if (ratio < minimum) failures.push(`${theme} ${label}: ${ratio.toFixed(2)}:1`);
  }
}

const fontFiles = [
  ['facet-lato-regular.woff2', '2e1eff147a26eaba324a5991dea698fc3cc935157bb097961550b4481dcf114a'],
  ['facet-lato-bold.woff2', '3824666ebd10503bb52fa19a8fd7079d71c5c09d4acaaa1bcfa2fc57cbcf3f61'],
];

for (const [name, expected] of fontFiles) {
  const data = await readFile(resolve(root, 'resources/fonts', name));
  const actual = createHash('sha256').update(data).digest('hex');
  if (actual !== expected) failures.push(`${name}: checksum mismatch`);
  if (!fonts.includes(`url('./${name}') format('woff2')`)) failures.push(`${name}: ineffective @font-face src`);
}

if ((fonts.match(/font-display:\s*swap/g) ?? []).length !== 2) failures.push('each font face must declare font-display: swap');
if (/https?:\/\//i.test(fonts)) failures.push('remote URL in font CSS');
if (!skin.includes("--facet-font-sans: 'Facet Sans'")) failures.push('self-hosted family is not consumed');

console.log(contrastResults.join('\n'));
if (failures.length > 0) {
  console.error(`Design-system check failed:\n- ${failures.join('\n- ')}`);
  process.exitCode = 1;
} else {
  console.log('Design-system tokens, contrast, local font declarations and checksums: PASS');
}
