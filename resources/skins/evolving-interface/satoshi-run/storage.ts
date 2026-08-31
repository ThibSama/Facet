/**
 * Satoshi Run — the one thing it remembers.
 *
 * A single browser-local integer under a namespaced key, written the same
 * guarded way the theme preference is (see resources/js/theme.ts): storage
 * throws outright in some privacy modes, and a best score is never worth
 * breaking a page over. There is no server, no account and no database behind
 * this — a best score is a fact about this browser and nothing else.
 */
const BEST_SCORE_KEY = 'facet.satoshi-run.best';

/** The stored best, or 0 when there is none — or none can be read. */
function readBestScore(): number {
  try {
    const stored = window.localStorage.getItem(BEST_SCORE_KEY);

    if (stored === null) {
      return 0;
    }

    const value = Number.parseInt(stored, 10);

    return Number.isFinite(value) && value > 0 ? value : 0;
  } catch {
    return 0;
  }
}

/**
 * Records a score if it beats the stored one, and answers with the best that
 * now stands. A score that did not beat it is not written, so a run that ends
 * badly can never lower the record.
 */
function recordScore(score: number): number {
  const best = readBestScore();

  if (score <= best) {
    return best;
  }

  try {
    window.localStorage.setItem(BEST_SCORE_KEY, String(score));
  } catch {
    // Unwritable storage costs the visitor a record, not their run.
  }

  return score;
}

export { BEST_SCORE_KEY, readBestScore, recordScore };
