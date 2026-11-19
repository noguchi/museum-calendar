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

test('today shows the opening status after the date and lists the other events', async ({ page }, testInfo) => {
  await page.goto('/?pagename=calendar-today&museum_fixture_today=open');
  const [japanese, english] = [page.locator('.museum-calendar-today').nth(0), page.locator('.museum-calendar-today').nth(1)];
  const status = japanese.locator('.museum-calendar-today__status');
  await expect(status).toHaveAttribute('data-kind', 'open');
  await expect(status.locator('strong')).toHaveText('OPEN');
  await expect(status.locator('span')).toHaveText('10:30–16:30');
  await expect(japanese.locator('.museum-calendar-today__date + .museum-calendar-today__status')).toBeVisible();
  await expect(japanese.locator('li')).toHaveText(['ガイドツアー開催日11:00–12:00']);
  await expect(japanese.locator('.museum-calendar-today__empty')).toHaveCount(0);
  await expect(status).toHaveCSS('display', 'flex');
  await expect(english.locator('.museum-calendar-today__status strong')).toHaveText('OPEN');
  await expect(english.locator('li strong')).toHaveText(['Guided Tour']);
  await page.screenshot({ path: testInfo.outputPath('today-open.png'), fullPage: true });

  await page.goto('/?pagename=calendar-today&museum_fixture_today=closed');
  await expect(japanese.locator('.museum-calendar-today__status')).toHaveAttribute('data-kind', 'closed');
  await expect(japanese.locator('.museum-calendar-today__status')).toHaveText('CLOSE');
  await expect(english.locator('.museum-calendar-today__status')).toHaveText('CLOSED');
  await expect(page.locator('.museum-calendar-today__status span')).toHaveCount(0);
  await expect(page.locator('.museum-calendar-today li, .museum-calendar-today__empty')).toHaveCount(0);

  await page.goto('/?pagename=calendar-today&museum_fixture_today=none');
  await expect(page.locator('.museum-calendar-today__status')).toHaveCount(0);
  await expect(japanese.locator('.museum-calendar-today__empty')).toHaveText('本日の予定はありません。');
  await expect(english.locator('.museum-calendar-today__empty')).toHaveText('No events today.');
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
  expect(overflow).toBe(false);
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
