// プラグインのZIPをステージングのWordPressにアップロードし、そのプラグインでサイトがカレンダーを表示することを確かめる。
import { basename } from 'node:path';
import { withStagingAdmin } from './staging-admin.mjs';

const [zip] = process.argv.slice(2);
if (!zip) throw new Error('使い方: STAGING_URL=… STAGING_WP_USER=… STAGING_WP_PASSWORD=… node scripts/deploy-staging.mjs <museum-calendar-x.y.z.zip>');
const version = basename(zip).match(/^museum-calendar-(\d+\.\d+\.\d+)\.zip$/)?.[1];
if (!version) throw new Error(`プラグインのZIPではありません: ${zip}`);
const plugin = 'tr[data-plugin="museum-calendar/museum-calendar.php"]';

await withStagingAdmin(async ({ site, page, check }) => {
  await page.goto(`${site}/wp-admin/plugin-install.php?tab=upload`);
  await page.locator('#pluginzip').setInputFiles(zip);
  await page.locator('#install-plugin-submit').click();
  await page.waitForLoadState('load');
  // 導入済みのプラグインをアップロードしたプラグインで置き換える前に、WordPressが確認を求める。
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
  // 設定画面は保存したURLを表示しないので、登録状況の行でカレンダーの登録を確かめる。入力欄の値はここでは読まない。
  check(await page.locator('#museum-calendar-url').getAttribute('type') === 'password', 'the settings page has the masked URL field');
  check(/^登録状況：(?:非公開|公開)URLを登録済み$/.test((await page.locator('#museum-calendar-connection').innerText()).trim()), 'a calendar is registered');
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
});
