/**
 * Satoshi Run — intent, gathered from whatever the player has.
 *
 * The simulation asks one question per tick — is jump held, is duck held — and
 * this is the only file that knows how a person answers it. Keys and touches
 * are two spellings of the same two verbs, and neither is the primary: the
 * pointer path is not a reduced version of the keyboard one, it is the same
 * one reached differently.
 *
 * Holds are tracked by *source* rather than as a boolean, so releasing the
 * Space bar cannot cancel a jump the player is still holding with a thumb, and
 * a pointer that leaves the page mid-press cannot leave a key stuck down.
 */
import type { Intent } from './world';

type Command = 'restart' | 'close';

interface ControlsOptions {
  /** Where key events are listened for. The overlay, never the document. */
  readonly surface: HTMLElement;
  /** The playfield. Its top half jumps and its bottom half ducks. */
  readonly stage: HTMLElement;
  /** Explicit, labelled controls. They hold: press and keep pressing. */
  readonly jump: HTMLElement;
  readonly duck: HTMLElement;
  readonly onCommand: (command: Command) => void;
}

interface Controls {
  intent(): Intent;
  /** Forgets every hold. Used on restart, so a run never starts mid-press. */
  clear(): void;
  destroy(): void;
}

/** Keys that would otherwise scroll the page out from under the game. */
const SWALLOWED = new Set(['Space', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight']);

const JUMP_KEYS = new Set(['Space', 'ArrowUp', 'KeyW']);
const DUCK_KEYS = new Set(['ArrowDown', 'KeyS']);

/**
 * Captures the pointer so a press that wanders off its target still ends where
 * it started. It is a convenience and never a requirement: a synthetic pointer
 * — which is what a test dispatches — has no capture to take, and refusing it
 * must not cost the press itself.
 */
function capture(element: HTMLElement, pointerId: number): void {
  try {
    element.setPointerCapture(pointerId);
  } catch {
    // Nothing to hold on to. The release path is set-based and copes.
  }
}

function createControls(options: ControlsOptions): Controls {
  const jumping = new Set<string>();
  const ducking = new Set<string>();

  /*
   * A press shorter than a frame is still a press.
   *
   * The loop samples intent once per animation frame; a tap that goes down and
   * up inside those sixteen milliseconds would otherwise be sampled as nothing
   * at all, and the player would swear the button was ignored — because it
   * was. Each verb therefore latches when it starts and stays latched until
   * the loop has read it exactly once, which is the whole of the input
   * buffering this game needs.
   */
  let tappedJump = false;
  let tappedDuck = false;

  const hold = (set: Set<string>, source: string): void => {
    set.add(source);

    if (set === jumping) {
      tappedJump = true;

      return;
    }

    tappedDuck = true;
  };

  const onKeyDown = (event: KeyboardEvent): void => {
    if (SWALLOWED.has(event.code)) {
      event.preventDefault();
    }

    if (event.repeat) {
      return;
    }

    if (JUMP_KEYS.has(event.code)) {
      hold(jumping, event.code);

      return;
    }

    if (DUCK_KEYS.has(event.code)) {
      hold(ducking, event.code);

      return;
    }

    if (event.code === 'Escape') {
      options.onCommand('close');

      return;
    }

    if (event.code === 'KeyR' || event.code === 'Enter') {
      options.onCommand('restart');
    }
  };

  const onKeyUp = (event: KeyboardEvent): void => {
    jumping.delete(event.code);
    ducking.delete(event.code);
  };

  /*
   * A window-level blur is the one signal that a key will never be released:
   * the tab lost focus mid-press and the keyup will be delivered somewhere
   * else. Everything is dropped rather than left held.
   */
  const onBlur = (): void => {
    jumping.clear();
    ducking.clear();
    tappedJump = false;
    tappedDuck = false;
  };

  const holds: Array<{ element: HTMLElement; set: Set<string>; id: string }> = [
    { element: options.jump, set: jumping, id: 'button-jump' },
    { element: options.duck, set: ducking, id: 'button-duck' },
  ];

  const onHoldStart = (event: PointerEvent, set: Set<string>, id: string): void => {
    /* The playfield and the buttons are both touch targets; without this a tap
       on a button would also count as a tap on the stage underneath it. */
    event.preventDefault();
    hold(set, `${id}:${event.pointerId}`);
    capture(event.currentTarget as HTMLElement, event.pointerId);
  };

  const onHoldEnd = (event: PointerEvent, set: Set<string>, id: string): void => {
    set.delete(`${id}:${event.pointerId}`);
  };

  const listeners: Array<() => void> = [];

  for (const hold of holds) {
    const start = (event: PointerEvent): void => onHoldStart(event, hold.set, hold.id);
    const end = (event: PointerEvent): void => onHoldEnd(event, hold.set, hold.id);

    hold.element.addEventListener('pointerdown', start);
    hold.element.addEventListener('pointerup', end);
    hold.element.addEventListener('pointercancel', end);
    hold.element.addEventListener('pointerleave', end);

    listeners.push((): void => {
      hold.element.removeEventListener('pointerdown', start);
      hold.element.removeEventListener('pointerup', end);
      hold.element.removeEventListener('pointercancel', end);
      hold.element.removeEventListener('pointerleave', end);
    });
  }

  /**
   * The stage itself is a control: the top two thirds jump, the bottom third
   * ducks. It is what a player's thumb finds first on a phone, and it holds
   * exactly like the buttons do.
   */
  const onStageDown = (event: PointerEvent): void => {
    const box = options.stage.getBoundingClientRect();
    const set = event.clientY - box.top > box.height * 0.66 ? ducking : jumping;

    event.preventDefault();
    hold(set, `stage:${event.pointerId}`);
    capture(options.stage, event.pointerId);
  };

  const onStageUp = (event: PointerEvent): void => {
    jumping.delete(`stage:${event.pointerId}`);
    ducking.delete(`stage:${event.pointerId}`);
  };

  options.stage.addEventListener('pointerdown', onStageDown);
  options.stage.addEventListener('pointerup', onStageUp);
  options.stage.addEventListener('pointercancel', onStageUp);

  options.surface.addEventListener('keydown', onKeyDown);
  options.surface.addEventListener('keyup', onKeyUp);
  window.addEventListener('blur', onBlur);

  return {
    intent(): Intent {
      const intent = {
        jump: jumping.size > 0 || tappedJump,
        duck: ducking.size > 0 || tappedDuck,
      };

      tappedJump = false;
      tappedDuck = false;

      return intent;
    },

    clear(): void {
      jumping.clear();
      ducking.clear();
      tappedJump = false;
      tappedDuck = false;
    },

    destroy(): void {
      options.stage.removeEventListener('pointerdown', onStageDown);
      options.stage.removeEventListener('pointerup', onStageUp);
      options.stage.removeEventListener('pointercancel', onStageUp);
      options.surface.removeEventListener('keydown', onKeyDown);
      options.surface.removeEventListener('keyup', onKeyUp);
      window.removeEventListener('blur', onBlur);

      for (const remove of listeners.splice(0)) {
        remove();
      }

      jumping.clear();
      ducking.clear();
    },
  };
}

export { DUCK_KEYS, JUMP_KEYS, createControls };
export type { Command, Controls, ControlsOptions };
