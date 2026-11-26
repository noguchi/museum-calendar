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
  await expect(calendar.locator('li[data-title="説明付きの催し"]')).toHaveText('説明付きの催し13:00–14:00');
  const concert = calendar.getByRole('link', { name: '説明付きの催し', exact: true });
  await expect(concert).toHaveAttribute('href', 'https://example.com/concert');
  await expect(concert).toHaveCSS('text-decoration-line', 'underline');
  await expect(calendar.locator('li[data-title="日時変更ガイド"] a')).toHaveCount(0);
  await expect(calendar.getByText(/午後の演奏会|受付で名前|非公開の会議室/)).toHaveCount(0);
  await expect(calendar.locator('table')).toHaveCSS('table-layout', 'fixed');
  // 既定の表はガイドツアーに色を付ける。表にない予定は、種類の色を導入する前と同じ色のまま。
  const tour = calendar.locator('li[data-title="ガイドツアー開催日"]').first();
  await expect(tour).toHaveAttribute('style', '--museum-calendar-kind-color: #e3f2fd');
  await expect(tour).toHaveCSS('background-color', 'rgb(227, 242, 253)');
  const other = calendar.locator('li[data-title="日時変更ガイド"]').first();
  await expect(other).not.toHaveAttribute('style');
  await expect(other).toHaveCSS('background-color', 'rgb(244, 241, 235)');
  await expect(other).toHaveCSS('border-left-color', 'rgb(99, 84, 64)');
  await expect(other).toHaveCSS('color', 'rgb(41, 37, 31)');
  await expect(concert).toHaveCSS('color', 'rgb(99, 84, 64)');
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

test('today shows only the date and the opening status', async ({ page }, testInfo) => {
  await page.goto('/?pagename=calendar-today&museum_fixture_today=open');
  const [japanese, english] = [page.locator('.museum-calendar-today').nth(0), page.locator('.museum-calendar-today').nth(1)];
  const status = japanese.locator('.museum-calendar-today__status');
  await expect(status).toHaveAttribute('data-kind', 'open');
  await expect(status.locator('strong')).toHaveText('開館日');
  await expect(status.locator('span')).toHaveText('10:30–16:30');
  await expect(japanese.locator('.museum-calendar-today__date + .museum-calendar-today__status')).toBeVisible();
  await expect(page.locator('.museum-calendar-today ul, .museum-calendar-today li')).toHaveCount(0);
  await expect(page.locator('.museum-calendar-today').filter({ hasText: /ガイドツアー|Guided Tour/ })).toHaveCount(0);
  await expect(japanese.locator('.museum-calendar-today__empty')).toHaveCount(0);
  await expect(status).toHaveCSS('display', 'flex');
  await expect(status).toHaveAttribute('style', '--museum-calendar-kind-color: #ffebee');
  await expect(status).toHaveCSS('background-color', 'rgb(255, 235, 238)');
  await expect(english.locator('.museum-calendar-today__status strong')).toHaveText('OPEN');
  await page.screenshot({ path: testInfo.outputPath('today-open.png'), fullPage: true });

  await page.goto('/?pagename=calendar-today&museum_fixture_today=closed');
  await expect(japanese.locator('.museum-calendar-today__status')).toHaveAttribute('data-kind', 'closed');
  await expect(japanese.locator('.museum-calendar-today__status')).toHaveText('休館日');
  await expect(english.locator('.museum-calendar-today__status')).toHaveText('CLOSED');
  await expect(page.locator('.museum-calendar-today__status span')).toHaveCount(0);
  await expect(page.locator('.museum-calendar-today li, .museum-calendar-today__empty')).toHaveCount(0);

  await page.goto('/?pagename=calendar-today&museum_fixture_today=none');
  await expect(page.locator('.museum-calendar-today__status')).toHaveCount(0);
  await expect(japanese.locator('.museum-calendar-today__empty')).toHaveText('本日の開館情報はありません。');
  await expect(english.locator('.museum-calendar-today__empty')).toHaveText('No opening information today.');
  await expect(page.locator('.museum-calendar-today').filter({ hasText: /ガイドツアー|Guided Tour/ })).toHaveCount(0);
  await expect(page.locator('.museum-calendar-today ul, .museum-calendar-today li')).toHaveCount(0);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
  expect(overflow).toBe(false);
});

test('administrator registers the private iCal URL without seeing it again, and CSRF is rejected', async ({ page }) => {
  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('password');
  await page.locator('#wp-submit').click();
  await expect(page).toHaveURL(/\/wp-admin\//);
  await page.goto('/wp-admin/options-general.php?page=museum-calendar');
  await expect(page.getByRole('heading', { name: 'Museum Calendar', exact: true })).toBeVisible();
  const field = page.getByLabel('iCal形式の非公開URL', { exact: true });
  const status = page.locator('#museum-calendar-connection');
  const save = () => page.getByRole('button', { name: '設定を保存', exact: true }).click();
  const saved = page.getByText('設定を保存しました。', { exact: true });
  const publicUrl = 'https://calendar.google.com/calendar/ical/museum-fixture%40group.calendar.google.com/public/basic.ics';
  // tests/support/ics-fixture が応答する、作った秘密の部分。ほかのテストも同じカレンダーを読む。
  const token = '0123456789abcdef0123456789abcdef';
  const privateUrl = `https://calendar.google.com/calendar/ical/museum-fixture%40group.calendar.google.com/private-${token}/basic.ics`;
  const neverShown = async () => {
    await expect(field).toHaveValue('');
    const html = await page.content();
    for (const secret of [token, 'museum-fixture', 'calendar.google.com']) {
      expect(html.includes(secret), '設定画面にカレンダーのURLもIDも出ない').toBe(false);
    }
  };
  await expect(field).toHaveAttribute('type', 'password');
  await expect(field).toHaveAttribute('autocomplete', 'new-password');
  // 途中で止まった実行の後でも、seed が登録する公開URLから始める。
  await field.fill(publicUrl);
  await save();
  await expect(saved).toBeVisible();
  await expect(status).toHaveText('登録状況：公開URLを登録済み');
  await expect(page.getByLabel('接続を解除する', { exact: true })).not.toBeChecked();
  await neverShown();

  const forgedPost = await page.request.post('/wp-admin/admin-post.php', {
    form: { action: 'museum_calendar_save', disconnect: '1' },
  });
  expect(forgedPost.status()).toBe(403);
  await field.fill('https://calendar.google.com/calendar/ical/museum-fixture%40group.calendar.google.com/private-not_a_token/basic.ics');
  await save();
  await expect(page.getByText(/^URLを保存できませんでした。Googleカレンダーの「iCal形式の非公開URL」か「iCal形式の公開URL」を/)).toBeVisible();
  await expect(status).toHaveText('登録状況：公開URLを登録済み');
  await neverShown();

  await field.fill(privateUrl);
  await save();
  await expect(saved).toBeVisible();
  await expect(status).toHaveText('登録状況：非公開URLを登録済み');
  await expect(page.getByText(/^最終取得：\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}$/)).toBeVisible();
  await expect(page.locator('#wpbody-content p[role="alert"]')).toHaveCount(0);
  await neverShown();
  // 入力欄が空なら接続を変えず、取得し直す。
  await save();
  await expect(saved).toBeVisible();
  await expect(status).toHaveText('登録状況：非公開URLを登録済み');
  await page.getByRole('button', { name: '今すぐ更新', exact: true }).click();
  await expect(page.getByText(/^最終取得：\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}$/)).toBeVisible();
  await expect(page.getByRole('heading', { name: '種類が未定義の予定名', exact: true })).toBeVisible();
  await neverShown();
  await page.screenshot({ path: 'test-results/settings-' + test.info().project.name + '.png', fullPage: true });

  await field.fill(publicUrl);
  await save();
  await expect(saved).toBeVisible();
  await expect(status).toHaveText('登録状況：公開URLを登録済み');
});

test('administrator adds a kind of event, sees an invalid one rejected and resets the table', async ({ page }) => {
  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('password');
  await page.locator('#wp-submit').click();
  await expect(page).toHaveURL(/\/wp-admin\//);
  await page.goto('/wp-admin/options-general.php?page=museum-calendar');
  const field = (row, column) => page.getByLabel(`${row}行目の${column}`, { exact: true });
  const save = () => page.getByRole('button', { name: '予定の種類を保存', exact: true }).click();
  const saved = page.getByText('予定の種類を保存しました。', { exact: true });
  // リダイレクト先は表のフォームの見出しなので、通知はそこで画面内に見えていなければならない。
  const savedInView = async () => {
    await expect(saved).toBeVisible();
    await expect(saved).toBeInViewport();
  };
  const titles = page.locator('input[name$="[title]"]');
  // 途中で止まった実行の後でも、既定の表から始める。
  await page.getByLabel('既定の表に戻す', { exact: true }).check();
  await save();
  await savedInView();
  await expect(page.getByRole('heading', { name: '予定の種類と英語での表示', exact: true })).toBeVisible();
  await expect(titles).toHaveCount(11);
  await expect(field(9, 'タイトル')).toHaveValue('');

  await field(9, 'タイトル').fill('ワークショップ');
  await field(9, '種類').fill('workshop');
  await field(9, '英語での表示').fill('Workshop');
  await save();
  await savedInView();
  await expect(titles).toHaveCount(12);
  await expect(field(9, 'タイトル')).toHaveValue('ワークショップ');
  await expect(field(9, '種類')).toHaveValue('workshop');
  await expect(field(9, '英語での表示')).toHaveValue('Workshop');

  // 色の選択で選んだ色は文字の入力欄に入り、文字の入力欄に入力した色は色の選択に反映される。
  const picker = (row) => field(row, '色を選ぶ');
  await picker(4).fill('#1f3a93');
  await expect(field(4, '色')).toHaveValue('#1f3a93');
  await expect(picker(4)).not.toHaveAttribute('data-empty');
  await field(1, '色').fill('');
  await expect(picker(1)).toHaveAttribute('data-empty', '');
  await field(10, 'タイトル').fill('説明付きの催し');
  await field(10, '種類').fill('event');
  await expect(picker(10)).toHaveAttribute('data-empty', '');
  await field(10, '色').fill(' #2F6F4F ');
  await expect(picker(10)).toHaveValue('#2f6f4f');
  await expect(picker(10)).not.toHaveAttribute('data-empty');
  await save();
  await savedInView();
  await expect(field(4, '色')).toHaveValue('#1f3a93');
  await expect(picker(4)).toHaveValue('#1f3a93');
  await expect(field(10, '色')).toHaveValue('#2f6f4f');
  await expect(field(1, '色')).toHaveValue('');
  await expect(picker(1)).toHaveAttribute('data-empty', '');
  await expect(picker(1)).toHaveValue('#ffffff');

  // 計算後の色を sRGB のバイト値で得る。ブラウザーがどの色空間で書き出しても同じ値で比べられる。
  const srgb = (target, property) => target.evaluate((element, property) => {
    const context = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
    context.fillStyle = getComputedStyle(element)[property];
    context.fillRect(0, 0, 1, 1);
    return [...context.getImageData(0, 0, 1, 1).data.slice(0, 3)];
  }, property);
  const near = (actual, expected) => expect(actual.every((value, index) => Math.abs(value - expected[index]) <= 3), `${actual} は ${expected} に近い`).toBe(true);
  const colorPage = await page.context().newPage();
  await colorPage.goto('/?pagename=calendar-demo&museum_month=2026-11');
  const coloredTour = colorPage.locator('.museum-calendar li[data-title="ガイドツアー開催日"]').first();
  await expect(coloredTour).toHaveAttribute('style', '--museum-calendar-kind-color: #1f3a93');
  await expect(coloredTour).toHaveCSS('background-color', 'rgb(31, 58, 147)');
  near(await srgb(coloredTour, 'borderLeftColor'), [11, 29, 117]);
  expect(await srgb(coloredTour, 'color')).toEqual([255, 255, 255]);
  const linked = colorPage.locator('.museum-calendar li[data-title="説明付きの催し"]').first();
  await expect(linked).toHaveAttribute('style', '--museum-calendar-kind-color: #2f6f4f');
  expect(await srgb(linked.locator('a'), 'color')).toEqual([255, 255, 255]);
  const plain = colorPage.locator('.museum-calendar li[data-title="日時変更ガイド"]').first();
  await expect(plain).not.toHaveAttribute('style');
  await expect(plain).toHaveCSS('background-color', 'rgb(244, 241, 235)');
  await expect(plain).toHaveCSS('border-left-color', 'rgb(99, 84, 64)');
  await expect(plain).toHaveCSS('color', 'rgb(41, 37, 31)');
  // テーマが設定した変数は表の色より優先し、文字の色は実際に表示する背景色に合わせる。
  await colorPage.addStyleTag({ content: '.museum-calendar li[data-kind="tour"] { --museum-calendar-event-background: rgb(250, 250, 250); --museum-calendar-event-accent: rgb(4, 5, 6); }' });
  await expect(coloredTour).toHaveCSS('background-color', 'rgb(250, 250, 250)');
  await expect(coloredTour).toHaveCSS('border-left-color', 'rgb(4, 5, 6)');
  expect(await srgb(coloredTour, 'color')).toEqual([0, 0, 0]);
  await colorPage.goto('/?pagename=calendar-today&museum_fixture_today=open');
  const plainStatus = colorPage.locator('.museum-calendar-today__status').first();
  await expect(plainStatus).not.toHaveAttribute('style');
  await expect(plainStatus).toHaveCSS('background-color', 'rgb(244, 241, 235)');
  await expect(plainStatus).toHaveCSS('border-left-color', 'rgb(99, 84, 64)');
  await expect(plainStatus).toHaveCSS('color', 'rgb(41, 37, 31)');
  await colorPage.close();

  await field(4, '色').fill('red');
  await save();
  await expect(page.getByText(/予定の種類を保存できませんでした。色は/)).toBeVisible();
  await expect(field(4, '色')).toHaveValue('#1f3a93');

  await field(9, '種類').fill('other');
  await save();
  const failed = page.getByText(/予定の種類を保存できませんでした。種類は英小文字/);
  await expect(failed).toBeVisible();
  await expect(failed).toBeInViewport();
  await expect(field(9, '種類')).toHaveValue('workshop');

  await page.getByLabel('既定の表に戻す', { exact: true }).check();
  await save();
  await savedInView();
  await expect(titles).toHaveCount(11);
  const values = (locator) => locator.evaluateAll((inputs) => inputs.map((input) => input.value));
  expect(await values(titles)).toEqual(['開館日', '休館日', 'お茶室営業日', 'ガイドツアー開催日', '結婚式', '夜間特別開館', 'コンサート', 'イベント', '', '', '']);
  expect(await values(page.locator('input[name$="[kind]"]'))).toEqual(['open', 'closed', 'tea', 'tour', 'wedding', 'evening', 'concert', 'event', '', '', '']);
  expect(await values(page.locator('input[name$="[color]"]'))).toEqual(['#ffebee', '#f5f5f5', '#e8f5e9', '#e3f2fd', '#fff0fc', '#fff9f4', '#f5f5f5', '#f5f5f5', '', '', '']);
});

const monthFromNow = (offset) => {
  const [year, month] = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Tokyo', year: 'numeric', month: '2-digit' }).format(new Date()).split('-').map(Number);
  const first = new Date(Date.UTC(year, month - 1 + offset, 1));
  const name = (options) => first.toLocaleString('en-US', { ...options, timeZone: 'UTC' });
  return { key: first.toISOString().slice(0, 7), name: name({ month: 'long' }), caption: name({ month: 'long', year: 'numeric' }) };
};

test('visitors can move forward up to the month after next', async ({ page }) => {
  await page.goto('/?pagename=calendar-demo');
  const calendar = page.locator('.museum-calendar');
  await expect(calendar.locator('caption')).toHaveText(monthFromNow(0).caption);
  for (const offset of [1, 2]) {
    await calendar.getByRole('link', { name: `${monthFromNow(offset).name} »`, exact: true }).click();
    await expect(calendar.locator('caption')).toHaveText(monthFromNow(offset).caption);
  }
  await expect(calendar.getByRole('link', { name: /»$/ })).toHaveCount(0);
  await expect(calendar.getByRole('link', { name: `« ${monthFromNow(1).name}`, exact: true })).toBeVisible();
  await page.goto(`/?pagename=calendar-demo&museum_month=${monthFromNow(3).key}`);
  await expect(page.getByText('この月の予定はまだ表示できません。', { exact: true })).toBeVisible();
  await expect(page.locator('.museum-calendar table')).toHaveCount(0);
});

test('invalid months are rejected', async ({ page }) => {
  await page.goto('/?pagename=calendar-demo&museum_month=2026-13');
  await expect(page.getByText('年月の指定が正しくありません。')).toBeVisible();
  await expect(page.locator('.museum-calendar table')).toHaveCount(0);
});
