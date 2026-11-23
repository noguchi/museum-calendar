import { test, expect } from '@playwright/test';

const samples = [
  { name: 'monochrome', header: 'rgb(12, 12, 12)', open: 'rgb(244, 244, 244)' },
  { name: 'pop', header: 'rgb(255, 77, 141)', open: 'rgb(255, 210, 63)' },
];

for (const { name, header, open } of samples) {
  test(`${name} sample overrides the museum theme colors`, async ({ page }) => {
    // 差し替えテンプレートは曜日属性を付けず、予定の種類をラベルに付ける。
    await page.setContent(`
      <section class="museum-calendar-today"><div class="c-today">
        <a class="c-today__label" data-kind="open">開館日</a>
      </div></section>
      <section class="museum-calendar"><div class="c-cal">
        <nav class="c-cal__header">11月</nav>
        <table class="c-cal__table"><tr><td class="is-empty"></td><td>
          <time>1</time><ul><li><span class="c-calEvent" data-kind="open">開館日</span></li></ul>
        </td></tr></table>
        <dialog open class="c-calDay"><div class="c-today">
          <span class="c-today__label" data-kind="open">開館日</span>
        </div></dialog>
      </div></section>
    `);
    await page.addStyleTag({ path: `plugins/museum-calendar/samples/${name}.css` });
    // テーマの指定が後から来た場合にも、見出しやラベルに元の色を残さない。
    await page.addStyleTag({ content: `
      .c-cal__header, .c-today, .c-calDay .c-today,
      .c-today__label, .c-calDay .c-today__label, .c-calEvent[data-kind="open"] { background: #931313; }
    ` });

    await expect(page.locator('.c-cal__header'), '月の見出しにサンプルの背景色を使う').toHaveCSS('background-color', header);
    for (const panel of await page.locator('.c-today').all()) {
      await expect(panel, '本日の予定と日別詳細の背景にテーマのえんじ色を残さない').not.toHaveCSS('background-color', 'rgb(147, 19, 19)');
    }
    for (const selector of ['.c-calEvent', '.museum-calendar-today .c-today__label', 'dialog .c-today__label']) {
      await expect(page.locator(selector), '月間、本日、日別詳細で開館日の配色をそろえる').toHaveCSS('background-color', open);
    }
    await expect(page.locator('td:has(time)'), '日付のあるマスは無地にする').toHaveCSS('background-image', 'none');
    await expect(page.locator('.is-empty'), '月外のマスには模様を付ける').not.toHaveCSS('background-image', 'none');
  });
}
