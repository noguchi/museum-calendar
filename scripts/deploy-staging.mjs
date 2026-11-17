// Uploads the plugin ZIP to the staging WordPress and checks that the site displays the calendar with it.
import { chromium } from '@playwright/test';
import { basename } from 'node:path';

const [zip] = process.argv.slice(2);
const { STAGING_URL, STAGING_WP_USER, STAGING_WP_PASSWORD } = process.env;
if (!zip || !STAGING_URL || !STAGING_WP_USER || !STAGING_WP_PASSWORD) {
  throw new Error('Usage: STAGING_URL=… STAGING_WP_USER=… STAGING_WP_PASSWORD=… node scripts/deploy-staging.mjs <museum-calendar-x.y.z.zip>');
}
const version = basename(zip).match(/^museum-calendar-(\d+\.\d+\.\d+)\.zip$/)?.[1];
if (!version) throw new Error(`Not a plugin ZIP: ${zip}`);
const site = STAGING_URL.replace(/\/+$/, '');
const plugin = 'tr[data-plugin="museum-calendar/museum-calendar.php"]';

const browser = await chromium.launch();
const page = await browser.newPage();
const check = (condition, message) => {
  if (!condition) throw new Error(message);
  console.log(`ok: ${message}`);
};
try {
  await page.goto(`${site}/wp-login.php`);
  await page.locator('#user_login').fill(STAGING_WP_USER);
  await page.locator('#user_pass').fill(STAGING_WP_PASSWORD);
  await page.locator('#wp-submit').click();
  await page.waitForURL(/\/wp-admin\//);
  check(true, 'logged in to the staging site');

  await page.goto(`${site}/wp-admin/plugin-install.php?tab=upload`);
  await page.locator('#pluginzip').setInputFiles(zip);
  await page.locator('#install-plugin-submit').click();
  await page.waitForLoadState('load');
  // WordPress asks before it replaces an installed plugin with an uploaded one.
  const replace = page.locator('a.update-from-upload-overwrite');
  if (await replace.count()) {
    await replace.click();
    await page.waitForLoadState('load');
  }

  await page.goto(`${site}/wp-admin/plugins.php`);
  const row = page.locator(plugin);
  check(await row.count() === 1, 'the plugin is installed');
  check(new RegExp(`\\b${version.replaceAll('.', '\\.')}\\b`).test(await row.innerText()), `the installed plugin is version ${version}`);
  if (!(await row.getAttribute('class')).split(' ').includes('active')) {
    await row.locator('span.activate a').click();
    await page.waitForLoadState('load');
  }
  check((await page.locator(plugin).getAttribute('class')).split(' ').includes('active'), 'the plugin is active');

  await page.goto(`${site}/wp-admin/options-general.php?page=museum-calendar`);
  const field = page.locator('#museum-calendar-url');
  check(await field.getAttribute('type') === 'text', 'the settings page has the public URL field');
  check(await field.inputValue() !== '', 'a calendar is registered');
  await page.getByRole('button', { name: '今すぐ更新', exact: true }).click();
  await page.waitForLoadState('load');
  const error = page.locator('#wpbody-content p[role="alert"]');
  check(await error.count() === 0, `the calendar was fetched${await error.count() ? `: ${await error.innerText()}` : ''}`);

  const response = await page.goto(`${site}/?museum_calendar_deploy=${Date.now()}`);
  check(response?.status() === 200, 'the front page responds with 200');
  const html = await page.content();
  check(!html.includes('critical error'), 'the front page has no PHP fatal error');
  check(await page.locator('section.museum-calendar table').count() === 1, 'the front page shows the month calendar');
  check(await page.locator('section.museum-calendar-today').count() === 1, "the front page shows today's schedule");
  check(!html.includes('現在、予定を取得できません'), 'the front page shows events, not the fetch failure notice');
} catch (error) {
  await page.screenshot({ path: 'test-results/staging-failure.png', fullPage: true }).catch(() => {});
  throw error;
} finally {
  await browser.close();
}
