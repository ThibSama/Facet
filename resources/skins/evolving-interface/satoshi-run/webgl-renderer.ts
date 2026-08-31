/**
 * Satoshi Run — the accelerated renderer.
 *
 * Hand-written WebGL2, no scene library and no dependency: two primitives — a
 * unit cube and a unit disc — uploaded once and drawn as a few hundred
 * instances per frame through `drawArraysInstanced`. Two draw calls cover the
 * whole picture, so the per-frame cost is an array upload rather than a scene
 * traversal.
 *
 * ## The composition is the feature
 *
 * This is a Dino Run, and a Dino Run is read side-on. The camera therefore
 * looks straight across the lane, level with it, from a fixed place: the
 * runner holds a stable column near the left of the frame, the world travels
 * right-to-left towards him, and a glance tells you what to jump and what to
 * duck. Nothing here is allowed to make that harder to read.
 *
 * What the third dimension buys is *presentation*, not play. Every solid is a
 * real box or disc in a real perspective, so it has lit and shaded faces; the
 * scenery sits at negative depth, so it parallaxes for free rather than
 * through a hand-tuned scroll factor; and everything on the lane casts a
 * contact shadow onto the ground. The play stays on the single plane z = 0,
 * which is the plane the simulation has always described.
 *
 * ## Satoshi
 *
 * Satoshi Nakamoto is a person nobody has seen; inventing a likeness would be
 * inventing a fact. What runs is an *icon* — hooded silhouette, round head, the
 * ₿ worn on the chest — which is the idea rather than a claim about anyone's
 * face. It is built to read at a couple of dozen pixels tall, which is the
 * size it is actually played at.
 */
import { mix, readPalette } from './renderer';
import type { Colour, Frame, Palette, RendererOptions, RunRenderer } from './renderer';
import { DUCKING_HEIGHT, STANDING_HEIGHT } from './world';
import type { Obstacle, World } from './world';

/** A point or a direction in lane space. Same shape as a colour, not the same thing. */
type Vec3 = [number, number, number];

/** Eleven floats per instance: offset, scale, colour, spin, glow. */
const STRIDE = 11;
const MAX_BOXES = 768;
const MAX_DISCS = 192;

/** Sides on the disc. Twenty is round at every size this game is played at. */
const DISC_SEGMENTS = 20;

/**
 * The composition, as three numbers.
 *
 * `RUNNER_COLUMN` and `GROUND_LINE` are fractions of the frame, and they are
 * the whole of the Dino-style layout: the runner stands a fifth of the way in
 * and the ground sits low, leaving the upper frame to the scenery. `FOV` is
 * narrow on purpose — a long lens flattens the perspective, which is exactly
 * what keeps a 3D scene reading as a side-on 2D one.
 */
const RUNNER_COLUMN = 0.22;
const GROUND_LINE = 0.76;
const FOV = (30 * Math.PI) / 180;

/**
 * How much lane is shown, in world units, at a wide and at a narrow stage.
 *
 * The wide figure is set by fairness: at the top speed of 27 units/s the lane
 * ahead of the runner has to be worth about three quarters of a second, and
 * `WIDE_SPAN × (1 - RUNNER_COLUMN)` is. The narrow figure is set by
 * readability: a phone held upright cannot show that much lane without
 * shrinking the runner to a full stop, so it shows less lane instead. Between
 * the two the span is interpolated on the stage's aspect.
 */
const WIDE_SPAN = 24;
const NARROW_SPAN = 13.5;

const VERTEX_SHADER = `#version 300 es
in vec3 aPosition;
in vec3 aNormal;
in vec3 iOffset;
in vec3 iScale;
in vec3 iColour;
in float iSpin;
in float iGlow;

uniform mat4 uViewProjection;

out vec3 vColour;
out vec3 vNormal;
out vec3 vWorld;
out float vGlow;

void main() {
  float s = sin(iSpin);
  float c = cos(iSpin);
  mat3 spin = mat3(c, 0.0, -s, 0.0, 1.0, 0.0, s, 0.0, c);

  vec3 local = spin * (aPosition * iScale);
  vec3 world = local + iOffset;

  /* Non-uniform scale: the inverse scale keeps the normal perpendicular. */
  vNormal = normalize(spin * (aNormal / max(abs(iScale), vec3(0.0001))));
  vColour = iColour;
  vGlow = iGlow;
  /*
   * The world position travels to the fragment stage, and the distance haze is
   * worked out there rather than here.
   *
   * Doing it per vertex is the cheaper and the wrong answer. The ground is one
   * box two hundred units long, so its corners are a hundred units from the
   * camera even where the part you are looking at is five — and a value
   * interpolated between those corners washes the whole lane out to the
   * background colour. The lane went pale, the picture lost its floor, and the
   * cause was two vertices nobody can see.
   */
  vWorld = world;

  gl_Position = uViewProjection * vec4(world, 1.0);
}`;

const FRAGMENT_SHADER = `#version 300 es
precision mediump float;

in vec3 vColour;
in vec3 vNormal;
in vec3 vWorld;
in float vGlow;

uniform vec3 uFog;
uniform vec3 uKey;
uniform vec3 uFill;
uniform vec3 uCamera;
uniform vec2 uFogRange;

out vec4 fragColour;

void main() {
  vec3 normal = normalize(vNormal);
  float vFog = clamp((distance(vWorld, uCamera) - uFogRange.x) / uFogRange.y, 0.0, 1.0);

  /* A key light across the lane and a dim fill from behind the camera, so the
     face turned to the viewer, the face turned to the light and the face
     turned away all read as three different planes. */
  float key = max(dot(normal, uKey), 0.0);
  float fill = max(dot(normal, uFill), 0.0);

  vec3 lit = vColour * (0.44 + 0.48 * key + 0.22 * fill);
  lit = mix(lit, vColour, vGlow);
  lit += vColour * vGlow * 0.7;

  fragColour = vec4(mix(lit, uFog, vFog), 1.0);
}`;

/** A unit cube, centred, non-indexed: position and normal per vertex. */
function cubeGeometry(): number[] {
  const faces: Array<{ normal: Vec3; corners: Vec3[] }> = [
    { normal: [0, 0, 1], corners: [[-0.5, -0.5, 0.5], [0.5, -0.5, 0.5], [0.5, 0.5, 0.5], [-0.5, 0.5, 0.5]] },
    { normal: [0, 0, -1], corners: [[0.5, -0.5, -0.5], [-0.5, -0.5, -0.5], [-0.5, 0.5, -0.5], [0.5, 0.5, -0.5]] },
    { normal: [1, 0, 0], corners: [[0.5, -0.5, 0.5], [0.5, -0.5, -0.5], [0.5, 0.5, -0.5], [0.5, 0.5, 0.5]] },
    { normal: [-1, 0, 0], corners: [[-0.5, -0.5, -0.5], [-0.5, -0.5, 0.5], [-0.5, 0.5, 0.5], [-0.5, 0.5, -0.5]] },
    { normal: [0, 1, 0], corners: [[-0.5, 0.5, 0.5], [0.5, 0.5, 0.5], [0.5, 0.5, -0.5], [-0.5, 0.5, -0.5]] },
    { normal: [0, -1, 0], corners: [[-0.5, -0.5, -0.5], [0.5, -0.5, -0.5], [0.5, -0.5, 0.5], [-0.5, -0.5, 0.5]] },
  ];

  const data: number[] = [];

  for (const face of faces) {
    for (const index of [0, 1, 2, 0, 2, 3]) {
      data.push(...face.corners[index], ...face.normal);
    }
  }

  return data;
}

/**
 * A unit disc: a short cylinder lying on the Z axis, so it faces the camera.
 *
 * It is what stops a coin being a cube. A Bitcoin that is not round is not a
 * Bitcoin, and no amount of colour or glow on a box recovers the read — the
 * silhouette is the whole of the recognition. The same primitive rounds
 * Satoshi's head and softens every contact shadow.
 */
function discGeometry(): number[] {
  const data: number[] = [];
  const step = (Math.PI * 2) / DISC_SEGMENTS;

  for (let index = 0; index < DISC_SEGMENTS; index += 1) {
    const a = index * step;
    const b = (index + 1) * step;
    const ax = Math.cos(a) * 0.5;
    const ay = Math.sin(a) * 0.5;
    const bx = Math.cos(b) * 0.5;
    const by = Math.sin(b) * 0.5;

    /* Front cap, wound anticlockwise seen from +Z. */
    data.push(0, 0, 0.5, 0, 0, 1, ax, ay, 0.5, 0, 0, 1, bx, by, 0.5, 0, 0, 1);
    /* Back cap. */
    data.push(0, 0, -0.5, 0, 0, -1, bx, by, -0.5, 0, 0, -1, ax, ay, -0.5, 0, 0, -1);

    /* The rim, two triangles, with radial normals so it catches the key. */
    const an: Vec3 = [Math.cos(a), Math.sin(a), 0];
    const bn: Vec3 = [Math.cos(b), Math.sin(b), 0];
    data.push(ax, ay, 0.5, ...an, ax, ay, -0.5, ...an, bx, by, -0.5, ...bn);
    data.push(ax, ay, 0.5, ...an, bx, by, -0.5, ...bn, bx, by, 0.5, ...bn);
  }

  return data;
}

function perspective(fovy: number, aspect: number, near: number, far: number): Float32Array {
  const f = 1 / Math.tan(fovy / 2);
  const out = new Float32Array(16);

  out[0] = f / aspect;
  out[5] = f;
  out[10] = (far + near) / (near - far);
  out[11] = -1;
  out[14] = (2 * far * near) / (near - far);

  return out;
}

function lookAt(eye: Vec3, target: Vec3, out: Float32Array): Float32Array {
  let zx = eye[0] - target[0];
  let zy = eye[1] - target[1];
  let zz = eye[2] - target[2];
  const zl = Math.hypot(zx, zy, zz) || 1;
  zx /= zl;
  zy /= zl;
  zz /= zl;

  // up is (0, 1, 0); the cross product with it is never degenerate here
  // because the camera always looks across the lane rather than straight down.
  let xx = zz;
  let xy = 0;
  let xz = -zx;
  const xl = Math.hypot(xx, xy, xz) || 1;
  xx /= xl;
  xy /= xl;
  xz /= xl;

  const yx = zy * xz - zz * xy;
  const yy = zz * xx - zx * xz;
  const yz = zx * xy - zy * xx;

  out[0] = xx; out[1] = yx; out[2] = zx; out[3] = 0;
  out[4] = xy; out[5] = yy; out[6] = zy; out[7] = 0;
  out[8] = xz; out[9] = yz; out[10] = zz; out[11] = 0;
  out[12] = -(xx * eye[0] + xy * eye[1] + xz * eye[2]);
  out[13] = -(yx * eye[0] + yy * eye[1] + yz * eye[2]);
  out[14] = -(zx * eye[0] + zy * eye[1] + zz * eye[2]);
  out[15] = 1;

  return out;
}

function multiply(a: Float32Array, b: Float32Array, out: Float32Array): Float32Array {
  for (let column = 0; column < 4; column += 1) {
    const b0 = b[column * 4];
    const b1 = b[column * 4 + 1];
    const b2 = b[column * 4 + 2];
    const b3 = b[column * 4 + 3];

    for (let row = 0; row < 4; row += 1) {
      out[column * 4 + row] = a[row] * b0 + a[4 + row] * b1 + a[8 + row] * b2 + a[12 + row] * b3;
    }
  }

  return out;
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

/** One decorative spark. Purely visual, never fed back into the simulation. */
interface Spark {
  x: number;
  y: number;
  z: number;
  vx: number;
  vy: number;
  vz: number;
  life: number;
  colour: Colour;
}

/**
 * A stable pseudo-random number for a position on the lane.
 *
 * The scenery has to be varied without being remembered: a tower's height must
 * be the same every frame it is on screen, and it must not have to be stored
 * anywhere to stay that way. Hashing the index is how a background gets
 * variety for no state at all — and, incidentally, why the scenery is
 * identical between the two renderers.
 */
function hash(index: number): number {
  const value = Math.sin(index * 127.1 + 311.7) * 43758.5453;

  return value - Math.floor(value);
}

/**
 * Mounts the accelerated renderer, or answers null.
 *
 * Null is not a failure to report: it is the answer "this browser should be
 * shown the 2D lane instead", and `createRenderer` acts on it without telling
 * anybody. `failIfMajorPerformanceCaveat` is deliberate — a software
 * rasteriser running a 3D scene at 8 fps is worse than a 2D one running at 60.
 */
function mountWebglRenderer(canvas: HTMLCanvasElement, options: RendererOptions): RunRenderer | null {
  const gl = canvas.getContext('webgl2', {
    alpha: false,
    antialias: true,
    depth: true,
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
    gl.deleteProgram(program);

    return null;
  }

  gl.useProgram(program);

  /*
   * Both primitives live in one buffer, one after the other, and each batch is
   * drawn with a `first` offset into it. Two shapes therefore cost two draw
   * calls and one upload each — not two programs, two buffers and two pipeline
   * switches.
   */
  const cube = cubeGeometry();
  const disc = discGeometry();
  const CUBE_VERTICES = cube.length / 6;
  const DISC_VERTICES = disc.length / 6;

  const geometry = gl.createBuffer();
  gl.bindBuffer(gl.ARRAY_BUFFER, geometry);
  gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([...cube, ...disc]), gl.STATIC_DRAW);

  const aPosition = gl.getAttribLocation(program, 'aPosition');
  const aNormal = gl.getAttribLocation(program, 'aNormal');
  const instanceAttributes: Array<[string, number, number]> = [
    ['iOffset', 3, 0],
    ['iScale', 3, 12],
    ['iColour', 3, 24],
    ['iSpin', 1, 36],
    ['iGlow', 1, 40],
  ];

  /** One batch: a shape's slice of the geometry, its instances, and its VAO. */
  interface Batch {
    readonly first: number;
    readonly vertices: number;
    readonly data: Float32Array;
    readonly buffer: WebGLBuffer;
    readonly vao: WebGLVertexArrayObject;
    count: number;
  }

  const createBatch = (first: number, vertices: number, capacity: number): Batch | null => {
    const vao = gl.createVertexArray();
    const buffer = gl.createBuffer();

    if (vao === null || buffer === null) {
      return null;
    }

    gl.bindVertexArray(vao);

    gl.bindBuffer(gl.ARRAY_BUFFER, geometry);
    gl.enableVertexAttribArray(aPosition);
    gl.vertexAttribPointer(aPosition, 3, gl.FLOAT, false, 24, 0);
    gl.enableVertexAttribArray(aNormal);
    gl.vertexAttribPointer(aNormal, 3, gl.FLOAT, false, 24, 12);

    const data = new Float32Array(capacity * STRIDE);
    gl.bindBuffer(gl.ARRAY_BUFFER, buffer);
    gl.bufferData(gl.ARRAY_BUFFER, data.byteLength, gl.DYNAMIC_DRAW);

    for (const [name, size, offset] of instanceAttributes) {
      const location = gl.getAttribLocation(program, name);
      gl.enableVertexAttribArray(location);
      gl.vertexAttribPointer(location, size, gl.FLOAT, false, STRIDE * 4, offset);
      gl.vertexAttribDivisor(location, 1);
    }

    gl.bindVertexArray(null);

    return { first, vertices, data, buffer, vao, count: 0 };
  };

  const boxes = createBatch(0, CUBE_VERTICES, MAX_BOXES);
  const discs = createBatch(CUBE_VERTICES, DISC_VERTICES, MAX_DISCS);

  if (boxes === null || discs === null) {
    gl.deleteProgram(program);

    return null;
  }

  const uViewProjection = gl.getUniformLocation(program, 'uViewProjection');
  const uCamera = gl.getUniformLocation(program, 'uCamera');
  const uFog = gl.getUniformLocation(program, 'uFog');
  const uFogRange = gl.getUniformLocation(program, 'uFogRange');
  const uKey = gl.getUniformLocation(program, 'uKey');
  const uFill = gl.getUniformLocation(program, 'uFill');

  gl.enable(gl.DEPTH_TEST);
  gl.enable(gl.CULL_FACE);
  gl.cullFace(gl.BACK);
  /* Across the lane and slightly towards the camera: it separates the front
     face from the leading edge, which is what gives a box its corner. */
  gl.uniform3f(uKey, 0.50, 0.66, 0.56);
  gl.uniform3f(uFill, -0.55, 0.30, 0.78);

  let palette: Palette = readPalette(options.palette);
  let width = 1;
  let height = 1;

  /** The frame's world span, and the camera distance that produces it. */
  let spanX = WIDE_SPAN;
  let spanY = WIDE_SPAN / 1.78;
  let distance = 25;

  const projection = perspective(FOV, 1.78, 0.1, 190);
  const view = new Float32Array(16);
  const viewProjection = new Float32Array(16);

  const sparks: Spark[] = [];
  let shake = 0;
  let lastCoins = 0;
  let crashed = false;

  const push = (
    batch: Batch,
    x: number,
    y: number,
    z: number,
    sx: number,
    sy: number,
    sz: number,
    colour: Colour,
    spin = 0,
    glow = 0,
  ): void => {
    const capacity = batch.data.length / STRIDE;

    if (batch.count >= capacity) {
      return;
    }

    const at = batch.count * STRIDE;
    batch.data[at] = x;
    batch.data[at + 1] = y;
    batch.data[at + 2] = z;
    batch.data[at + 3] = sx;
    batch.data[at + 4] = sy;
    batch.data[at + 5] = sz;
    batch.data[at + 6] = colour[0];
    batch.data[at + 7] = colour[1];
    batch.data[at + 8] = colour[2];
    batch.data[at + 9] = spin;
    batch.data[at + 10] = glow;
    batch.count += 1;
  };

  /** A box, positioned by its centre. */
  const box = (
    x: number, y: number, z: number,
    sx: number, sy: number, sz: number,
    colour: Colour, spin = 0, glow = 0,
  ): void => push(boxes, x, y, z, sx, sy, sz, colour, spin, glow);

  /** A box, positioned by its left edge and its base — the way the world talks. */
  const slab = (
    left: number, base: number, z: number,
    w: number, h: number, depth: number,
    colour: Colour, glow = 0,
  ): void => box(left + w / 2, base + h / 2, z, w, h, depth, colour, 0, glow);

  const round = (
    x: number, y: number, z: number,
    sx: number, sy: number, sz: number,
    colour: Colour, spin = 0, glow = 0,
  ): void => push(discs, x, y, z, sx, sy, sz, colour, spin, glow);

  /**
   * A box that belongs to something spinning, offset from that thing's centre.
   *
   * The shader turns an instance about its own origin, so a mark carried on a
   * spinning coin has to have its *offset* turned too or it hangs in the air
   * beside the coin instead of on it. This is that rotation, and it is the only
   * reason the ₿ stays on the face where it was struck.
   */
  const spun = (
    cx: number, cy: number, cz: number,
    dx: number, dy: number, dz: number,
    sx: number, sy: number, sz: number,
    colour: Colour, spin: number, glow = 0,
  ): void => {
    const s = Math.sin(spin);
    const c = Math.cos(spin);

    box(cx + c * dx + s * dz, cy + dy, cz - s * dx + c * dz, sx, sy, sz, colour, spin, glow);
  };

  const resize = (nextWidth: number, nextHeight: number): void => {
    width = Math.max(1, nextWidth);
    height = Math.max(1, nextHeight);
    canvas.width = width;
    canvas.height = height;
    gl.viewport(0, 0, width, height);

    const aspect = width / height;

    /*
     * A wide stage is shown wide lane; a narrow one is shown less of it.
     *
     * Scaling the span with the aspect in the obvious way — a fixed vertical
     * field of view — walks a phone's runner down to a few pixels, because a
     * tall screen gets its extra room in the dimension the game does not use.
     * Interpolating the *horizontal* span instead spends a narrow screen's
     * shape on a larger runner and a shorter view, which is the trade a player
     * on a phone would make.
     */
    const t = Math.min(1, Math.max(0, (aspect - 0.75) / (1.7 - 0.75)));
    spanX = NARROW_SPAN + (WIDE_SPAN - NARROW_SPAN) * t;
    spanY = spanX / aspect;
    distance = spanY / 2 / Math.tan(FOV / 2);

    projection[0] = 1 / Math.tan(FOV / 2) / aspect;
    projection[5] = 1 / Math.tan(FOV / 2);
  };

  /**
   * The scenery, in three layers at three depths.
   *
   * There is no parallax factor anywhere in here, and that is the point: the
   * layers are simply *further away*, and a perspective camera moves a distant
   * thing across the frame more slowly than a near one all by itself. The
   * depths are chosen so the far skyline drifts at roughly a third of the
   * lane's pace, the chain at a half, and the near nodes at three quarters.
   *
   * The blockchain is scenery and stays scenery: low contrast, well above the
   * lane, and never shaped like anything the player has to act on.
   */
  const drawScenery = (world: World, frame: Frame): void => {
    const sky = palette.dark
      ? mix(palette.canvas, palette.accentSoft, 0.55)
      : mix(palette.canvas, palette.accent, 0.10);

    /* A backdrop and a haze band, which is the whole of the horizon. */
    box(spanX * 0.5, spanY * 0.5, -74, 260, 130, 0.4, sky);
    box(spanX * 0.5, 1.2, -70, 260, 9, 0.4, mix(sky, palette.accent, palette.dark ? 0.16 : 0.10));

    /* Far: a skyline of stacked blocks, barely apart from the sky. */
    const far = mix(sky, palette.dark ? palette.accent : palette.ink, palette.dark ? 0.17 : 0.07);

    for (let index = -1; index < 20; index += 1) {
      const period = 9;
      const at = Math.floor(world.distance / period) + index;
      const local = at * period - world.distance;
      const noise = hash(at);
      const tall = 5 + noise * 11;

      slab(local, 0, -46, 3.4 + noise * 1.6, tall, 3, far);
      slab(local + 0.6, tall, -46, 2.2, 0.5 + noise, 2.9, far);
    }

    /* Mid: the chain itself — blocks in a line, linked, one in four confirming. */
    const chainBody = mix(sky, palette.accent, palette.dark ? 0.34 : 0.20);
    const chainLink = mix(sky, palette.accent, palette.dark ? 0.22 : 0.13);

    for (let index = -1; index < 14; index += 1) {
      const period = 6.5;
      const at = Math.floor(world.distance / period) + index;
      const local = at * period - world.distance;
      const y = 6.4 + hash(at * 3) * 2.6;
      const size = 1.5 + hash(at * 7) * 0.5;

      /*
       * A confirmation: a block lighting up behind the runner as the lane goes
       * past it. It is driven by the world's own distance, so a paused game
       * shows a still chain and two runs of the same seed confirm the same
       * blocks — decoration that is nonetheless deterministic.
       */
      const phase = (world.distance * 0.05 + hash(at * 11) * 6.28) % 6.28;
      const confirming = !options.reducedMotion && at % 4 === 0 && phase < 1.4;
      const glow = confirming ? 0.55 - phase * 0.3 : 0.04;

      box(local, y, -26, size, size, size, confirming ? mix(chainBody, palette.accent, 0.6) : chainBody, 0, Math.max(0.04, glow));
      box(local + period / 2, y, -26, period - size, 0.12, 0.12, chainLink, 0, 0.05);
    }

    /*
     * Node masts, set behind the chain rather than in front of it.
     *
     * They stood at z = -11 in a draft, which put a lit vertical bar running
     * from the skyline down to the ground line — the exact place, and the exact
     * shape, of something the player has to jump. Scenery that can be mistaken
     * for an obstacle is worse than no scenery, so the masts moved back behind
     * the chain and stop well clear of the lane.
     */
    const pylon = mix(sky, palette.accent, palette.dark ? 0.24 : 0.16);

    for (let index = -1; index < 8; index += 1) {
      const period = 15;
      const at = Math.floor(world.distance / period) + index;
      const local = at * period - world.distance;

      slab(local, 6.6, -34, 0.28, 2.6, 0.28, pylon);
      slab(local - 0.5, 9.2, -34, 1.4, 0.24, 0.6, pylon);
      round(local, 9.6, -33.6, 0.46, 0.46, 0.24, mix(pylon, palette.accent, 0.7), 0, 0.5);
    }

    /*
     * There is no foreground layer, and that is a decision rather than an
     * omission.
     *
     * A draft put dark posts in front of the lane at z ≈ +5. At that distance
     * the perspective magnifies them enormously — a post a metre wide fills a
     * third of the frame — so what was meant as a hint of depth arrived as a
     * black slab sitting on top of the game. Foreground depth is bought below,
     * on the face of the ground itself, where it cannot cover anything.
     */

    /* One drifting spark of data, purely for life. */
    if (!options.reducedMotion) {
      for (let index = 0; index < 5; index += 1) {
        const offset = (frame.time * 2.2 + index * 4.7) % 22;

        box(spanX - offset, 3.2 + hash(index * 13) * 4, -16, 0.5, 0.06, 0.06, mix(sky, palette.accent, 0.55), 0, 0.4);
      }
    }
  };

  /**
   * The ground: a lit top surface, a bright leading edge, and a face below it.
   *
   * The face is the bottom quarter of the frame and it is drawn as what it is
   * — a cross-section of the ground the runner is on — rather than left as a
   * dark void. It carries the striations that used to be a foreground layer:
   * they travel with the lane, at the fastest rate anything in the picture
   * moves, and they cannot cover a single thing the player has to read.
   */
  const drawGround = (world: World): void => {
    /*
     * Light is not dark inverted. Dark gets the page's deep canvas pushed
     * towards the accent, so the lane glows; light gets a genuine mineral
     * grey, several steps down from the sky, because a near-white lane under a
     * near-white sky is a lane whose edge nobody can find.
     */
    const surface = palette.dark
      ? mix(palette.canvas, palette.accentSoft, 0.8)
      : mix(palette.canvas, palette.ink, 0.26);
    const face = palette.dark
      ? mix(palette.canvas, palette.accentSoft, 0.4)
      : mix(surface, palette.ink, 0.16);
    const edge = mix(surface, palette.accent, palette.dark ? 0.5 : 0.3);

    box(spanX * 0.4, -1.6, 0, 220, 3.2, 5.6, surface);
    /* The face, a hair in front of the body, so it reads as its own plane. */
    box(spanX * 0.4, -1.75, 2.82, 220, 3.5, 0.06, face);
    /* The line that says "this is the floor". It is the single most important
       mark in the picture: everything else is read relative to it. */
    box(spanX * 0.4, -0.02, 2.86, 220, 0.055, 0.06, edge, 0, palette.dark ? 0.35 : 0);
    box(spanX * 0.4, -0.05, -2.8, 220, 0.1, 0.1, mix(surface, palette.ink, palette.dark ? 0.22 : 0.14));

    const dash = mix(surface, palette.accent, palette.dark ? 0.3 : 0.26);
    const striation = palette.dark
      ? mix(face, palette.accent, 0.16)
      : mix(face, palette.ink, 0.12);
    const first = Math.ceil((world.distance - 6) / 2.6) * 2.6;

    for (let x = first; x < world.distance + spanX + 4; x += 2.6) {
      const local = x - world.distance;

      box(local, -0.02, 1.5, 1.2, 0.05, 0.14, dash);
      box(local + 1.3, -1.9, 2.88, 0.18, 2.6, 0.05, striation);
    }
  };

  /**
   * A contact shadow.
   *
   * A flat disc on the ground, shrinking and fading as whatever casts it rises.
   * It costs one instance and it is the difference between a runner standing on
   * the lane and a runner floating in front of it — which, in a jumping game,
   * is information rather than decoration: it is how you read where you will
   * land.
   */
  const shadow = (x: number, y: number, scale: number): void => {
    const lift = Math.min(1, y / 3.4);
    const size = scale * (1 - lift * 0.45);
    const dark = mix(palette.canvas, [0, 0, 0], palette.dark ? 0.55 : 0.22);

    round(x, 0.02, 0.4, size, size * 0.34, 0.02, mix(dark, palette.canvas, lift * 0.55));
  };

  /**
   * The runner: an icon, in about a dozen solids.
   *
   * The stride is derived from the world's own distance rather than from
   * wall-clock time, so two runs of the same seed animate identically — which
   * matters for a screenshot far more than it matters for play — and a game
   * that is not advancing shows a runner who is not running.
   */
  const drawRunner = (world: World): void => {
    const y = world.y;

    /*
     * The hoodie is the accent pulled well away from the page's own surfaces
     * in both themes, because the runner has to hold against a dark lane and a
     * bright one with the same silhouette. Light gets a deepened accent — a
     * dark figure on a bright ground, which is the Dino read; dark gets a
     * lifted one.
     */
    const hoodie = palette.dark
      ? mix(palette.accent, [1, 1, 1], 0.10)
      : mix(palette.accent, [0.05, 0.04, 0.14], 0.42);
    const trouser = mix(hoodie, palette.dark ? palette.canvas : [0, 0, 0], 0.35);
    const skin = palette.dark ? mix(palette.ink, palette.accent, 0.18) : mix(palette.ink, palette.accent, 0.30);
    const orange: Colour = [0.97, 0.58, 0.10];

    shadow(0, y, world.ducking ? 1.5 : 1.15);

    if (world.ducking) {
      const tall = DUCKING_HEIGHT;

      /* Sliding: the body goes flat and forward, the head leads, the legs
         trail. The silhouette has to change shape and not merely height, or
         nobody can tell a duck from a short runner. */
      const scuff = world.grounded ? Math.sin(world.distance * 3.4) * 0.06 : 0;

      slab(-0.5, 0.12, 0, 1.0, tall * 0.5, 0.54, hoodie);
      slab(-0.72, 0.05, 0, 0.5, 0.26, 0.46, trouser);
      slab(-0.86 + scuff, 0.02, 0, 0.34, 0.14, 0.4, trouser);
      round(0.44, tall * 0.52, 0, 0.46, 0.46, 0.4, skin);
      /* The hood, pulled up and back off the face. */
      slab(0.06, tall * 0.56, 0, 0.62, 0.3, 0.5, hoodie);
      box(0.28, tall * 0.42, 0.28, 0.2, 0.2, 0.08, orange, 0, 0.6);

      return;
    }

    const swing = world.grounded ? Math.sin(world.distance * 2.9) : 0.55;
    const lift = world.grounded ? Math.abs(Math.cos(world.distance * 2.9)) * 0.07 : 0.14;

    /* Legs, front and back, with feet. In the air they tuck instead. */
    slab(-0.14 + swing * 0.24, y + (world.grounded ? 0 : 0.14), 0.16, 0.24, 0.66, 0.22, trouser);
    slab(-0.14 - swing * 0.24, y + (world.grounded ? 0 : 0.20), -0.16, 0.24, 0.66, 0.22, trouser);
    slab(-0.2 + swing * 0.24, y + (world.grounded ? 0 : 0.14), 0.16, 0.42, 0.13, 0.24, mix(trouser, [0, 0, 0], 0.25));
    slab(-0.2 - swing * 0.24, y + (world.grounded ? 0 : 0.20), -0.16, 0.42, 0.13, 0.24, mix(trouser, [0, 0, 0], 0.25));

    /* Torso, a touch narrower at the shoulders than at the hem. */
    slab(-0.28, y + 0.62, 0, 0.56, 0.5, 0.44, hoodie);
    slab(-0.26, y + 1.08, 0, 0.52, 0.26, 0.42, mix(hoodie, palette.dark ? [1, 1, 1] : [0, 0, 0], 0.12));

    /* The ₿, worn rather than drawn: struck on the chest, facing the camera. */
    box(0.02, y + 0.9, 0.24, 0.19, 0.3, 0.08, orange, 0, 0.7);
    box(0.02, y + 1.0, 0.27, 0.1, 0.05, 0.06, mix(orange, [0, 0, 0], 0.55));
    box(0.02, y + 0.82, 0.27, 0.1, 0.05, 0.06, mix(orange, [0, 0, 0], 0.55));

    /* Arms, opposed to the legs. The near one carries the read. */
    slab(-0.1 - swing * 0.26, y + 0.78 + lift, 0.3, 0.2, 0.46, 0.2, hoodie);
    slab(-0.1 + swing * 0.26, y + 0.78 + lift, -0.3, 0.2, 0.46, 0.2, mix(hoodie, [0, 0, 0], 0.18));

    /* Head and hood. The disc is what makes him a person rather than a post. */
    round(0.02, y + STANDING_HEIGHT - 0.22, 0.06, 0.44, 0.44, 0.4, skin);
    slab(-0.28, y + STANDING_HEIGHT - 0.34, 0, 0.5, 0.34, 0.48, hoodie);
    slab(-0.3, y + STANDING_HEIGHT - 0.1, 0, 0.62, 0.1, 0.52, hoodie);
    /* A brim over the eyes, forward, which is what fixes the facing. */
    slab(0.06, y + STANDING_HEIGHT - 0.16, 0, 0.34, 0.08, 0.44, mix(hoodie, [0, 0, 0], 0.3));
  };

  /**
   * One obstacle.
   *
   * Each kind is a silhouette first and a reference second. A player who knows
   * nothing about banks, candles or central banks still has to see, in one
   * glance and at speed, whether a thing is on the floor or in the air — so
   * everything that sits on the ground is bottom-heavy and solid, and
   * everything that hangs is a horizontal bar with visible suspension.
   */
  const drawObstacle = (world: World, obstacle: Obstacle, frame: Frame): void => {
    const local = obstacle.x - world.distance;
    const w = obstacle.width;
    const h = obstacle.height;
    const fatal = world.fatal === obstacle;

    /* Ground things read dark on a light lane and light on a dark one, which
       is the only way one geometry serves two themes. */
    const solidTone = fatal
      ? palette.danger
      : palette.dark
        ? mix(palette.ink, palette.canvas, 0.42)
        : mix(palette.ink, [0.02, 0.02, 0.06], 0.72);

    if (obstacle.kind === 'bank') {
      shadow(local + w / 2, 0, w * 1.5);

      const stone = solidTone;
      const shade = mix(stone, palette.canvas, 0.28);

      /* Plinth, body, colonnade, entablature, and a stepped pediment. The
         steps are what make a squat block read as an institution. */
      slab(local - 0.08, 0, 0, w + 0.16, 0.1, 1.5, mix(stone, palette.canvas, 0.15));
      slab(local + w * 0.07, 0.1, 0, w * 0.86, 0.82, 1.3, shade);

      for (let index = 0; index < 4; index += 1) {
        slab(local + 0.1 + index * (w - 0.28) / 3.4, 0.14, 0.68, 0.11, 0.74, 0.14, stone);
      }

      slab(local - 0.07, 0.92, 0, w + 0.14, 0.14, 1.6, stone);
      slab(local, 1.06, 0, w, 0.12, 1.45, stone);
      slab(local + w * 0.19, 1.18, 0, w * 0.62, 0.1, 1.3, stone);
      slab(local + w * 0.35, 1.28, 0, w * 0.3, 0.07, 1.15, mix(stone, palette.accent, 0.35));

      return;
    }

    if (obstacle.kind === 'candle') {
      shadow(local + w / 2, 0, w * 1.6);

      /* Red in both themes: it is the market's colour, not the skin's, and a
         candle painted in the page accent would say nothing at all. */
      const red: Colour = fatal ? [1, 0.36, 0.30] : [0.86, 0.21, 0.24];

      /* Body to 1.40, wick above it — both inside the hitbox, so the shape the
         player judges is exactly the shape that can catch them. */
      slab(local, 0, 0, w, h - 0.22, 0.64, red);
      slab(local + w / 2 - 0.045, h - 0.22, 0, 0.09, 0.22, 0.09, mix(red, palette.ink, 0.35));
      /* A lit front face, so it is a candle rather than a red brick. */
      slab(local + 0.07, 0.08, 0.33, w - 0.14, h - 0.42, 0.04, mix(red, [0, 0, 0], 0.28));

      return;
    }

    /* From here down, everything hangs: a bar across the lane with the gap
       beneath it, which is the shape that means duck. */
    const drift = options.reducedMotion ? 0 : Math.sin(frame.time * 1.4 + obstacle.variant * 6.28) * 0.07;
    const base = obstacle.base + drift;

    if (obstacle.kind === 'barrier') {
      const bar = fatal ? palette.danger : mix(palette.accent, palette.dark ? [1, 1, 1] : [0, 0, 0], 0.18);
      const hanger = mix(palette.canvas, palette.accent, palette.dark ? 0.3 : 0.35);

      /* Suspension first, so the eye follows it down to the bar. */
      slab(local + 0.12, base + h, 0, 0.1, 2.6, 0.1, hanger);
      slab(local + w - 0.22, base + h, 0, 0.1, 2.6, 0.1, hanger);
      slab(local, base, 0, w, h, 1.0, bar, palette.dark ? 0.16 : 0.02);

      /* Hazard chevrons on the face. Four bars is a barrier; a plain slab is
         a shelf, and a shelf does not say "get under me". */
      for (let index = 0; index < 4; index += 1) {
        slab(local + 0.14 + index * (w - 0.34) / 4, base + 0.16, 0.53, 0.16, h - 0.32, 0.04, mix(bar, [0.02, 0.02, 0.05], 0.62));
      }

      return;
    }

    /* FUD: a hovering hazard sign. It is the loudest thing in the lane on
       purpose — a warning is supposed to be read before it is understood. */
    const panel = fatal ? palette.danger : palette.dark
      ? mix(palette.ink, palette.accentSoft, 0.35)
      : mix(palette.ink, [0.02, 0.02, 0.06], 0.62);
    const mark: Colour = fatal ? [1, 0.5, 0.45] : [0.98, 0.72, 0.16];

    slab(local, base, 0, w, h, 0.7, panel);
    slab(local + 0.04, base + 0.06, 0.38, w - 0.08, h - 0.12, 0.04, mix(panel, palette.canvas, 0.18));

    /* The exclamation, twice, so it reads at either end of a wide sign. */
    for (const at of [w * 0.27, w * 0.73]) {
      slab(local + at - 0.05, base + h * 0.34, 0.42, 0.1, h * 0.4, 0.05, mark, 0.35);
      slab(local + at - 0.05, base + h * 0.14, 0.42, 0.1, 0.1, 0.05, mark, 0.35);
    }

    /* Small lamps at the ends, which is what makes it hover rather than float. */
    round(local + 0.06, base + h / 2, 0.38, 0.22, 0.22, 0.1, mark, 0, 0.6);
    round(local + w - 0.06, base + h / 2, 0.38, 0.22, 0.22, 0.1, mark, 0, 0.6);
  };

  /**
   * A Bitcoin.
   *
   * A struck disc: a rim, a face, and the ₿ raised on it, turning slowly about
   * its own axis so the coin has thickness. It is the one object in the game
   * whose colour is not the skin's — Bitcoin's orange is the coin's identity,
   * and a coin tinted with the page accent stops reading as a coin at all.
   */
  const drawCoin = (x: number, y: number, spin: number): void => {
    const gold: Colour = [0.98, 0.66, 0.16];
    const rim = mix(gold, [0.42, 0.18, 0.02], 0.45);
    const face = mix(gold, [1, 1, 1], 0.14);

    round(x, y, 0, 0.78, 0.78, 0.16, rim, spin, 0.28);
    round(x, y, 0, 0.6, 0.6, 0.2, face, spin, 0.42);

    /*
     * The mark: the upright of a ₿ with its two bowls, and the two strokes
     * that cross it top and bottom. At a coin's size on screen this is not a
     * glyph so much as the *idea* of one — which is all it has to be, and why
     * it is built from six thick bars rather than drawn from a font that would
     * disappear at twenty pixels.
     */
    const ink = mix(gold, [0.3, 0.1, 0.0], 0.8);

    /* Upright. */
    spun(x, y, 0, -0.06, 0, 0.115, 0.09, 0.34, 0.05, ink, spin);
    /* Top and bottom strokes of the two bowls. */
    spun(x, y, 0, 0.02, 0.11, 0.115, 0.2, 0.08, 0.05, ink, spin);
    spun(x, y, 0, 0.02, -0.11, 0.115, 0.2, 0.08, 0.05, ink, spin);
    /* The waist. */
    spun(x, y, 0, 0.0, 0, 0.115, 0.16, 0.07, 0.05, ink, spin);
    /* The bowls' outer edge. */
    spun(x, y, 0, 0.1, 0.11, 0.115, 0.07, 0.09, 0.05, ink, spin);
    spun(x, y, 0, 0.1, -0.11, 0.115, 0.07, 0.09, 0.05, ink, spin);
    /* The two strokes through the upright, above and below. */
    spun(x, y, 0, -0.06, 0.21, 0.115, 0.08, 0.09, 0.05, ink, spin);
    spun(x, y, 0, -0.06, -0.21, 0.115, 0.08, 0.09, 0.05, ink, spin);
  };

  const drawWorld = (world: World, frame: Frame): void => {
    boxes.count = 0;
    discs.count = 0;

    drawScenery(world, frame);
    drawGround(world);

    for (const obstacle of world.obstacles) {
      const local = obstacle.x - world.distance;

      if (local < -4 || local > spanX + 6) {
        continue;
      }

      drawObstacle(world, obstacle, frame);
    }

    for (const coin of world.pickups) {
      if (coin.collected) {
        continue;
      }

      const local = coin.x - world.distance;

      if (local < -3 || local > spanX + 6) {
        continue;
      }

      /* Coins spin on distance, not on time, so a paused game is still. */
      const spin = options.reducedMotion ? 0.35 : world.distance * 1.15 + coin.x * 0.8;

      shadow(local, coin.y, 0.5);
      drawCoin(local, coin.y, spin);
    }

    drawRunner(world);

    for (const spark of sparks) {
      const fade = Math.max(0, spark.life);

      box(
        spark.x - world.distance, spark.y, spark.z,
        0.15 * fade, 0.15 * fade, 0.15 * fade,
        spark.colour, spark.life * 6, 0.85,
      );
    }
  };

  const advanceSparks = (world: World, frame: Frame): void => {
    if (options.reducedMotion) {
      sparks.length = 0;

      return;
    }

    if (world.coins > lastCoins) {
      for (let index = 0; index < 10; index += 1) {
        sparks.push({
          x: world.distance,
          y: world.y + 0.9,
          z: 0,
          vx: (Math.random() - 0.3) * 4.5,
          vy: Math.random() * 5,
          vz: (Math.random() - 0.5) * 2.5,
          life: 0.5,
          colour: [0.98, 0.7, 0.2],
        });
      }
    }

    if (world.status === 'over' && !crashed) {
      crashed = true;
      shake = 1;

      for (let index = 0; index < 24; index += 1) {
        sparks.push({
          x: world.distance,
          y: world.y + 0.8,
          z: 0,
          vx: (Math.random() - 0.5) * 6,
          vy: Math.random() * 6.5,
          vz: (Math.random() - 0.5) * 3,
          life: 0.9,
          colour: palette.danger,
        });
      }
    }

    if (world.status === 'running') {
      crashed = false;
    }

    lastCoins = world.coins;
    shake = Math.max(0, shake - frame.delta * 2.6);

    for (let index = sparks.length - 1; index >= 0; index -= 1) {
      const spark = sparks[index];
      spark.life -= frame.delta * 1.6;

      if (spark.life <= 0) {
        sparks.splice(index, 1);
        continue;
      }

      spark.x += spark.vx * frame.delta;
      spark.y += spark.vy * frame.delta;
      spark.z += spark.vz * frame.delta;
      spark.vy -= 14 * frame.delta;
    }
  };

  /*
   * The context can be taken away, and the taking is not an error.
   *
   * `webglcontextlost` is how a browser says the GPU objects above are gone: a
   * driver reset, a compositor reclaiming memory, a laptop switching cards.
   * Every call after it is a silent no-op, so a loop that keeps drawing paints
   * nothing while the simulation runs on behind a frozen picture — the failure
   * mode a player would describe as "it hung".
   *
   * The default action is *not* prevented. Preventing it is how a renderer
   * asks to be given a context back later, and this one does not want one: the
   * run needs a lane it can draw on now, and `run.ts` has a second lane that
   * needs no GPU at all. Reporting it once and going quiet is the whole
   * contribution; `lost` makes it once no matter how often the event repeats.
   */
  let lost = false;

  const onContextLost = (): void => {
    if (lost) {
      return;
    }

    lost = true;
    options.onContextLost?.();
  };

  canvas.addEventListener('webglcontextlost', onContextLost);

  return {
    kind: 'webgl2',
    resize,

    retheme(): void {
      palette = readPalette(options.palette);
    },

    draw(world: World, frame: Frame): void {
      advanceSparks(world, frame);

      /*
       * The camera stands still.
       *
       * That is the single most important decision in this file: a side-on
       * runner is read from a fixed place, and a camera that drifts, orbits or
       * chases turns a game of timing into a game of tracking. All it is
       * allowed is a fraction of the runner's own rise — so a high jump is
       * never lost off the top — and a short jolt on impact. Under reduced
       * motion both collapse to zero and the frame is nailed down.
       */
      const lift = options.reducedMotion ? 0 : Math.min(world.y * 0.16, 0.6);
      const jolt = options.reducedMotion ? 0 : shake * 0.3;

      const centreX = spanX * (0.5 - RUNNER_COLUMN);
      const centreY = spanY * (GROUND_LINE - 0.5) + lift;

      const eye: Vec3 = [
        centreX + Math.sin(frame.time * 41) * jolt,
        centreY + Math.cos(frame.time * 53) * jolt,
        distance,
      ];
      const target: Vec3 = [eye[0], eye[1], 0];

      multiply(projection, lookAt(eye, target, view), viewProjection);
      gl.uniformMatrix4fv(uViewProjection, false, viewProjection);
      gl.uniform3f(uCamera, eye[0], eye[1], eye[2]);

      /*
       * Dark and light are two designs, not one inverted.
       *
       * Dark is nocturnal: the page's own deep canvas pushed towards the
       * accent, so the lane glows against it. Light is mineral: a bright,
       * faintly cool ground that the dark obstacles and the gold coins are
       * drawn *on* rather than lit against. Neither is derived from the other.
       */
      const background = palette.dark
        ? mix(palette.canvas, palette.accentSoft, 0.42)
        : mix(palette.canvas, palette.accent, 0.07);

      gl.uniform3f(uFog, background[0], background[1], background[2]);
      gl.uniform2f(uFogRange, distance * 0.9, distance * 3.4);
      gl.clearColor(background[0], background[1], background[2], 1);
      gl.clear(gl.COLOR_BUFFER_BIT | gl.DEPTH_BUFFER_BIT);

      drawWorld(world, frame);

      for (const batch of [boxes, discs]) {
        if (batch.count === 0) {
          continue;
        }

        gl.bindVertexArray(batch.vao);
        gl.bindBuffer(gl.ARRAY_BUFFER, batch.buffer);
        gl.bufferSubData(gl.ARRAY_BUFFER, 0, batch.data, 0, batch.count * STRIDE);
        gl.drawArraysInstanced(gl.TRIANGLES, batch.first, batch.vertices, batch.count);
      }

      gl.bindVertexArray(null);
    },

    destroy(): void {
      /* The listener goes first, so a teardown can never be reported as a
         loss — and so the canvas holds no reference to this closure once the
         renderer it belonged to is gone. */
      canvas.removeEventListener('webglcontextlost', onContextLost);

      /* Deleted by name rather than by losing the context: a context-loss
         event is console noise attributed to a teardown that succeeded. */
      gl.deleteProgram(program);
      gl.deleteShader(vertex);
      gl.deleteShader(fragment);
      gl.deleteBuffer(geometry);

      for (const batch of [boxes, discs]) {
        gl.deleteBuffer(batch.buffer);
        gl.deleteVertexArray(batch.vao);
      }

      sparks.length = 0;
    },
  };
}

export { mountWebglRenderer };
