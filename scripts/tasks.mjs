import { spawnSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = fileURLToPath(new URL('../', import.meta.url));
process.chdir(root);
mkdirSync('.cache', { recursive: true });
const environment = { ...process.env, WP_ENV_HOME: path.join(root, '.cache/wp-env') };
const binary = path.join(root, 'node_modules/.bin/wp-env');
const testConfig = '.wp-env.test.json';

function run(command, args, extra = {}) {
  const result = spawnSync(command, args, { stdio: 'inherit', env: environment, ...extra });
  if (result.error) throw result.error;
  if (result.status !== 0) throw new Error(`${path.basename(command)} が終了コード ${result.status} で終了しました。`);
}

function dockerReady() {
  const result = spawnSync('docker', ['info'], { stdio: 'ignore' });
  if (result.status !== 0) throw new Error('Dockerに接続できません。Docker Engineが起動していて、sudoなしで `docker info` が通ることを確認してください。');
}

function prepareTestConfig() {
  const config = JSON.parse(readFileSync('.wp-env.json', 'utf8'));
  config.port = 8889;
  config.config.MUSEUM_CALENDAR_TEST_FIXTURES = true;
  config.plugins = [...config.plugins.filter((plugin) => plugin !== './dev/calendar-copy'), './tests/support/ics-fixture'];
  config.themes = ['./tests/support/override-theme'];
  writeFileSync(testConfig, `${JSON.stringify(config, null, 2)}\n`);
}

function wpEnv(args, test = false) {
  run(binary, [...(test ? ['--config', testConfig] : []), ...args]);
}

function stopEnvironment(test = false) {
  const result = spawnSync(binary, [...(test ? ['--config', testConfig] : []), 'stop'], {
    env: environment, encoding: 'utf8',
  });
  if (result.error) throw result.error;
  const output = `${result.stdout ?? ''}${result.stderr ?? ''}`;
  if (result.status !== 0 && !output.includes('Environment not initialized')) {
    throw new Error(output);
  }
  console.log(output.includes('Environment not initialized') ? '未作成の環境は停止不要です。' : output.trim());
}

function php(args, test = false) {
  wpEnv(['run', 'cli', '--env-cwd=workspace', ...args], test);
}

function dependencies(test = false) {
  php(['composer', 'install', '--no-interaction', '--prefer-dist'], test);
  wpEnv(['run', 'cli', '--env-cwd=workspace/plugins/museum-calendar', 'composer', 'install', '--no-dev', '--no-interaction', '--prefer-dist'], test);
}

function seed(test = false) {
  wpEnv(['run', 'cli', 'wp', 'eval-file', '/var/www/html/workspace/scripts/seed.php'], test);
}

function startTest() {
  prepareTestConfig();
  wpEnv(['start'], true);
  dependencies(true);
}

const task = process.argv[2];
try {
  dockerReady();
  switch (task) {
    case 'setup':
      wpEnv(['start']);
      dependencies();
      seed();
      console.log('確認ページ: http://localhost:8888/?pagename=calendar-demo');
      break;
    case 'dev': wpEnv(['start']); break;
    case 'seed': seed(); break;
    case 'site':
      wpEnv(['start']);
      // 実サイトは日本語。開発環境は英語で始まるので切り替える。
      wpEnv(['run', 'cli', 'wp', 'language', 'core', 'install', 'ja', '--activate']);
      wpEnv(['run', 'cli', 'wp', 'eval-file', '/var/www/html/workspace/scripts/site-seed.php']);
      console.log('トップページ: http://localhost:8888/');
      break;
    case 'wp': wpEnv(['run', 'cli', 'wp', ...process.argv.slice(3)]); break;
    case 'stop':
      stopEnvironment();
      prepareTestConfig();
      stopEnvironment(true);
      break;
    case 'unit':
      startTest();
      php(['php', 'vendor/bin/phpunit'], true);
      break;
    case 'integration':
      startTest();
      wpEnv(['run', 'cli', 'wp', 'eval-file', '/var/www/html/workspace/tests/integration/plugin.php'], true);
      wpEnv(['run', 'cli', 'wp', 'eval-file', '/var/www/html/workspace/tests/integration/ics.php'], true);
      break;
    case 'e2e':
      startTest();
      seed(true);
      run(path.join(root, 'node_modules/.bin/playwright'), ['test']);
      break;
    case 'check':
      startTest();
      php(['php', 'scripts/lint.php'], true);
      php(['composer', 'validate', '--strict'], true);
      wpEnv(['run', 'cli', '--env-cwd=workspace/plugins/museum-calendar', 'composer', 'validate', '--strict'], true);
      php(['php', 'vendor/bin/phpunit'], true);
      wpEnv(['run', 'cli', 'wp', 'eval-file', '/var/www/html/workspace/tests/integration/plugin.php'], true);
      wpEnv(['run', 'cli', 'wp', 'eval-file', '/var/www/html/workspace/tests/integration/ics.php'], true);
      seed(true);
      run(path.join(root, 'node_modules/.bin/playwright'), ['test']);
      break;
    case 'audit':
      // 配布ZIPに入る実行用の依存だけを調べる。脆弱性の情報をPackagistから取得するので、check には含めない。
      prepareTestConfig();
      wpEnv(['start'], true);
      wpEnv(['run', 'cli', '--env-cwd=workspace/plugins/museum-calendar', 'composer', 'audit', '--locked', '--no-interaction'], true);
      break;
    default: throw new Error(`不明なタスクです: ${task}`);
  }
} catch (error) {
  console.error(`\n${error.message}`);
  process.exitCode = 1;
}
