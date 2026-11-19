import { test, expect } from '@playwright/test';

test('calendar renders events, styles and navigates between months', async ({ page }, testInfo) => {
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/?pagename=calendar-demo&museum_month=2026-11');
  const calendar = page.locator('.museum-calendar');
  await expect(calendar.locator('caption')).toHaveText('November 2026');
  await expect(calendar.getByText('日時変更ガイド', { exact: true })).toBeVisible();
  await expect(calendar.getByText('終日展示', { exact: true })).toHaveCount(3);
  await expect(calendar.locator('li[data-title="ガイドツアー開催日"]')).toHaveAttribute('data-kind', 'tour');
  await expect(calendar.locator('li[data-title="日時変更ガイド"]')).toHaveAttribute('data-kind', 'other');
  await expect(calendar.getByText(/実際の開館情報ではありません/)).toHaveCount(0);
  await expect(calendar.locator('table')).toHaveCSS('table-layout', 'fixed');
  await calendar.getByRole('link', { name: 'December »', exact: true }).click();
  await expect(calendar.locator('caption')).toHaveText('December 2026');
  await expect(calendar.getByText('翌月の予定', { exact: true })).toBeVisible();
  await calendar.getByRole('link', { name: '« November', exact: true }).click();
  await expect(calendar.locator('caption')).toHaveText('November 2026');
  expect(errors).toEqual([]);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
  expect(overflow).toBe(false);
  await page.screenshot({ path: testInfo.outputPath('calendar.png'), fullPage: true });
});

test('administrator sees and saves the public URL of the calendar, and CSRF is rejected', async ({ page }) => {
  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('password');
  await page.locator('#wp-submit').click();
  await expect(page).toHaveURL(/\/wp-admin\//);
  await page.goto('/wp-admin/options-general.php?page=museum-calendar');
  await expect(page.getByRole('heading', { name: 'Museum Calendar', exact: true })).toBeVisible();
  const field = page.getByLabel('Googleカレンダーの公開URL', { exact: true });
  const publicUrl = 'https://calendar.google.com/calendar/embed?src=museum-fixture%40group.calendar.google.com';
  await expect(field).toHaveAttribute('type', 'text');
  await expect(field).toHaveValue(publicUrl);
  const invalidPost = await page.request.post('/wp-admin/admin-post.php', {
    form: { action: 'museum_calendar_save', url: '' },
  });
  expect(invalidPost.status()).toBe(403);
  await field.fill('https://calendar.google.com/calendar/ical/museum-fixture%40group.calendar.google.com/private-secret/basic.ics');
  await page.getByRole('button', { name: '設定を保存', exact: true }).click();
  await expect(page.getByText(/URLを保存できませんでした。/)).toBeVisible();
  await expect(field).toHaveValue(publicUrl);
  await field.fill(`${publicUrl}&ctz=Asia%2FTokyo`);
  await page.getByRole('button', { name: '設定を保存', exact: true }).click();
  await expect(page.getByText('設定を保存しました。', { exact: true })).toBeVisible();
  await expect(field).toHaveValue(publicUrl);
  await page.getByRole('button', { name: '今すぐ更新', exact: true }).click();
  await expect(page.getByText(/^最終成功：/)).not.toContainText('未取得');
  await expect(page.getByRole('heading', { name: '種類が未定義の予定名', exact: true })).toBeVisible();
  await page.screenshot({ path: 'test-results/settings-' + test.info().project.name + '.png', fullPage: true });
});

test('invalid months are rejected', async ({ page }) => {
  await page.goto('/?pagename=calendar-demo&museum_month=2026-13');
  await expect(page.getByText('年月の指定が正しくありません。')).toBeVisible();
  await expect(page.locator('.museum-calendar table')).toHaveCount(0);
});
