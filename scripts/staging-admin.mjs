// 配備のスクリプトのために、ステージングのWordPressの管理画面にログインする。ログイン情報は環境変数から読み、出力しない。
import { chromium } from '@playwright/test';

export async function withStagingAdmin(task) {
  const missing = ['STAGING_URL', 'STAGING_WP_USER', 'STAGING_WP_PASSWORD'].filter((name) => !process.env[name]);
  if (missing.length) throw new Error(`使い方: STAGING_URL にステージングのサイト、STAGING_WP_USER と STAGING_WP_PASSWORD に管理者のログイン情報を指定します。未指定: ${missing.join(', ')}`);
  const site = process.env.STAGING_URL.replace(/\/+$/, '');

  const browser = await chromium.launch();
  const page = await browser.newPage();
  const check = (condition, message) => {
    if (!condition) throw new Error(message);
    console.log(`ok: ${message}`);
  };
  try {
    await page.goto(`${site}/wp-login.php`);
    await page.locator('#user_login').fill(process.env.STAGING_WP_USER);
    await page.locator('#user_pass').fill(process.env.STAGING_WP_PASSWORD);
    await page.locator('#wp-submit').click();
    await page.waitForURL(/\/wp-admin\//);
    check(true, 'logged in to the staging site');
    await task({ site, page, check });
  } catch (error) {
    await page.screenshot({ path: 'test-results/staging-failure.png', fullPage: true }).catch(() => {});
    throw error;
  } finally {
    await browser.close();
  }
}
