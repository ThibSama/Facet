/**
 * evolving-interface — the signature hero visual.
 *
 * A hand-written WebGL2 fragment shader over one full-slot triangle: a drifting
 * Voronoi cell field shaded as cut planes, with the seams where planes meet
 * picked out as facet edges. PORT-99 measured this against a Canvas 2D
 * equivalent and chose it for main-thread cost — 0.008 ms per frame against
 * 3.4 ms on a contended machine — not for looks; see
 * docs/decisions/PORT-99-signature-hero-renderer.md.
 *
 * This module is only ever reached through a dynamic import, so a page with no
 * hero never pays for it. It is also the *only* file that knows WebGL exists:
 * every caller sees a mount that either returns a handle or returns null, and
 * null simply means the server-rendered fallback stays as it is.
 *
 * Nothing here is required to understand the page. The slot is decorative and
 * `aria-hidden`, so failing to render is a visual outcome and never an
 * informational one.
 */

/**
 * The most fragments this effect will ever be asked to shade, per frame.
 *
 * 352 x 440 — the footprint the accepted hero occupied at a device-pixel ratio
 * of one. It is a deliberate ceiling rather than a measurement of any one
 * layout: whatever a composition decides the visual should look like, this is
 * what it is allowed to cost. See the note in `resize`.
 */
const MAX_PIXELS = 154_880;

/** A mounted effect. Every resource it holds is released by `destroy()`. */
interface HeroHandle {
  destroy(): void;
}

interface Palette {
  edge: [number, number, number];
  plane: [number, number, number];
  dark: number;
}

const VERTEX_SHADER = `#version 300 es
in vec2 p;
void main() { gl_Position = vec4(p, 0.0, 1.0); }`;

const FRAGMENT_SHADER = `#version 300 es
precision mediump float;

uniform vec2 uResolution;
uniform float uTime;
uniform vec3 uEdge;
uniform vec3 uPlane;
uniform float uDark;

out vec4 fragColour;

vec2 hash2(vec2 p) {
  p = vec2(dot(p, vec2(127.1, 311.7)), dot(p, vec2(269.5, 183.3)));
  return fract(sin(p) * 43758.5453);
}

/*
 * A Voronoi pass returning, per fragment: a per-cell seed in .x (each cell is
 * one flat plane, so the seed is how much light that plane catches), and the
 * distance to the nearest cell boundary in .y (the facet edge).
 */
vec2 facets(vec2 x, float t) {
  vec2 n = floor(x);
  vec2 f = fract(x);

  vec2 nearestOffset = vec2(0.0);
  vec2 nearestVector = vec2(0.0);
  float nearest = 8.0;

  for (int j = -1; j <= 1; j++) {
    for (int i = -1; i <= 1; i++) {
      vec2 g = vec2(float(i), float(j));
      vec2 h = hash2(n + g);
      vec2 r = g + (0.5 + 0.5 * sin(t * 0.35 + 6.2831 * h)) - f;
      float d = dot(r, r);
      if (d < nearest) {
        nearest = d;
        nearestVector = r;
        nearestOffset = g;
      }
    }
  }

  float edge = 8.0;
  for (int j = -2; j <= 2; j++) {
    for (int i = -2; i <= 2; i++) {
      vec2 g = nearestOffset + vec2(float(i), float(j));
      vec2 h = hash2(n + g);
      vec2 r = g + (0.5 + 0.5 * sin(t * 0.35 + 6.2831 * h)) - f;
      vec2 delta = r - nearestVector;
      if (dot(delta, delta) > 0.00001) {
        edge = min(edge, dot(0.5 * (nearestVector + r), normalize(delta)));
      }
    }
  }

  return vec2(hash2(n + nearestOffset).x, edge);
}

void main() {
  vec2 uv = (gl_FragCoord.xy - 0.5 * uResolution) / min(uResolution.x, uResolution.y);
  float t = uTime;

  /* Large cut planes, plus a finer pass that only contributes sparkle. */
  vec2 coarse = facets(uv * 1.9 + vec2(0.0, t * 0.035), t);
  vec2 fine = facets(uv * 3.7 - vec2(t * 0.025, 0.0), t * 1.2);

  float shade = 0.35 + 0.65 * coarse.x;
  float sparkle = smoothstep(0.62, 1.0, fine.x) * 0.30;

  float seam = 1.0 - smoothstep(0.0, 0.030, coarse.y);
  float seamFine = (1.0 - smoothstep(0.0, 0.018, fine.y)) * 0.28;
  float seams = clamp(seam * 0.85 + seamFine, 0.0, 1.0);

  /* Corners stay quiet, so the effect never fights the slot's rounded edge. */
  float vignette = smoothstep(1.05, 0.10, length(uv * vec2(1.0, 0.85)));

  /*
   * The two themes are not the same picture at different brightness. In dark,
   * the planes glow and the seams catch light. In light, the planes stay
   * nearly clear and the seams read as the cut itself — an added highlight
   * would simply vanish against a near-white surface.
   */
  vec3 colour = mix(uPlane, uEdge, mix(0.88, shade * 0.5 + sparkle, uDark));
  colour += uEdge * seams * 0.42 * uDark;

  float alpha = mix(
    seams * 0.46 + shade * 0.05,
    0.10 + shade * 0.14 + seams * 0.34,
    uDark
  ) * vignette;

  fragColour = vec4(colour, clamp(alpha, 0.0, 1.0));
}`;

/** Parses `#rgb` / `#rrggbb` into linear-ish 0..1 components. */
function parseHex(value: string): [number, number, number] | null {
  const hex = value.trim().replace('#', '');

  if (!/^[0-9a-f]{3}$|^[0-9a-f]{6}$/i.test(hex)) {
    return null;
  }

  const full = hex.length === 3 ? hex.replace(/./g, (c) => c + c) : hex;
  const int = Number.parseInt(full, 16);

  return [((int >> 16) & 255) / 255, ((int >> 8) & 255) / 255, (int & 255) / 255];
}

/**
 * The palette comes from the same semantic variables the fallback paints with,
 * so the effect follows a theme switch instead of holding its own copy of the
 * colours. `--facet-surface` decides which theme is in effect: its luminance is
 * a fact about what is on screen, which `data-theme` alone is not — the
 * attribute is absent whenever the visitor is simply following their system.
 */
function readPalette(element: Element): Palette {
  const styles = getComputedStyle(element);
  const read = (token: string, fallback: [number, number, number]): [number, number, number] =>
    parseHex(styles.getPropertyValue(token)) ?? fallback;

  const surface = read('--facet-surface', [0.03, 0.04, 0.09]);
  const luminance = 0.2126 * surface[0] + 0.7152 * surface[1] + 0.0722 * surface[2];
  const dark = luminance < 0.5 ? 1 : 0;

  return {
    edge: read('--facet-accent', dark === 1 ? [0.70, 0.65, 1.0] : [0.30, 0.21, 0.78]),
    plane: read('--facet-accent-soft', dark === 1 ? [0.15, 0.13, 0.31] : [0.91, 0.89, 1.0]),
    dark,
  };
}

function compile(gl: WebGL2RenderingContext, type: number, source: string): WebGLShader | null {
  const shader = gl.createShader(type);

  if (shader === null) {
    return null;
  }

  gl.shaderSource(shader, source);
  gl.compileShader(shader);

  if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
    gl.deleteShader(shader);

    return null;
  }

  return shader;
}

/**
 * Mounts the effect inside an already-sized slot.
 *
 * Returns null — quietly, and without touching the DOM — whenever WebGL2 is
 * unavailable or refuses the context. `failIfMajorPerformanceCaveat` is
 * deliberate: a software-rasterised context is precisely the case where the
 * CSS fallback is the better product, so the request is allowed to fail.
 */
function mountHero(slot: HTMLElement): HeroHandle | null {
  const canvas = document.createElement('canvas');
  canvas.setAttribute('aria-hidden', 'true');

  const gl = canvas.getContext('webgl2', {
    alpha: true,
    antialias: false,
    depth: false,
    stencil: false,
    powerPreference: 'low-power',
    failIfMajorPerformanceCaveat: true,
  });

  if (gl === null) {
    return null;
  }

  const vertex = compile(gl, gl.VERTEX_SHADER, VERTEX_SHADER);
  const fragment = compile(gl, gl.FRAGMENT_SHADER, FRAGMENT_SHADER);
  const program = gl.createProgram();

  if (vertex === null || fragment === null || program === null) {
    return null;
  }

  gl.attachShader(program, vertex);
  gl.attachShader(program, fragment);
  gl.linkProgram(program);

  if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
    return null;
  }

  gl.useProgram(program);

  const buffer = gl.createBuffer();
  gl.bindBuffer(gl.ARRAY_BUFFER, buffer);
  // One oversized triangle covers the slot with fewer vertices than a quad.
  gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 3, -1, -1, 3]), gl.STATIC_DRAW);

  const position = gl.getAttribLocation(program, 'p');
  gl.enableVertexAttribArray(position);
  gl.vertexAttribPointer(position, 2, gl.FLOAT, false, 0, 0);

  const uResolution = gl.getUniformLocation(program, 'uResolution');
  const uTime = gl.getUniformLocation(program, 'uTime');
  const uEdge = gl.getUniformLocation(program, 'uEdge');
  const uPlane = gl.getUniformLocation(program, 'uPlane');
  const uDark = gl.getUniformLocation(program, 'uDark');

  gl.enable(gl.BLEND);
  gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);

  const applyPalette = (): void => {
    const palette = readPalette(slot);
    gl.uniform3fv(uEdge, palette.edge);
    gl.uniform3fv(uPlane, palette.plane);
    gl.uniform1f(uDark, palette.dark);
  };

  // Capped at 2: beyond that the extra fragments buy nothing visible here.
  const ratio = Math.min(window.devicePixelRatio || 1, 2);
  let width = 0;
  let height = 0;

  const resize = (): void => {
    const next = slot.getBoundingClientRect();
    /*
     * The backing store is budgeted, and the budget is why the slot's size and
     * the effect's cost stopped being the same decision.
     *
     * This shader is two Voronoi passes — thirty-four hashed cells per
     * fragment, and a sine per cell. PORT-99 measured it at 0.008 ms of main
     * thread per frame and chose it on that number, but that number is the
     * *main thread's* share: the fragments themselves are the GPU's, and on a
     * machine with no GPU worth the name they are the CPU's. So the one thing
     * that must not happen is for a composition that wants a larger visual to
     * silently buy a proportionally larger per-frame bill — which is exactly
     * what PORT-136's hero did, at two and a quarter times the fragments.
     *
     * `MAX_PIXELS` is the accepted visual's own footprint at a ratio of one,
     * so a slot that size renders exactly as it always did and nothing about
     * the accepted effect changes. A larger slot is rendered into the same
     * budget and scaled up by the compositor, which costs nothing per frame
     * and is invisible here: the field is low-frequency and its seams are
     * feathered over three per cent of the shorter side, so they are tens of
     * pixels wide before any scaling and stay soft after it.
     */
    const budget = Math.min(1, Math.sqrt(MAX_PIXELS / Math.max(1, next.width * next.height * ratio * ratio)));
    const scale = ratio * budget;
    const nextWidth = Math.max(1, Math.round(next.width * scale));
    const nextHeight = Math.max(1, Math.round(next.height * scale));

    if (nextWidth === width && nextHeight === height) {
      return;
    }

    width = nextWidth;
    height = nextHeight;
    canvas.width = width;
    canvas.height = height;
    gl.viewport(0, 0, width, height);
    gl.uniform2f(uResolution, width, height);
  };

  let frame = 0;
  let running = false;

  const draw = (time: number): void => {
    gl.uniform1f(uTime, time * 0.001);
    gl.drawArrays(gl.TRIANGLES, 0, 3);
    frame = requestAnimationFrame(draw);
  };

  const start = (): void => {
    if (running) {
      return;
    }

    running = true;
    frame = requestAnimationFrame(draw);
  };

  const stop = (): void => {
    if (!running) {
      return;
    }

    running = false;
    cancelAnimationFrame(frame);
    frame = 0;
  };

  /* Off-screen frames are wasted frames; the slot pauses when it scrolls away. */
  const visibility = new IntersectionObserver((entries) => {
    for (const entry of entries) {
      if (entry.isIntersecting) {
        start();
      } else {
        stop();
      }
    }
  });

  const box = new ResizeObserver(() => resize());

  /* A theme switch changes the semantic variables, not this module's copy. */
  const theme = new MutationObserver(() => applyPalette());
  const system = typeof window.matchMedia === 'function'
    ? window.matchMedia('(prefers-color-scheme: dark)')
    : null;
  const onSystemChange = (): void => applyPalette();

  applyPalette();
  resize();
  slot.appendChild(canvas);

  visibility.observe(slot);
  box.observe(slot);
  theme.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
  system?.addEventListener('change', onSystemChange);

  return {
    destroy(): void {
      stop();
      visibility.disconnect();
      box.disconnect();
      theme.disconnect();
      system?.removeEventListener('change', onSystemChange);

      /*
       * The GPU objects are deleted by name rather than by dropping the
       * context. `WEBGL_lose_context` would release them too, but it does it
       * by firing a context-loss event that Firefox reports in the console —
       * noise attributed to this file, for a teardown that succeeded. Deleting
       * each object is just as deterministic and says nothing.
       */
      gl.deleteProgram(program);
      gl.deleteShader(vertex);
      gl.deleteShader(fragment);
      gl.deleteBuffer(buffer);

      canvas.remove();
    },
  };
}

export { mountHero };
export type { HeroHandle };
