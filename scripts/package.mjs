import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, rmSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = fileURLToPath(new URL('../', import.meta.url));
const source = readFileSync(path.join(root, 'plugins/museum-calendar/museum-calendar.php'), 'utf8');
const version = source.match(/Version:\s*(\d+\.\d+\.\d+)/)?.[1];
if (!version) throw new Error('プラグインヘッダーに Version がありません。');
if (!existsSync(path.join(root, 'plugins/museum-calendar/vendor/autoload.php'))) {
  throw new Error('プラグインの実行用の依存がありません。先に npm run setup を実行してください。');
}
mkdirSync(path.join(root, 'dist'), { recursive: true });
const archive = path.join(root, 'dist', `museum-calendar-${version}.zip`);
rmSync(archive, { force: true });
const result = spawnSync('zip', ['-r', archive, 'museum-calendar', '-x', '*/.DS_Store'], {
  cwd: path.join(root, 'plugins'), stdio: 'inherit',
});
if (result.status !== 0) throw new Error('ZIPを作成できませんでした。zip コマンドを導入してください。');

// 配布ZIPに実行用の依存がそろっていて、開発用のものが入っていないことを確かめる。
const listing = spawnSync('unzip', ['-Z1', archive], { encoding: 'utf8' });
if (listing.status !== 0) throw new Error('ZIPの中身を読めませんでした。unzip コマンドを導入してください。');
const entries = listing.stdout.split('\n').filter(Boolean);
const lock = JSON.parse(readFileSync(path.join(root, 'plugins/museum-calendar/composer.lock'), 'utf8'));
const locked = lock.packages.map((item) => item.name);
const shipped = new Set(entries.map((entry) => entry.match(/^museum-calendar\/vendor\/(?!bin\/|composer\/)([^/]+\/[^/]+)\//)?.[1]).filter(Boolean));
const problems = [
  ...['museum-calendar/museum-calendar.php', 'museum-calendar/uninstall.php', 'museum-calendar/vendor/autoload.php']
    .filter((file) => !entries.includes(file)).map((file) => `${file} がありません。`),
  ...entries.filter((entry) => !entry.startsWith('museum-calendar/')).map((entry) => `プラグインのフォルダーの外にあります: ${entry}`),
  ...entries.filter((entry) => /^museum-calendar\/(?!vendor\/)(?:.*\/)?(?:\.[^/]+|tests?|node_modules|dev)(?:\/|$)/.test(entry))
    .map((entry) => `テストや開発用のファイルです: ${entry}`),
  ...locked.filter((name) => !shipped.has(name)).map((name) => `実行用の依存がありません: ${name}`),
  ...[...shipped].filter((name) => !locked.includes(name)).map((name) => `composer.lock の実行用の依存にないパッケージです: ${name}`),
];
if (problems.length) {
  rmSync(archive, { force: true });
  throw new Error(`配布ZIPの中身が正しくないため、ZIPを削除しました。\n${problems.join('\n')}`);
}
console.log(`ok: 配布ZIPに実行用の依存 ${locked.length} 件があり、開発用のファイルがない`);
console.log(archive);
