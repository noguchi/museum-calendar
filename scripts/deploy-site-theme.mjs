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
    check(response.ok && served.equals(readFileSync(path.join(local, file))), `ステージングが手元の ${file} を配信する`);
  }

  await page.goto(fresh(`${site}/wp-admin/themes.php`));
  const active = await page.locator(`.theme.active[data-slug="${theme}"]`).count() === 1;
  check(active, active ? `テーマ ${theme} が有効`
    : `テーマ ${theme} が有効でない。wp-adminで有効にするか、ページも作る dist/site-seed.zip をアップロードして有効にする。`);

  for (const url of [`${site}/`, `${site}/en/`]) {
    const response = await page.goto(fresh(url));
    check(response?.status() === 200, `${url} が200で応答する`);
    check(!(await page.content()).includes('critical error'), `${url} にPHPの致命的エラーがない`);
    check(await page.locator('.c-cal table').count() === 1, `${url} にテーマのカレンダーが出る`);
    check(await page.locator('section.museum-calendar-today').count() === 1, `${url} に本日の予定が出る`);
  }
});
