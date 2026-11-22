// 実サイトのトップページの写しのテーマをステージングのWordPressにアップロードし、ステージングがそのテーマを配信していることを確かめる。
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { withStagingAdmin } from './staging-admin.mjs';

const root = fileURLToPath(new URL('../', import.meta.url));
// 環境変数にすでにある値は .env より優先する。
if (existsSync(path.join(root, '.env'))) process.loadEnvFile(path.join(root, '.env'));
const theme = 'otani-museum2026';
const zip = path.join(root, `dist/site-theme-${theme}.zip`);
const local = path.join(root, '.local/site/theme', theme);
// ステージングは s-maxage=600 で応答するので、毎回キャッシュにないURLを要求する。
const fresh = (url) => `${url}${url.includes('?') ? '&' : '?'}museum_calendar_deploy=${Date.now()}`;

await withStagingAdmin(async ({ site, page, check }) => {
  await page.goto(`${site}/wp-admin/theme-install.php?upload`);
  if (!(await page.locator('#install-theme-submit').isVisible())) await page.locator('.upload-view-toggle').click();
  await page.locator('#themezip').setInputFiles(zip);
  await page.locator('#install-theme-submit').click();
  await page.waitForLoadState('load');
  // 導入済みのテーマをアップロードしたテーマで置き換える前に、WordPressが確認を求める。
  const replace = page.locator('a.update-from-upload-overwrite');
  if (await replace.count()) {
    await replace.click();
    await page.waitForLoadState('load');
  }

  for (const file of ['assets/js/museum-calendar.js', 'assets/css/museum-calendar.css']) {
    const response = await fetch(fresh(`${site}/wp-content/themes/${theme}/${file}`));
    const served = Buffer.from(await response.arrayBuffer());
    check(response.ok && served.equals(readFileSync(path.join(local, file))), `staging serves the local ${file}`);
  }

  await page.goto(fresh(`${site}/wp-admin/themes.php`));
  const active = await page.locator(`.theme.active[data-slug="${theme}"]`).count() === 1;
  check(active, active ? `the theme ${theme} is active`
    : `the theme ${theme} is not active. Activate it in wp-admin, or upload and activate dist/site-seed.zip, which also sets up the pages.`);

  for (const url of [`${site}/`, `${site}/en/`]) {
    const response = await page.goto(fresh(url));
    check(response?.status() === 200, `${url} responds with 200`);
    check(!(await page.content()).includes('critical error'), `${url} has no PHP fatal error`);
    check(await page.locator('.c-cal table').count() === 1, `${url} shows the theme's calendar`);
    check(await page.locator('section.museum-calendar-today').count() === 1, `${url} shows today's schedule`);
  }
});
