/**
 * Journeys the specs perform rather than assert.
 *
 * Signing in is done the way a person does it — the real form, the real POST,
 * the token the server put on the page — and never by writing a cookie or
 * poking a session. A helper that forged a session would prove the pages
 * render, not that the sign-in works, and the pages it reached would be
 * unreachable in the product.
 */
import type { Page, Response } from '@playwright/test';

import { expect } from './test';

/**
 * Sign in and land on the account's own area.
 *
 * Where that is comes from the role on the stored row, so the caller states
 * the destination it expects and the helper proves the server agreed.
 */
async function signIn(page: Page, email: string, password: string, landsOn: string): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();

  await expect(page).toHaveURL(landsOn);
}

/** Sign out through the shell's own form, and land back on the public site. */
async function signOut(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Sign out' }).click();

  await expect(page).toHaveURL('/');
}

/**
 * Click something that submits a form, and hand back the server's answer.
 *
 * The navigation response is what carries the status code, and a status code is
 * the only way to tell a refusal apart from a page that merely looks like one.
 */
async function submitAndCapture(page: Page, action: () => Promise<void>): Promise<Response> {
  const response = page.waitForResponse((candidate) => candidate.request().isNavigationRequest());

  await action();

  return response;
}

export { signIn, signOut, submitAndCapture };
