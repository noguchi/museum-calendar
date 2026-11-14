import { readFileSync } from 'node:fs';
const config = JSON.parse(readFileSync(new URL('../.wp-env.json', import.meta.url), 'utf8'));
const response = await fetch('https://api.wordpress.org/core/version-check/1.7/', { signal: AbortSignal.timeout(15000) });
if (!response.ok) throw new Error(`WordPress API returned ${response.status}`);
const data = await response.json();
const latest = data.offers.find((offer) => offer.response === 'upgrade');
if (!latest) throw new Error('No stable release was returned.');
console.log(`設定中: ${config.core}\n公開最新版: ${latest.current}\n候補URL: ${latest.download}`);
console.log('このコマンドは確認のみです。設定変更・本体更新・PR作成は行いません。');
