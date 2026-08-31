/**
 * Satoshi Run — the simulation.
 *
 * This module is the whole game and knows nothing about how it is shown. It
 * has no DOM, no canvas, no timers and no randomness of its own: a world is
 * created from a seed, advanced by a fixed step, and every obstacle, coin,
 * collision and point it produces follows from those two facts alone. Run the
 * same seed through the same intents twice and you get the same world, which
 * is what makes the rules testable without a browser painting anything.
 *
 * The units are the game's, not the screen's. One unit is roughly the width of
 * the runner's shoulders; the ground is y = 0 and up is positive. A renderer
 * decides what a unit looks like — see `renderer.ts` for that boundary.
 *
 * The historic concept is preserved deliberately: Satoshi runs on his own,
 * jumps and ducks, the obstacles are the ones that ever threatened Bitcoin —
 * banks, FUD, red candles, the central-bank barriers — and the coins are BTC.
 * Distance and collection both score, so running far and collecting well are
 * two ways to be good at it rather than one.
 */

/** The fixed simulation step, in seconds. The renderer never changes it. */
const TICK = 1 / 120;

/** Everything the player can ask for on a given tick. */
interface Intent {
  /** Held, not tapped: the rising edge is what starts a jump. */
  readonly jump: boolean;
  readonly duck: boolean;
}

type ObstacleKind = 'bank' | 'candle' | 'barrier' | 'fud';

type RunStatus = 'running' | 'over';

interface Obstacle {
  readonly kind: ObstacleKind;
  /** Absolute world position of the box's left edge. */
  readonly x: number;
  readonly width: number;
  /** Bottom edge. Ground obstacles sit at 0; barriers hang above the lane. */
  readonly base: number;
  readonly height: number;
  /** Stable per-obstacle noise a renderer may use for variety. */
  readonly variant: number;
}

interface Coin {
  readonly x: number;
  readonly y: number;
  collected: boolean;
  /** Absolute distance at which it was collected, for the collect flourish. */
  collectedAt: number;
}

interface World {
  status: RunStatus;
  /** Absolute distance travelled, in units. Also the player's world x. */
  distance: number;
  /** Seconds of simulation elapsed. Deterministic: ticks × TICK. */
  elapsed: number;
  speed: number;
  /** Player's feet. 0 is standing on the ground. */
  y: number;
  velocity: number;
  ducking: boolean;
  grounded: boolean;
  coins: number;
  score: number;
  obstacles: Obstacle[];
  pickups: Coin[];
  /** The obstacle that ended the run, for the renderer's reaction. */
  fatal: Obstacle | null;
  /** Set for exactly one tick after a coin is taken. */
  collectedThisTick: number;
  seed: number;
  rng: number;
  nextObstacleAt: number;
  nextCoinAt: number;
  jumpHeld: boolean;
}

interface WorldOptions {
  readonly seed?: number;
}

const START_SPEED = 11.5;
const MAX_SPEED = 27;
/** Units per second, per second. Gentle enough to stay readable for minutes. */
const ACCELERATION = 0.34;

const GRAVITY = -62;
const JUMP_VELOCITY = 18.2;
/** Ducking in the air is a fast-fall, which is the only air control there is. */
const FAST_FALL = -46;
/**
 * The rising speed a jump is capped at once the button is released.
 *
 * Holding buys height and airtime; letting go gives a shorter hop. What it
 * must never buy is failure: this floor is chosen so that the shortest
 * possible tap still clears the tallest thing on the ground — a red candle at
 * 1.62 units, against a capped peak of 15²/(2×62) ≈ 1.81. A player who taps is
 * playing a tighter game than one who holds, not a broken one.
 */
const JUMP_RELEASE_VELOCITY = 15;

const PLAYER_HALF_WIDTH = 0.34;
const STANDING_HEIGHT = 1.72;
const DUCKING_HEIGHT = 0.92;

/** How far ahead of the player things are created, well beyond any camera. */
const SPAWN_AHEAD = 42;
/** How far behind the player they are forgotten. */
const FORGET_BEHIND = 8;

const COIN_RADIUS = 0.42;
const COIN_POINTS = 25;
const DISTANCE_POINTS = 1.6;

/**
 * One obstacle's geometry, by kind.
 *
 * `base > 0` is the whole of what makes an obstacle a ducking problem rather
 * than a jumping one: it hangs in the lane at head height and the gap beneath
 * it is exactly a ducked runner tall.
 */
const SHAPES: Record<ObstacleKind, { width: number; height: number; base: number }> = {
  /* A bank tower. Squat, wide, unmistakably a wall. */
  bank: { width: 1.15, height: 1.35, base: 0 },
  /* A red candle, printed downwards. Narrow and tall. */
  candle: { width: 0.62, height: 1.62, base: 0 },
  /* A central-bank barrier, hung across the lane. Duck. */
  barrier: { width: 1.5, height: 1.15, base: 1.05 },
  /* A bank of FUD, drifting at head height. Duck. */
  fud: { width: 2.1, height: 0.8, base: 1.12 },
};

/**
 * mulberry32 — small, fast, and identical everywhere.
 *
 * The state lives on the world rather than in a closure so a world is plain
 * data: it can be inspected, compared, and asserted against without asking a
 * function what it is about to do next.
 */
function nextRandom(world: World): number {
  world.rng = (world.rng + 0x6d2b79f5) >>> 0;
  let t = world.rng;
  t = Math.imul(t ^ (t >>> 15), t | 1);
  t ^= t + Math.imul(t ^ (t >>> 7), t | 61);

  return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
}

function between(world: World, low: number, high: number): number {
  return low + nextRandom(world) * (high - low);
}

function playerHeight(world: World): number {
  return world.ducking ? DUCKING_HEIGHT : STANDING_HEIGHT;
}

function createWorld(options: WorldOptions = {}): World {
  const seed = options.seed ?? 1;

  const world: World = {
    status: 'running',
    distance: 0,
    elapsed: 0,
    speed: START_SPEED,
    y: 0,
    velocity: 0,
    ducking: false,
    grounded: true,
    coins: 0,
    score: 0,
    obstacles: [],
    pickups: [],
    fatal: null,
    collectedThisTick: 0,
    seed,
    rng: seed >>> 0,
    /*
     * Both cursors start at zero, so the opening obstacle and the opening coin
     * are created on the very first tick — forty-two units ahead, which is
     * where everything is created. The run therefore always opens with the
     * same stretch of clear lane, about three and a half seconds of it,
     * whatever the seed: long enough to see what the lane is before it asks
     * anything, short enough that it asks soon.
     */
    nextObstacleAt: 0,
    nextCoinAt: 4,
    jumpHeld: false,
  };

  return world;
}

/**
 * The gap to the next obstacle.
 *
 * It is expressed in *seconds of reaction time* and converted to distance at
 * the current speed, which is the only way a run can keep getting faster
 * without eventually becoming unfair: at 27 units/s the same 0.85 s is 23
 * units of lane, and at 11.5 it is 10.
 */
function obstacleGap(world: World): number {
  const reaction = between(world, 0.85, 1.85);

  return world.speed * reaction;
}

function pickKind(world: World): ObstacleKind {
  const roll = nextRandom(world);

  /*
   * Ducking obstacles are the minority on purpose. Jumping is the verb the
   * game teaches first, and a lane that asked for both equally would read as
   * random rather than as rhythm.
   */
  if (roll < 0.34) {
    return 'bank';
  }

  if (roll < 0.68) {
    return 'candle';
  }

  return roll < 0.85 ? 'barrier' : 'fud';
}

function spawnObstacle(world: World): void {
  const kind = pickKind(world);
  const shape = SHAPES[kind];

  world.obstacles.push({
    kind,
    x: world.distance + SPAWN_AHEAD,
    width: shape.width,
    base: shape.base,
    height: shape.height,
    variant: nextRandom(world),
  });

  world.nextObstacleAt = world.distance + obstacleGap(world);
}

/**
 * A short arc of coins.
 *
 * Ground runs are free money; the arcs are not — they sit at jump height, so
 * collecting a whole arc means committing to a jump that has to land clear of
 * whatever the lane does next. That tension is the entire reason distance and
 * collection are scored separately.
 */
function spawnCoins(world: World): void {
  const count = 3 + Math.floor(nextRandom(world) * 3);
  const arc = nextRandom(world) < 0.55;
  const start = world.distance + SPAWN_AHEAD;
  const spacing = 1.15;

  for (let index = 0; index < count; index += 1) {
    const progress = count === 1 ? 0.5 : index / (count - 1);
    const height = arc
      ? 1.05 + Math.sin(progress * Math.PI) * 1.5
      : 0.75;

    world.pickups.push({
      x: start + index * spacing,
      y: height,
      collected: false,
      collectedAt: 0,
    });
  }

  world.nextCoinAt = world.distance + world.speed * between(world, 1.6, 3.4);
}

function overlaps(world: World, obstacle: Obstacle): boolean {
  const left = world.distance - PLAYER_HALF_WIDTH;
  const right = world.distance + PLAYER_HALF_WIDTH;

  if (right <= obstacle.x || left >= obstacle.x + obstacle.width) {
    return false;
  }

  const top = world.y + playerHeight(world);

  return top > obstacle.base && world.y < obstacle.base + obstacle.height;
}

function scoreOf(world: World): number {
  return Math.floor(world.distance * DISTANCE_POINTS) + world.coins * COIN_POINTS;
}

/**
 * Advances the world by exactly one tick.
 *
 * Order matters and is fixed: intent, then motion, then the lane, then
 * collection, then collision, then the score. A coin touched on the same tick
 * as an obstacle is collected — the run ends either way, and refusing the
 * player the coin they visibly touched would be a rule nobody could see.
 */
function stepWorld(world: World, intent: Intent): World {
  world.collectedThisTick = 0;

  if (world.status === 'over') {
    return world;
  }

  const pressed = intent.jump && !world.jumpHeld;
  world.jumpHeld = intent.jump;
  world.ducking = intent.duck;

  if (pressed && world.grounded) {
    world.velocity = JUMP_VELOCITY;
    world.grounded = false;
  }

  if (!intent.jump && world.velocity > JUMP_RELEASE_VELOCITY) {
    world.velocity = JUMP_RELEASE_VELOCITY;
  }

  if (!world.grounded) {
    world.velocity += (GRAVITY + (intent.duck ? FAST_FALL : 0)) * TICK;
    world.y += world.velocity * TICK;

    if (world.y <= 0) {
      world.y = 0;
      world.velocity = 0;
      world.grounded = true;
    }
  }

  world.speed = Math.min(MAX_SPEED, world.speed + ACCELERATION * TICK);
  world.distance += world.speed * TICK;
  world.elapsed += TICK;

  if (world.distance >= world.nextObstacleAt) {
    spawnObstacle(world);
  }

  if (world.distance >= world.nextCoinAt) {
    spawnCoins(world);
  }

  const horizon = world.distance - FORGET_BEHIND;

  if (world.obstacles.length > 0 && world.obstacles[0].x + world.obstacles[0].width < horizon) {
    world.obstacles.shift();
  }

  while (world.pickups.length > 0 && world.pickups[0].x < horizon) {
    world.pickups.shift();
  }

  const top = world.y + playerHeight(world);

  for (const coin of world.pickups) {
    if (coin.collected || Math.abs(coin.x - world.distance) > COIN_RADIUS + PLAYER_HALF_WIDTH) {
      continue;
    }

    if (coin.y + COIN_RADIUS > world.y && coin.y - COIN_RADIUS < top) {
      coin.collected = true;
      coin.collectedAt = world.distance;
      world.coins += 1;
      world.collectedThisTick += 1;
    }
  }

  for (const obstacle of world.obstacles) {
    if (overlaps(world, obstacle)) {
      world.status = 'over';
      world.fatal = obstacle;
      break;
    }
  }

  world.score = scoreOf(world);

  return world;
}

/**
 * Runs a world from its seed through a scripted intent, and hands back the
 * result. It exists for the browser suite: a rule that can only be observed by
 * playing is a rule nobody can assert, and this is the seam that makes the
 * physics, the spawning, the collisions and the collection checkable without
 * a single frame being drawn.
 */
function simulate(seed: number, ticks: number, intentAt: (tick: number, world: World) => Intent): World {
  const world = createWorld({ seed });

  for (let tick = 0; tick < ticks; tick += 1) {
    stepWorld(world, intentAt(tick, world));
  }

  return world;
}

const IDLE: Intent = { jump: false, duck: false };

export {
  COIN_POINTS,
  DISTANCE_POINTS,
  DUCKING_HEIGHT,
  IDLE,
  MAX_SPEED,
  PLAYER_HALF_WIDTH,
  SHAPES,
  JUMP_RELEASE_VELOCITY,
  STANDING_HEIGHT,
  START_SPEED,
  TICK,
  createWorld,
  playerHeight,
  scoreOf,
  simulate,
  stepWorld,
};
export type { Coin, Intent, Obstacle, ObstacleKind, RunStatus, World, WorldOptions };
