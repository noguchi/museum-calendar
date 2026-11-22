import { mkdirSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const target = fileURLToPath(new URL('../dev/calendar-copy/calendar.ics', import.meta.url));

function fail(message) {
  console.error(message);
  process.exit(1);
}

// MuseumCalendar\Ics\GoogleCalendar::fromUrl() と同じ形式（iCal形式の非公開URLと公開URL）を受け付け、カレンダーIDと非公開URLの秘密の部分を返す。このスクリプトのメッセージにはどちらも出さない。
function calendar(input) {
  let url;
  try {
    url = new URL(input.trim());
  } catch {
    return null;
  }
  if (url.protocol !== 'https:' || url.hostname !== 'calendar.google.com' || url.port || url.username || url.password) {
    return null;
  }
  const match = url.pathname.match(/^\/calendar\/ical\/([^/]+)\/(?:public|private-([^/]+))\/basic\.ics$/);
  if (!match) return null;
  const id = decodeURIComponent(match[1]);
  let secret = null;
  if (match[2] !== undefined) {
    if (!/^[A-Za-z0-9]{1,128}$/.test(match[2])) return null;
    secret = match[2];
  }
  return id.length <= 254 && /^[A-Za-z0-9._+#-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/.test(id) ? { id, secret } : null;
}

const input = process.argv[2] ?? '';
let source;
try {
  source = calendar(input);
} catch {
  source = null;
}
if (!source) fail('GoogleカレンダーのURLではありません。「iCal形式の非公開URL」か「iCal形式の公開URL」を指定してください。');
const { id, secret } = source;

let body;
try {
  const response = await fetch(`https://calendar.google.com/calendar/ical/${encodeURIComponent(id)}/${secret === null ? 'public' : `private-${secret}`}/basic.ics`, {
    headers: { Accept: 'text/calendar' },
    redirect: 'error',
  });
  body = response.status === 200 ? await response.text() : '';
} catch {
  // fetch() のエラーにはURLが含まれることがあるので、このメッセージだけを出す。
  fail('カレンダーを取得できませんでした。ネットワークを確認してください。');
}
if (!body.startsWith('BEGIN:VCALENDAR')) {
  fail('iCal形式のカレンダーを取得できませんでした。非公開URLがリセットされていないか、公開URLならカレンダーが一般公開され予定の詳細を表示する設定になっているかを確認してください。');
}
const lower = body.toLowerCase();
for (const [needle, reason] of [
  [id.toLowerCase(), 'カレンダーID'],
  [encodeURIComponent(id).toLowerCase(), 'カレンダーID'],
  ...(secret === null ? [] : [[secret.toLowerCase(), '非公開URLの秘密の部分']]),
  ['organizer', '主催者（ORGANIZER）'],
  ['attendee', '参加者（ATTENDEE）'],
  ['mailto:', 'メールアドレス（mailto:）'],
]) {
  if (lower.includes(needle)) fail(`取得したデータに${reason}が含まれるため、書き込みません。`);
}

mkdirSync(path.dirname(target), { recursive: true });
writeFileSync(target, body);
console.log(`${path.relative(process.cwd(), target)}: 予定 ${body.match(/^BEGIN:VEVENT\r?$/gm)?.length ?? 0} 件`);
