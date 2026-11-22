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
console.log(archive);
