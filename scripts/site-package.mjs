// Builds the two ZIPs that put the copy of the real site's front page on staging: the theme, and a plugin that sets up its pages when activated.
import { spawnSync } from 'node:child_process';
import { cpSync, existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { tmpdir } from 'node:os';
import path from 'node:path';

const root = fileURLToPath(new URL('../', import.meta.url));
const theme = 'otani-museum2026';
const site = path.join(root, '.local/site');
if (!existsSync(path.join(site, 'theme', theme, 'style.css'))) {
  throw new Error('.local/site/theme is missing. See docs/development.md.');
}
const dist = path.join(root, 'dist');
mkdirSync(dist, { recursive: true });

function zip(cwd, folder, archive) {
  rmSync(archive, { force: true });
  const result = spawnSync('zip', ['-rq', archive, folder, '-x', '*/.DS_Store'], { cwd, stdio: 'inherit' });
  if (result.status !== 0) throw new Error('ZIP creation failed. Install the zip utility.');
  console.log(archive);
}

zip(path.join(site, 'theme'), theme, path.join(dist, `site-theme-${theme}.zip`));

const work = mkdtempSync(path.join(tmpdir(), 'museum-calendar-site-'));
const plugin = path.join(work, 'museum-calendar-site-seed');
mkdirSync(plugin);
cpSync(path.join(root, 'scripts/site-seed.php'), path.join(plugin, 'site-seed.php'));
writeFileSync(path.join(plugin, 'museum-calendar-site-seed.php'), `<?php
/**
 * Plugin Name: Museum Calendar Site Seed
 * Description: ステージング用。有効化すると実サイトのトップページの写しのテーマとページを設定する。作成後は無効化してよい。
 * Version: 1.0.0
 */
if (!defined('ABSPATH')) {
    exit;
}
register_activation_hook(__FILE__, static function (): void {
    require_once __DIR__ . '/site-seed.php';
    try {
        museum_calendar_site_seed('${theme}');
    } catch (Throwable $error) {
        wp_die(esc_html($error->getMessage()));
    }
});
`);
zip(work, 'museum-calendar-site-seed', path.join(dist, 'site-seed.zip'));
rmSync(work, { recursive: true });
