/**
 * The theme control, as a control rather than as a picture.
 *
 * PORT-138 replaced the pill that said "Dark theme" with a 52-pixel day/night
 * scene. Everything about the theme *contract* was deliberately left alone —
 * one localStorage key, `data-theme` on the root, `aria-pressed` on a real
 * button, the pre-paint bootstrap — and the point of this file is to hold that
 * claim to account: the same behaviour, asserted through the new presentation.
 *
 * So the assertions here are split in two. The ones that would have passed
 * before the redesign (name, state, persistence, keyboard) prove nothing was
 * lost. The ones that could not have (the word is out of view, the body is at
 * the day end or the night end, the craters appear) prove the new state is
 * readable without reading the CSS — including the one property the palette
 * must not be trusted with, which is telling you which theme you are in.
 *
 * Nothing below waits on an animation. Where a settled visual is needed the
 * assertion is made on the property CSS transitions *to*, through Playwright's
 * own retrying expectations, so a slow engine costs a retry rather than a
 * flake.
 */
import { expect, test } from './support/test';

/** The accessible name is stable across states: a toggle names its subject. */
const NAME = 'Thème sombre';

const MOBILE = { width: 390, height: 844 };

/** The smallest a touch target may be, in CSS pixels. */
const TOUCH_TARGET = 44;

type Page = Parameters<Parameters<typeof test>[1]>[0]['page'];

const control = (page: Page) => page.getByRole('button', { name: NAME });

/**
 * The clock every case runs on.
 *
 * PORT-138 made the local hour the default theme, which means a suite that did
 * not say what time it was would pass or fail depending on when it ran. So the
 * hour is stated, per case, before the page loads.
 *
 * It is stated by replacing `Date.prototype.getHours` and nothing else, which
 * is deliberate on two counts. It is the exact seam the rule reads — both the
 * inline bootstrap and the theme module ask the local clock for an hour and
 * for nothing else — so the stub is as narrow as the contract it exercises.
 * And it leaves the page's timers alone: Playwright's own clock control also
 * governs the scheduling a browser uses to run a view transition's update
 * callback, and freezing that would have made the suite report a broken theme
 * switch in WebKit that no visitor could ever see.
 */
const NOON = 12;
const NIGHT = 22;

const at = async (page: Page, hour: number): Promise<void> => {
  await page.addInitScript((value) => {
    Object.defineProperty(Date.prototype, 'getHours', {
      configurable: true,
      writable: true,
      value: () => value,
    });
  }, hour);
};

test.use({ timezoneId: 'UTC' });

test.describe('theme control', () => {
  test('it is a real button with a name and a state, and no visible word', async ({ page }) => {
    await page.goto('/fr');

    const toggle = control(page);
    await expect(toggle).toBeVisible();
    await expect(toggle).toHaveAttribute('type', 'button');
    await expect(toggle).toHaveAttribute('aria-pressed', /^(true|false)$/);

    // The name survives as text — nothing was moved into an `aria-label` or a
    // `title`, either of which would have been a weaker contract.
    await expect(toggle).not.toHaveAttribute('aria-label', /./);
    await expect(toggle).not.toHaveAttribute('title', /./);

    // ...and that text is out of view. Not `display: none`, which would have
    // taken the accessible name with it: clipped to nothing and still there.
    const word = toggle.locator('.facet-theme-toggle__text');
    await expect(word).toHaveText(NAME);

    const box = await word.boundingBox();
    expect(box).not.toBeNull();
    expect(box!.width).toBeLessThan(2);
    expect(box!.height).toBeLessThan(2);

    // The scene is decoration and says so, so none of it reaches the tree.
    await expect(toggle.locator('.facet-theme-toggle__scene')).toHaveAttribute('aria-hidden', 'true');
    expect(await toggle.getByRole('img').count()).toBe(0);
  });

  test('the target stays 44px however small the capsule is drawn', async ({ page }) => {
    for (const viewport of [MOBILE, { width: 1280, height: 900 }]) {
      await page.setViewportSize(viewport);
      await page.goto('/fr');

      const box = await control(page).boundingBox();
      expect(box).not.toBeNull();
      expect(box!.width).toBeGreaterThanOrEqual(TOUCH_TARGET);
      expect(box!.height).toBeGreaterThanOrEqual(TOUCH_TARGET);

      // The thing you look at is deliberately smaller than the thing you hit.
      const scene = await control(page).locator('.facet-theme-toggle__scene').boundingBox();
      expect(scene).not.toBeNull();
      expect(scene!.height).toBeLessThan(TOUCH_TARGET);
    }
  });

  /**
   * The default, and the whole of it: the hour on the visitor's own clock.
   *
   * Both boundaries are walked from the outside in, because a rule stated as
   * `>= 7 && < 20` is wrong in exactly two places and both of them are here.
   * The theme is read off the root attribute the pre-paint bootstrap stamped,
   * so what is asserted is the state the page *opened* in rather than one the
   * module corrected afterwards.
   */
  test('the local hour picks the theme when nothing is stored', async ({ page }) => {
    // The hour is what the rule reads, so the hour is what is varied: 6 is
    // every minute from 06:00 to 06:59, and 19 every minute to 19:59.
    const hours: ReadonlyArray<readonly [number, string]> = [
      [0, 'dark'],
      [6, 'dark'],
      [7, 'light'],
      [12, 'light'],
      [19, 'light'],
      [20, 'dark'],
      [23, 'dark'],
    ];

    for (const [hour, theme] of hours) {
      const label = `${String(hour).padStart(2, '0')}:00`;

      await at(page, hour);
      await page.goto('/fr');

      // Seven loads in a row, and the page defers a chunk of its own: without
      // waiting for the network to go quiet the next navigation cancels a
      // request the previous page was still making, which WebKit reports as a
      // refused subresource and the suite's diagnostics — correctly — fail on.
      await page.waitForLoadState('networkidle');

      await expect(page.locator('html'), label).toHaveAttribute('data-theme', theme);
      await expect(control(page), label).toHaveAttribute(
        'aria-pressed',
        theme === 'dark' ? 'true' : 'false',
      );
    }
  });

  /**
   * The operating system no longer has a vote. It used to decide when nothing
   * was stored; the product rule is the clock, and a machine set to dark at
   * noon must not quietly outrank it — nor a light machine at ten at night.
   */
  test('the system colour scheme does not override the clock', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await at(page, NOON);
    await page.goto('/fr');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');

    await page.emulateMedia({ colorScheme: 'light' });
    await at(page, NIGHT);
    await page.goto('/fr');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  });

  test('a stored preference beats the clock in both directions', async ({ page }) => {
    // Light kept at ten at night is the half that is easy to lose.
    await page.emulateMedia({ colorScheme: 'dark' });
    await at(page, NIGHT);
    await page.addInitScript(() => window.localStorage.setItem('facet.theme', 'light'));
    await page.goto('/fr');

    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    await expect(control(page)).toHaveAttribute('aria-pressed', 'false');
  });

  test('a stored dark preference is respected in the middle of the day', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' });
    await at(page, NOON);
    await page.addInitScript(() => window.localStorage.setItem('facet.theme', 'dark'));
    await page.goto('/fr');

    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expect(control(page)).toHaveAttribute('aria-pressed', 'true');
  });

  test('a stored value that is not a theme falls back to the clock', async ({ page }) => {
    await page.addInitScript(() => window.localStorage.setItem('facet.theme', 'system'));

    await at(page, NOON);
    await page.goto('/fr');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');

    await at(page, NIGHT);
    await page.goto('/fr');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  });

  /**
   * A choice made at 18:00 is still the choice at 18:05, and a reload does not
   * hand the decision back to the clock.
   */
  test('a choice survives the reload that the clock would have overruled', async ({ page }) => {
    await at(page, NOON);
    await page.goto('/fr');

    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    await control(page).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    expect(await page.evaluate(() => window.localStorage.getItem('facet.theme'))).toBe('dark');

    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expect(control(page)).toHaveAttribute('aria-pressed', 'true');
  });

  test('the body sits at the day end in light and the night end in dark', async ({ page }) => {
    await at(page, NOON);
    await page.goto('/fr');

    const toggle = control(page);
    const scene = toggle.locator('.facet-theme-toggle__scene');
    const orb = toggle.locator('.facet-theme-toggle__orb');

    /** How far along the track the body is, 0 at the day end and 1 at night. */
    const position = async (): Promise<number> => {
      const track = await scene.boundingBox();
      const body = await orb.boundingBox();
      expect(track).not.toBeNull();
      expect(body).not.toBeNull();

      return (body!.x - track!.x) / (track!.width - body!.width);
    };

    await expect.poll(position).toBeLessThan(0.25);

    await toggle.click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

    // The travel is the state, and it is the half of the state that survives
    // greyscale, forced colours, and not seeing the difference between a blue
    // sky and a charcoal one.
    await expect.poll(position).toBeGreaterThan(0.75);

    await toggle.click();
    await expect.poll(position).toBeLessThan(0.25);
  });

  test('the sun is even and the moon is cratered', async ({ page }) => {
    await at(page, NOON);
    await page.goto('/fr');

    const toggle = control(page);
    const craters = () =>
      toggle
        .locator('.facet-theme-toggle__orb')
        .evaluate((node) => Number.parseFloat(getComputedStyle(node, '::after').opacity));

    const sky = () =>
      toggle
        .locator('.facet-theme-toggle__scene')
        .evaluate((node) => getComputedStyle(node).backgroundColor);

    await expect(toggle).toHaveAttribute('aria-pressed', 'false');
    await expect.poll(craters).toBeLessThan(0.05);

    const day = await sky();

    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-pressed', 'true');
    await expect.poll(craters).toBeGreaterThan(0.3);

    // The sky moves too — it is simply not the only thing that does.
    expect(await sky()).not.toBe(day);
  });

  test('Space and Enter both switch the theme', async ({ page }) => {
    await at(page, NOON);
    await page.goto('/fr');

    const toggle = control(page);
    await toggle.focus();
    await expect(toggle).toBeFocused();

    await page.keyboard.press('Space');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expect(toggle).toHaveAttribute('aria-pressed', 'true');

    await page.keyboard.press('Enter');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    await expect(toggle).toHaveAttribute('aria-pressed', 'false');
  });

  test('focus is visible, and it is drawn on the capsule', async ({ page }) => {
    await page.goto('/fr');

    const toggle = control(page);
    await toggle.focus();

    const outline = await toggle
      .locator('.facet-theme-toggle__scene')
      .evaluate((node) => getComputedStyle(node).outlineWidth);

    expect(Number.parseFloat(outline)).toBeGreaterThan(0);
  });

  test('switching is deterministic, needs no reload, and fires once per press', async ({ page }) => {
    await at(page, NOON);
    await page.goto('/fr');

    // A marker that only survives if the document is never replaced.
    await page.evaluate(() => {
      window.name = 'facet-theme-marker';
    });

    const toggle = control(page);

    // Ten presses. A second listener on the same button would flip the theme
    // twice per press and land back where it started, so an odd number of
    // presses ending in `light` is exactly the duplicate-listener failure.
    for (let press = 1; press <= 10; press++) {
      await toggle.click();
      await expect(page.locator('html')).toHaveAttribute(
        'data-theme',
        press % 2 === 1 ? 'dark' : 'light',
      );
    }

    await expect(control(page)).toHaveAttribute('aria-pressed', 'false');
    expect(await page.evaluate(() => window.name)).toBe('facet-theme-marker');
    expect(await page.evaluate(() => window.localStorage.getItem('facet.theme'))).toBe('light');
  });

  test('hovering the control does not switch anything', async ({ page }) => {
    await at(page, NOON);
    await page.goto('/fr');

    const toggle = control(page);

    // Both states are reached the way a visitor reaches them — by pressing the
    // button — rather than by writing `data-theme` from the test. Nothing but
    // the control and the system preference is supposed to move that
    // attribute, and a test that moved it itself would be asserting against a
    // state the page never produces.
    await expect(toggle).toHaveAttribute('aria-pressed', 'false');

    /*
     * Since PORT-138's corrective the bootstrap always stamps a theme — the
     * stored choice if there is one and the local hour otherwise — so the pair
     * read here is the attribute and the control's own reported state, and at
     * noon with nothing stored the resting state is `light/false`.
     */
    const state = async (): Promise<string> => {
      const declared = await page.locator('html').getAttribute('data-theme');
      const pressed = await toggle.getAttribute('aria-pressed');

      return `${declared ?? 'system'}/${pressed}`;
    };

    for (const expected of ['light/false', 'dark/true']) {
      if (expected === 'dark/true') {
        await toggle.click();
      }

      await expect.poll(state).toBe(expected);

      const before = await page.evaluate(() => window.localStorage.getItem('facet.theme'));

      await toggle.hover();
      // The hover treatment is a transition of its own; this outlasts it.
      await expect.poll(state, { timeout: 2000 }).toBe(expected);

      // And a hover is not a choice: nothing was written down.
      expect(await page.evaluate(() => window.localStorage.getItem('facet.theme'))).toBe(before);

      // Leaving is as uneventful as arriving.
      await page.mouse.move(0, 0);
      await expect.poll(state).toBe(expected);
    }
  });

  test('reduced motion keeps the switch and drops the travel', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await at(page, NOON);
    await page.goto('/fr');

    const toggle = control(page);

    const durations = () =>
      toggle.locator('.facet-theme-toggle__orb').evaluate((node) => ({
        orb: getComputedStyle(node).transitionDuration,
        drift: getComputedStyle(node).getPropertyValue('--facet-toggle-drift').trim(),
      }));

    const before = await durations();

    /*
     * The shared layer's reduced-motion rule sets a single `!important`
     * duration, which replaces the skin's four-property list outright — so
     * this may be one value or four depending on which layer won. Every entry
     * is checked either way, and anything shorter than a frame is no travel.
     */
    const seconds = before.orb.split(',').map((value) => Number.parseFloat(value));
    expect(seconds.length).toBeGreaterThan(0);

    for (const duration of seconds) {
      expect(duration).toBeLessThan(0.016);
    }

    // And the skin removes the two vertical translations outright rather than
    // merely compressing them: at zero duration a drift would be a teleport.
    expect(Number.parseFloat(before.drift)).toBe(0);

    // The functionality is untouched: same click, same result, same storage.
    await toggle.click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expect(toggle).toHaveAttribute('aria-pressed', 'true');

    await toggle.click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
  });

  test('the control never overlaps the navigation at any width, in either theme', async ({
    page,
  }) => {
    // Both themes at every width in the matrix. The theme is stored rather
    // than pressed, so each width is measured on a settled page and never
    // mid-crossfade.
    // Eighteen loads, each waited out to a quiet network: worth the minutes,
    // and more than the default budget for one case.
    test.setTimeout(180_000);

    const widths = [320, 390, 412, 768, 834, 1024, 1280, 1512, 1920];

    for (const [theme, width] of ['light', 'dark'].flatMap((value) =>
      widths.map((width) => [value, width] as const),
    )) {
      await page.addInitScript((value) => {
        window.localStorage.setItem('facet.theme', value);
      }, theme);

      await page.setViewportSize({ width, height: 900 });
      await page.goto('/fr');
      await page.waitForLoadState('networkidle');

      await expect(page.locator('html'), `${width}px`).toHaveAttribute('data-theme', theme);

      // The capsule keeps its silhouette at every width: the scene is drawn in
      // rem on a fixed track, so what is checked is that it is still there and
      // still the size it was designed at.
      const scene = await control(page).locator('.facet-theme-toggle__scene').boundingBox();
      expect(scene, `${width}px: the capsule must be laid out`).not.toBeNull();
      expect(scene!.width, `${width}px: the capsule`).toBeGreaterThan(40);

      const toggle = await control(page).boundingBox();
      expect(toggle, `${width}px: the control must be laid out`).not.toBeNull();

      const navigation = page.getByRole('navigation', { name: 'Navigation principale' });

      if (await navigation.isVisible()) {
        const nav = await navigation.boundingBox();
        expect(nav).not.toBeNull();

        const overlaps =
          toggle!.x < nav!.x + nav!.width &&
          nav!.x < toggle!.x + toggle!.width &&
          toggle!.y < nav!.y + nav!.height &&
          nav!.y < toggle!.y + toggle!.height;

        expect(overlaps, `${width}px: the control overlaps the navigation`).toBe(false);
      }

      // And nothing the header does makes the page scroll sideways.
      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
      );
      expect(overflow, `${width}px ${theme}: horizontal overflow`).toBeLessThanOrEqual(1);
    }
  });
});

/**
 * The footer, which PORT-138 took off the enhanced page.
 *
 * It repeated the primary navigation, which on a wide screen made it a second
 * banner at the other end of the document; there is no legal, privacy or
 * editorial content to put in its place, and inventing some would have been
 * worse than the duplication. So the enhanced page ends with its content — and
 * the served document, which is what a reader without JavaScript gets, still
 * carries the full footer it always did.
 */
test.describe('footer', () => {
  test('the enhanced page has no duplicate navigation at the bottom', async ({ page }) => {
    for (const viewport of [MOBILE, { width: 1440, height: 900 }]) {
      await page.setViewportSize(viewport);
      await page.goto('/fr');

      const footer = page.locator('footer');
      await expect(footer).toBeHidden();

      // Hidden by `display: none`, so nothing inside it is reachable by tab or
      // announced — a visually hidden footer would be four invisible stops.
      expect(await footer.getByRole('link').count()).toBe(0);
    }
  });

  test('the served document keeps the footer a reader without JavaScript needs', async ({ page }) => {
    // A canonical localized URL rather than the entry route: `/` is a redirect
    // and its body is deliberately empty.
    const html = await (await page.request.get('/fr')).text();

    expect(html).toContain('facet-footer');

    for (const label of ['Accueil', 'Projets', 'À propos', 'Contact']) {
      expect(html).toContain(`>${label}</a>`);
    }
  });
});

/**
 * The page theme change, which is the part of PORT-138 the control was hiding.
 *
 * The capsule's own animation was always there; what was not was any
 * transition of the *document*, which repainted from near-white to near-black
 * between two frames. The fix is one attribute — `data-facet-theme-shift` —
 * that exists only while a manual switch is in flight, and one mechanism
 * behind it in every engine: a short colour transition, scoped to that mark
 * and to everything except the control's own subtree.
 *
 * A transition cannot be photographed, and polling for a 320ms attribute is a
 * race. So it is *recorded* instead: a MutationObserver installed before the
 * click writes down every change to that attribute, and the assertions are
 * made against the log. That is engine-independent, and it proves the two
 * things that matter — that the switch is a transition, and that it ends.
 */
declare global {
  interface Window {
    __facetShiftLog?: boolean[];
  }
}

const watchShift = async (page: Page): Promise<void> => {
  await page.evaluate(() => {
    window.__facetShiftLog = [];

    new MutationObserver(() => {
      window.__facetShiftLog?.push(
        document.documentElement.hasAttribute('data-facet-theme-shift'),
      );
    }).observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['data-facet-theme-shift'],
    });
  });
};

const shiftLog = (page: Page): Promise<boolean[]> =>
  page.evaluate(() => window.__facetShiftLog ?? []);

test.describe('the page theme transition', () => {
  test('a manual switch is a transition, and it terminates — both ways', async ({ page }) => {
    await at(page, NOON);
    await page.goto('/fr');

    const toggle = control(page);
    const root = page.locator('html');

    for (const expected of ['dark', 'light']) {
      await watchShift(page);
      await toggle.click();

      await expect(root).toHaveAttribute('data-theme', expected);

      // It started...
      await expect.poll(async () => (await shiftLog(page)).includes(true)).toBe(true);
      // ...and it finished, without a reload and without being cleared early.
      await expect.poll(async () => (await shiftLog(page)).at(-1), { timeout: 4000 }).toBe(false);
      await expect(root).not.toHaveAttribute('data-facet-theme-shift', /.*/);
    }
  });

  test('the transition crosses the document and leaves the capsule alone', async ({ page }) => {
    await at(page, NOON);
    await page.goto('/fr');

    // The rule replaces every transition list it touches, so the one subtree
    // it must not touch is the control's: an orb whose `translate` transition
    // were overwritten mid-switch would teleport across the track instead of
    // travelling, which is the approved animation undone by its own crossfade.
    const scoped = await page.evaluate(() => {
      document.documentElement.setAttribute('data-facet-theme-shift', '');

      const orb = document.querySelector('.facet-theme-toggle__orb');
      const main = document.querySelector('main');
      const reading = {
        orb: orb === null ? '' : getComputedStyle(orb).transitionProperty,
        orbDuration: orb === null ? '' : getComputedStyle(orb).transitionDuration,
        main: main === null ? '' : getComputedStyle(main).transitionProperty,
        mainDuration: main === null ? '' : getComputedStyle(main).transitionDuration,
      };

      document.documentElement.removeAttribute('data-facet-theme-shift');

      return reading;
    });

    expect(scoped.orb).toContain('translate');
    expect(scoped.orbDuration).toContain('0.36s');

    expect(scoped.main).toContain('background-color');
    expect(scoped.mainDuration.split(',').map((value) => value.trim())).toContain('0.32s');
  });

  test('rapid switching settles on one theme and leaves nothing behind', async ({ page }) => {
    await at(page, NOON);
    await page.goto('/fr');

    const toggle = control(page);
    await watchShift(page);

    // light → dark → light, as fast as the engine will take them.
    for (let press = 0; press < 3; press++) {
      await toggle.click({ noWaitAfter: true });
    }

    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expect(control(page)).toHaveAttribute('aria-pressed', 'true');

    // Every transition that was started has ended, and exactly one theme is on
    // the document. A superseded transition must not strip the attribute out
    // from under the one that replaced it, nor leave it stamped for good.
    await expect
      .poll(async () => (await shiftLog(page)).at(-1), { timeout: 4000 })
      .toBe(false);
    await expect(page.locator('html')).not.toHaveAttribute('data-facet-theme-shift', /.*/);

    expect(await page.evaluate(() => window.localStorage.getItem('facet.theme'))).toBe('dark');
  });

  test('reduced motion switches the theme with no page transition at all', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await at(page, NOON);
    await page.goto('/fr');

    await watchShift(page);
    await control(page).click();

    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expect(control(page)).toHaveAttribute('aria-pressed', 'true');

    // Not "a fast transition": none. The attribute is never set, so neither
    // mechanism is ever armed.
    expect(await shiftLog(page)).toEqual([]);
    await expect(page.locator('html')).not.toHaveAttribute('data-facet-theme-shift', /.*/);
  });

  test('opening the page is not a transition', async ({ page }) => {
    // A visitor whose clock says night opens in dark. They do not watch the
    // page become dark — the theme is already resolved before the first paint.
    await at(page, NIGHT);
    await page.goto('/fr');

    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expect(page.locator('html')).not.toHaveAttribute('data-facet-theme-shift', /.*/);

    // And the served document says nothing about a transition either.
    const html = await (await page.request.get('/')).text();
    expect(html).not.toContain('data-facet-theme-shift');
  });
});
