<?php
// Private visitor log viewer. Reads data/visits.log.php (written by ../track.php), looks each IP up
// with ip-api.com (free, no key; results cached in data/ipcache.php) and shows the visits as a dashboard:
// totals, how far people got down the page, the visit list and one visit's details.
// Query: ?range=today|7|30|all  ?show=people|bots|all  ?v=<visit id>

session_start();
date_default_timezone_set('America/Jamaica');
$config = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$DATA = __DIR__ . '/data';

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/* ---------- login ---------- */
if (isset($_GET['logout'])) { session_destroy(); header('Location: ./'); exit; }
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['password'])) {
  if (!empty($config['password_hash']) && password_verify((string) $_POST['password'], $config['password_hash'])) {
    session_regenerate_id(true);
    $_SESSION['visits_ok'] = true;
    header('Location: ./'); exit;
  }
  usleep(800000);
  $error = empty($config['password_hash']) ? 'No password is set up yet: add visits/config.php.' : 'Wrong password.';
}
if (empty($_SESSION['visits_ok'])) { page_head('Sign in'); ?>
  <main class="login-wrap">
    <form method="post" class="login">
      <div class="m"><span class="g">$</span> sudo tail -f visits.log</div>
      <h1>Visitor log</h1>
      <label for="pw">Password</label>
      <input id="pw" type="password" name="password" autocomplete="current-password" autofocus required>
      <?php if ($error) echo '<p class="err" role="alert">' . h($error) . '</p>'; ?>
      <button type="submit" class="btn">[ sign in ]</button>
    </form>
  </main>
<?php page_foot(); exit; }

/* ---------- read the log: one visit per id ---------- */
$visits = [];
$file = $DATA . '/visits.log.php';
if (is_file($file)) {
  foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $r = json_decode($line, true);
    if (!is_array($r) || empty($r['id'])) continue;
    $v = $visits[$r['id']] ?? ['sections' => [], 'clicks' => [], 'secs' => 0];
    if ($r['t'] === 'view') $v = $r + $v;
    else {
      $v['sections'] = array_values(array_unique(array_merge($v['sections'], $r['sections'] ?? [])));
      $v['clicks'] = array_values(array_unique(array_merge($v['clicks'], $r['clicks'] ?? [])));
      $v['secs'] = max($v['secs'], (int) ($r['secs'] ?? 0));
    }
    $visits[$r['id']] = $v;
  }
}
$visits = array_filter($visits, function ($v) { return !empty($v['ip']); });
uasort($visits, function ($a, $b) { return $b['at'] <=> $a['at']; });

// What the server sees, for when the list is empty
$health = [
  ['tracker', is_file(dirname(__DIR__) . '/track.php') ? 'track.php found' : 'track.php MISSING next to index.html',
    is_file(dirname(__DIR__) . '/track.php'), 'Upload track.php next to index.html.'],
  ['data folder', !is_dir($DATA) ? 'missing' : (is_writable($DATA) ? 'exists, writable' : 'exists, NOT writable'),
    is_dir($DATA) && is_writable($DATA), is_dir($DATA) ? 'In the host\'s file manager, set visits/data to 755.' : 'Create visits/data and upload visits/data/.htaccess into it.'],
  // A missing log is only a problem when the folder can't take it; otherwise nobody has visited yet
  ['log file', is_file($file) ? number_format(filesize($file)) . ' bytes, ' . max(0, count(file($file)) - 1) . ' records' : 'not created yet (no visits saved)',
    is_file($file) || (is_dir($DATA) && is_writable($DATA)), 'Fills in by itself once the folder above is fixed and someone visits.'],
  ['PHP', PHP_VERSION, true, ''],
];

/* ---------- IP lookups (ip-api.com batch: 100 IPs per call, 15 calls a minute on the free plan) ---------- */
$cacheFile = $DATA . '/ipcache.php'; // line 1 is an exit guard, line 2 the JSON
$cache = is_file($cacheFile) ? (json_decode((string) (file($cacheFile)[1] ?? ''), true) ?: []) : [];
$todo = array_values(array_diff(array_unique(array_column($visits, 'ip')), array_keys($cache)));
$lookupFailed = false;
foreach (array_slice(array_chunk($todo, 100), 0, 3) as $chunk) {
  $fields = 'status,message,query,country,countryCode,regionName,city,isp,org,as,mobile,proxy,hosting';
  $body = json_encode(array_map(function ($ip) { return ['query' => $ip]; }, $chunk));
  $res = http_post('http://ip-api.com/batch?fields=' . $fields, $body);
  $rows = $res ? json_decode($res, true) : null;
  if (!is_array($rows)) { $lookupFailed = true; break; }
  foreach ($rows as $row) if (!empty($row['query'])) $cache[$row['query']] = $row;
}
if ($todo) @file_put_contents($cacheFile, "<?php exit; ?>\n" . json_encode($cache, JSON_HEX_TAG), LOCK_EX);

function http_post($url, $body) {
  if (function_exists('curl_init')) {
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    $out = curl_exec($c);
    curl_close($c);
    return $out === false ? null : $out;
  }
  $out = @file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST', 'timeout' => 8,
    'header' => "Content-Type: application/json\r\n", 'content' => $body]]));
  return $out === false ? null : $out;
}

/* ---------- turning raw fields into something readable ---------- */
const STEPS = ['top' => 'hero', 'reel' => 'showreel', 'work' => 'projects', 'stack' => 'skills', 'play' => 'quest', 'contact' => 'contact'];

function is_bot($v, $geo) {
  if (preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|curl|wget|python|headless|lighthouse|monitor/i', $v['ua'] ?? '')) return true;
  return !empty($geo['hosting']);
}
function device($ua) {
  $os = 'Other';
  foreach (['iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Windows' => 'Windows', 'CrOS' => 'ChromeOS', 'Mac OS X' => 'Mac', 'Linux' => 'Linux'] as $k => $n)
    if (stripos($ua, $k) !== false) { $os = $n; break; }
  $br = '';
  foreach (['Edg/' => 'Edge', 'OPR/' => 'Opera', 'SamsungBrowser' => 'Samsung', 'CriOS' => 'Chrome', 'FxiOS' => 'Firefox', 'Firefox/' => 'Firefox', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari'] as $k => $n)
    if (strpos($ua, $k) !== false) { $br = $n; break; }
  return trim($os . ($br ? ' · ' . $br : ''));
}
function source($v) {
  $ref = $v['ref'] ?? '';
  $host = $ref ? (parse_url($ref, PHP_URL_HOST) ?: '') : '';
  $self = $_SERVER['HTTP_HOST'] ?? '';
  if ($host === '' || $host === $self) return 'direct / unknown';
  return preg_replace('/^(www\.|m\.|l\.|lm\.)/', '', $host);
}
// How far down the page: index into STEPS of the furthest section seen (0 = hero)
function reach($v) {
  $i = 0; $n = 0;
  foreach (array_keys(STEPS) as $id) { if (in_array($id, $v['sections'], true)) $i = $n; $n++; }
  return $i;
}
function dur($s) { return $s <= 0 ? '—' : ($s < 60 ? $s . 's' : floor($s / 60) . 'm ' . ($s % 60) . 's'); }
function network($geo) {
  if (!$geo || ($geo['status'] ?? '') !== 'success') return '';
  $org = trim($geo['org'] ?? ''); $isp = trim($geo['isp'] ?? '');
  return $org && strcasecmp($org, $isp) !== 0 ? $org . ' via ' . $isp : ($isp ?: $org);
}
function place($geo, $short = false) {
  if (!$geo || ($geo['status'] ?? '') !== 'success') return 'lookup pending';
  return $short ? ($geo['city'] ?: $geo['country'] ?? '') : implode(', ', array_filter([$geo['city'] ?? '', $geo['country'] ?? '']));
}
function when($at) {
  $day = date('Y-m-d', $at);
  if ($day === date('Y-m-d')) return 'Today, ' . date('g:ia', $at);
  if ($day === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday, ' . date('g:ia', $at);
  return date('D j M, g:ia', $at);
}
function tally($list, $key) {
  $m = [];
  foreach ($list as $x) { $k = $key($x); if ($k !== '') $m[$k] = ($m[$k] ?? 0) + 1; }
  arsort($m);
  return array_slice($m, 0, 5, true);
}

/* ---------- filters from the URL ---------- */
$ranges = ['today' => 'today', '7' => '7 days', '30' => '30 days', 'all' => 'all time'];
$range = isset($ranges[$_GET['range'] ?? '']) ? $_GET['range'] : '7';
$show = in_array($_GET['show'] ?? '', ['people', 'bots', 'all'], true) ? $_GET['show'] : 'people';
$since = $range === 'today' ? strtotime('today') : ($range === 'all' ? 0 : strtotime('-' . $range . ' days'));
function url_with($changes) {
  global $range, $show;
  $q = array_merge(['range' => $range, 'show' => $show], $changes);
  return '?' . http_build_query(array_filter($q, function ($x) { return $x !== null; }));
}

$ipCount = array_count_values(array_column($visits, 'ip'));
$all = [];
foreach ($visits as $id => $v) {
  if ($v['at'] < $since) continue;
  $geo = $cache[$v['ip']] ?? null;
  $flags = [];
  $bot = is_bot($v, $geo);
  if ($bot) $flags[] = !empty($geo['hosting']) ? 'data centre' : 'bot';
  if (!empty($geo['mobile'])) $flags[] = 'mobile data';
  if (!empty($geo['proxy'])) $flags[] = 'VPN / proxy';
  $returning = !empty($v['back']) || $ipCount[$v['ip']] > 1;
  if ($returning && !$bot) $flags[] = 'returning';
  $all[$id] = $v + ['geo' => $geo, 'bot' => $bot, 'flags' => $flags, 'reach' => reach($v), 'returning' => $returning];
}
$people = array_filter($all, function ($v) { return !$v['bot']; });
$botCount = count($all) - count($people);
$list = $show === 'people' ? $people : ($show === 'bots' ? array_filter($all, function ($v) { return $v['bot']; }) : $all);
$selId = isset($_GET['v'], $all[$_GET['v']]) ? $_GET['v'] : (array_key_first($list) ?? null);
$sel = $selId ? $all[$selId] : null;

$total = count($people);
$funnel = [];
$i = 0;
foreach (STEPS as $label) {
  $n = count(array_filter($people, function ($v) use ($i) { return $v['reach'] >= $i; }));
  $funnel[] = ['label' => $label, 'n' => $n, 'pct' => $total ? max(3, round($n / $total * 100)) : 0];
  $i++;
}
$contact = end($funnel)['n'];
$avg = $total ? (int) round(array_sum(array_column($people, 'secs')) / $total) : 0;
$places = tally($people, function ($v) { return place($v['geo'], true); });
$nets = tally($people, function ($v) { return network($v['geo']); });

page_head('Visitor log'); ?>
<header class="bar">
  <div class="wrap bar-in">
    <a href="./" class="brand"><span class="g">kevando@jamaica</span><span class="m">:</span><span class="b">~/visits</span><span class="m">$</span></a>
    <nav aria-label="Range and account" class="bar-nav">
      <div class="seg">
        <?php foreach ($ranges as $k => $label): ?>
          <a href="<?= h(url_with(['range' => (string) $k, 'v' => null])) ?>"<?= (string) $k === $range ? ' aria-current="true" class="on"' : '' ?>><?= h($label) ?></a>
        <?php endforeach; ?>
      </div>
      <a href="?logout=1" class="out">sign out</a>
    </nav>
  </div>
</header>

<main class="wrap stack">
  <div class="head">
    <div>
      <div class="m"><span class="g">$</span> tail -f visits.log</div>
      <h1>Who's been visiting</h1>
    </div>
    <div class="sub"><?= h(ucfirst($ranges[$range])) ?> · Jamaica time</div>
  </div>

  <?php if ($lookupFailed) echo '<p class="err">Couldn\'t reach ip-api.com for the IP lookups. They\'ll be retried next time you open this page.</p>'; ?>

  <?php if (!$visits): ?>
    <section class="empty">
      <div class="empty-copy">
        <h2>No visits logged yet<span class="a">_</span></h2>
        <?php if (array_filter($health, function ($c) { return !$c[2]; })): ?>
          <p>The server can't save visits yet. Fix the red line, then reload this page.</p>
        <?php else: ?>
          <p>Everything's set up. Visits show up here as soon as someone opens the site.</p>
        <?php endif; ?>
        <a href="./" class="btn">[ check again ]</a>
      </div>
      <?php status_panel($health, true); ?>
    </section>
  <?php else: ?>

  <section aria-label="Totals" class="stats">
    <div class="card"><div class="lbl">Visitors</div><div class="big"><?= $total ?></div><div class="note"><?= $botCount ?> bot<?= $botCount === 1 ? '' : 's' ?> hidden</div></div>
    <div class="card"><div class="lbl">Returning</div><div class="big"><?= count(array_filter($people, function ($v) { return $v['returning']; })) ?></div><div class="note">seen before</div></div>
    <div class="card"><div class="lbl">Reached contact</div><div class="big a"><?= $contact ?></div><div class="note"><?= $total ? round($contact / $total * 100) : 0 ?>% of visitors</div></div>
    <div class="card"><div class="lbl">Avg. time</div><div class="big"><?= h(dur($avg)) ?></div><div class="note">per visit</div></div>
  </section>

  <section aria-labelledby="funnel-h" class="card funnel">
    <div class="row"><h2 id="funnel-h">How far people got</h2><span class="sub">people only, bots left out</span></div>
    <div class="funnel-cols">
      <?php foreach ($funnel as $k => $f): ?>
        <div class="fcol">
          <div class="ftrack"><div class="fbar<?= $k === count($funnel) - 1 ? ' hot' : '' ?>" style="--p: <?= (int) $f['pct'] ?>%"></div></div>
          <div class="flbl"><span><?= h($f['label']) ?></span><strong><?= $f['n'] ?></strong></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <div class="split">
    <section aria-labelledby="list-h" class="list">
      <div class="row">
        <h2 id="list-h">Visits</h2>
        <div class="seg" role="group" aria-label="Show">
          <?php foreach (['people' => 'people (' . $total . ')', 'bots' => 'bots (' . $botCount . ')', 'all' => 'all'] as $k => $label): ?>
            <a href="<?= h(url_with(['show' => $k, 'v' => null])) ?>"<?= $k === $show ? ' aria-current="true" class="on"' : '' ?>><?= h($label) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="tbl">
        <table>
          <thead><tr><th>When</th><th>Where</th><th>Network</th><th>Device</th><th>Got to</th><th class="r">Time</th></tr></thead>
          <tbody>
          <?php if (!$list) echo '<tr><td colspan="6" class="m">No visits in this range.</td></tr>'; ?>
          <?php foreach ($list as $id => $v): $isSel = $id === $selId; $hot = $v['reach'] === count(STEPS) - 1; ?>
            <tr class="<?= trim(($isSel ? 'sel ' : '') . ($v['bot'] ? 'bot' : '')) ?>">
              <td><a class="pick" href="<?= h(url_with(['v' => $id])) ?>#visit"<?= $isSel ? ' aria-current="true"' : '' ?>><?= h(when($v['at'])) ?></a></td>
              <td data-l="where"><?= h(place($v['geo'])) ?></td>
              <td data-l="network"><?= h(network($v['geo'])) ?: '<span class="m">—</span>' ?><?= $v['flags'] ? '<div class="flags">' . h(implode(' · ', $v['flags'])) . '</div>' : '' ?></td>
              <td data-l="device" class="t2"><?= h(device($v['ua'] ?? '')) ?></td>
              <td data-l="got to"><span class="pill<?= $hot ? ' hot' : '' ?>"><?= h(array_values(STEPS)[$v['reach']]) ?></span></td>
              <td data-l="time" class="r t2 nowrap"><?= h(dur($v['secs'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="sub">Select a visit to see its full path. Locations come from the IP address, so they're often the ISP's city. "Came from" is usually unknown on this host, because its security check replaces the real source.</p>
    </section>

    <aside aria-label="Selected visit" class="side">
      <?php if ($sel): $steps = array_values(STEPS); ?>
        <div class="win" id="visit">
          <div class="win-bar"><span class="dot r"></span><span class="dot y"></span><span class="dot gr"></span><span>visit.json</span></div>
          <div class="win-body">
            <div>
              <div class="place"><?= h(place($sel['geo'])) ?></div>
              <div class="t2"><?= h(when($sel['at'])) ?> · <?= h(dur($sel['secs'])) ?> on site</div>
            </div>
            <dl>
              <dt>ip</dt><dd class="b"><?= h($sel['ip']) ?></dd>
              <dt>network</dt><dd><?= h(network($sel['geo']) ?: '—') ?></dd>
              <dt>flags</dt><dd class="a"><?= h($sel['flags'] ? implode(' · ', $sel['flags']) : 'none') ?></dd>
              <dt>device</dt><dd><?= h(device($sel['ua'] ?? '')) ?><?= !empty($sel['scr']) ? ' · ' . h($sel['scr']) : '' ?></dd>
              <dt>language</dt><dd><?= h(trim(($sel['lang'] ?? '') . ' ' . ($sel['tz'] ?? '')) ?: '—') ?></dd>
              <dt>came from</dt><dd><?= h(source($sel)) ?></dd>
              <dt>visits</dt><dd><?= $ipCount[$sel['ip']] > 1 ? $ipCount[$sel['ip']] . ' from this IP' : 'first visit' ?></dd>
            </dl>
            <div class="lbl">Path through the page</div>
            <ol class="path">
              <?php foreach ($steps as $k => $label): $on = !$sel['bot'] && $k <= $sel['reach']; ?>
                <li class="<?= $on ? 'on' : '' ?>"><span class="pdot" aria-hidden="true"></span><?= h($label) ?><?= $on ? '' : ' <span class="vh">(not reached)</span>' ?></li>
              <?php endforeach; ?>
            </ol>
            <div class="lbl">Clicked</div>
            <div class="chips">
              <?php if (!$sel['clicks']) echo '<span class="m">nothing</span>'; ?>
              <?php foreach ($sel['clicks'] as $c) echo '<span class="chip">' . h(str_replace('-', ' ', preg_replace('/^click-/', '', $c))) . '</span>'; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="tops">
        <div class="card"><div class="lbl">Top places</div><?php top_list($places); ?></div>
        <div class="card"><div class="lbl">Top networks</div><?php top_list($nets); ?></div>
      </div>
      <?php status_panel($health, false); ?>
    </aside>
  </div>
  <?php endif; ?>
</main>
<?php page_foot();

function top_list($arr) {
  if (!$arr) { echo '<div class="m">—</div>'; return; }
  foreach ($arr as $k => $n) echo '<div class="top"><span>' . h($k) . '</span><span class="m">' . $n . '</span></div>';
}

function status_panel($health, $open) { ?>
  <details class="win status"<?= $open ? ' open' : '' ?>>
    <summary class="win-bar"><span class="dot r"></span><span class="dot y"></span><span class="dot gr"></span><span>log-status</span></summary>
    <ul>
      <?php foreach ($health as [$label, $value, $ok, $fix]): ?>
        <li>
          <span class="badge <?= $ok ? 'ok' : 'bad' ?>" aria-hidden="true"><?= $ok ? '✓' : '✕' ?></span>
          <div><span class="m"><?= h($label) ?></span> <span class="<?= $ok ? '' : 'badv' ?>"><?= h($value) ?></span><span class="vh"><?= $ok ? ' (ok)' : ' (problem)' ?></span>
          <?php if (!$ok && $fix) echo '<div class="fix">' . h($fix) . '</div>'; ?></div>
        </li>
      <?php endforeach; ?>
    </ul>
  </details>
<?php }

function page_head($title) { ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700;800&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
<style>
  :root { --bg: #0D1117; --deep: #0A0D12; --panel: #151B23; --panel-2: #10151C; --line: #262E38; --line-2: #3A4552;
    --text: #D7DEE7; --hi: #F3F6F9; --t2: #AEB8C4; --muted: #8B96A3; --amber: #FFB547; --green: #7EE787; --blue: #79C0FF; --red: #FF6B6B;
    --sans: 'IBM Plex Sans', -apple-system, 'Segoe UI', sans-serif; }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.5 'JetBrains Mono', ui-monospace, Menlo, monospace; }
  a { color: var(--amber); } a:hover { color: #FFD08A; }
  :focus-visible { outline: 2px solid var(--amber); outline-offset: 2px; border-radius: 4px; }
  h1, h2, p { margin: 0; }
  .g { color: var(--green); } .b { color: var(--blue); } .a { color: var(--amber); } .m { color: var(--muted); } .t2 { color: var(--t2); }
  .r { text-align: right; } .nowrap { white-space: nowrap; }
  .vh { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
  .wrap { max-width: 1320px; margin: 0 auto; padding-left: 24px; padding-right: 24px; }
  .stack { padding-top: 32px; padding-bottom: 64px; display: flex; flex-direction: column; gap: 24px; }
  .row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; }
  h2 { font-size: 15px; font-weight: 700; color: var(--hi); }
  .sub { font-family: var(--sans); font-size: 13px; color: var(--t2); }
  .lbl { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; }
  .card { padding: 18px 20px; border: 1px solid var(--line); border-radius: 10px; background: var(--panel); display: flex; flex-direction: column; gap: 6px; }
  .err { padding: 10px 14px; border-radius: 8px; background: rgba(255, 107, 107, 0.12); color: #FFB3B3; }
  .btn { display: inline-flex; align-items: center; justify-content: center; min-height: 48px; padding: 0 20px; border: 0; border-radius: 6px; background: var(--amber); color: var(--bg); font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; align-self: flex-start; }
  .btn:hover { color: var(--bg); background: #FFC56B; }

  .bar { border-bottom: 1px solid var(--line); background: var(--deep); }
  .bar-in { padding-top: 12px; padding-bottom: 12px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
  .brand { text-decoration: none; font-weight: 700; font-size: 15px; }
  .bar-nav { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
  .seg { display: flex; flex-wrap: wrap; padding: 3px; border: 1px solid var(--line); border-radius: 8px; background: var(--panel-2); }
  .seg a { display: inline-flex; align-items: center; min-height: 38px; padding: 0 14px; border-radius: 6px; color: var(--t2); font-size: 13px; text-decoration: none; }
  .seg a:hover { color: var(--hi); }
  .seg a.on { background: var(--line); color: var(--hi); }
  .out { display: inline-flex; align-items: center; min-height: 44px; padding: 0 10px; font-size: 13px; }

  .head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 12px; }
  .head h1 { font-size: clamp(28px, 4vw, 40px); font-weight: 800; letter-spacing: -0.03em; color: var(--hi); }

  .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
  .big { font-size: 34px; font-weight: 800; color: var(--hi); letter-spacing: -0.03em; }
  .big.a { color: var(--amber); }
  .note { font-family: var(--sans); font-size: 13px; color: var(--t2); }

  .funnel { gap: 14px; }
  .funnel-cols { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 10px; }
  .fcol { display: flex; flex-direction: column; gap: 8px; min-width: 0; }
  .ftrack { height: 96px; display: flex; align-items: flex-end; border-radius: 6px; background: var(--panel-2); overflow: hidden; }
  .fbar { width: 100%; height: var(--p); background: var(--line-2); border-radius: 6px 6px 0 0; }
  .fbar.hot { background: var(--amber); }
  .flbl { display: flex; justify-content: space-between; gap: 6px; font-size: 13px; }
  .flbl span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .flbl strong { color: var(--hi); }

  .split { display: flex; flex-wrap: wrap; gap: 24px; align-items: flex-start; }
  .list { flex: 999 1 640px; min-width: 0; display: flex; flex-direction: column; gap: 12px; }
  .side { flex: 1 1 340px; min-width: 0; display: flex; flex-direction: column; gap: 16px; }

  .tbl { overflow-x: auto; border: 1px solid var(--line); border-radius: 10px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th { padding: 10px 14px; background: var(--panel-2); color: var(--muted); font-weight: 500; text-align: left; }
  td { padding: 8px 14px; border-top: 1px solid var(--line); background: var(--panel); vertical-align: top; }
  tr.bot td { background: #0F1318; }
  tr.sel td { background: #1B2330; }
  tr.sel td:first-child { box-shadow: inset 3px 0 0 var(--amber); }
  td:first-child { padding: 0; }
  .pick { display: block; min-height: 44px; padding: 8px 14px; color: var(--hi); text-decoration: none; white-space: nowrap; }
  .pick:hover { color: var(--amber); }
  .flags { color: var(--amber); font-size: 12px; }
  .pill { display: inline-block; padding: 2px 8px; border-radius: 999px; border: 1px solid var(--line-2); font-size: 12px; white-space: nowrap; }
  .pill.hot { border-color: var(--amber); color: var(--amber); }

  .win { border: 1px solid var(--line-2); border-radius: 10px; background: var(--panel); overflow: hidden; }
  .win-bar { display: flex; align-items: center; gap: 8px; padding: 12px 16px; border-bottom: 1px solid var(--line); background: var(--panel-2); font-size: 13px; color: var(--muted); }
  .win-bar span:last-child { margin-left: 8px; }
  .dot { width: 11px; height: 11px; border-radius: 50%; } .dot.r { background: var(--red); } .dot.y { background: var(--amber); } .dot.gr { background: var(--green); }
  .win-body { padding: 18px; display: flex; flex-direction: column; gap: 14px; }
  .place { font-size: 20px; font-weight: 800; color: var(--hi); letter-spacing: -0.02em; }
  dl { margin: 0; display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 8px 16px; font-size: 13px; }
  dt { color: var(--muted); } dd { margin: 0; overflow-wrap: anywhere; }
  .path { margin: 0; padding: 0; list-style: none; }
  .path li { display: flex; align-items: center; gap: 12px; min-height: 30px; color: var(--muted); }
  .path li.on { color: var(--hi); }
  .pdot { width: 12px; height: 12px; border-radius: 50%; flex: none; border: 2px solid var(--line-2); }
  .path li.on .pdot { background: var(--amber); border-color: var(--amber); }
  .chips { display: flex; flex-wrap: wrap; gap: 6px; }
  .chip { padding: 4px 10px; border-radius: 6px; background: var(--panel-2); border: 1px solid var(--line); font-size: 12px; }
  .tops { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
  .tops .card { padding: 16px; }
  .top { display: flex; justify-content: space-between; gap: 8px; font-size: 13px; }
  .top span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

  .status summary { cursor: pointer; list-style: none; }
  .status summary::-webkit-details-marker { display: none; }
  .status ul { margin: 0; padding: 6px 0; list-style: none; }
  .status li { display: flex; gap: 14px; padding: 10px 18px; border-bottom: 1px solid #1E252E; font-size: 13px; }
  .status li:last-child { border-bottom: 0; }
  .badge { flex: none; width: 22px; height: 22px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; color: var(--bg); font-size: 12px; font-weight: 800; }
  .badge.ok { background: var(--green); } .badge.bad { background: var(--red); }
  .badv { color: #FFB3B3; }
  .fix { font-family: var(--sans); color: var(--t2); margin-top: 2px; }

  .empty { display: flex; flex-wrap: wrap; gap: 48px; align-items: center; padding: 24px 0; }
  .empty-copy { flex: 1 1 320px; display: flex; flex-direction: column; gap: 16px; }
  .empty-copy h2 { font-size: clamp(28px, 4vw, 40px); font-weight: 800; letter-spacing: -0.03em; line-height: 1.05; }
  .empty-copy p { font-family: var(--sans); font-size: 16px; color: var(--t2); max-width: 36ch; }
  .empty .status { flex: 1 1 420px; }

  .login-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px; }
  .login { width: 100%; max-width: 360px; display: flex; flex-direction: column; gap: 12px; }
  .login h1 { font-size: 30px; font-weight: 800; color: var(--hi); letter-spacing: -0.03em; margin-bottom: 8px; }
  .login label { color: var(--t2); font-size: 13px; }
  .login input { min-height: 48px; padding: 0 14px; border-radius: 8px; border: 1px solid var(--line-2); background: var(--panel); color: var(--hi); font: inherit; }
  .login .btn { align-self: stretch; }

  /* Phones: the visit table becomes a list of cards */
  @media (max-width: 640px) {
    .wrap { padding-left: 16px; padding-right: 16px; }
    .funnel-cols { grid-template-columns: 1fr; gap: 8px; }
    .fcol { display: grid; grid-template-columns: 1fr 2fr; align-items: center; gap: 10px; }
    .fcol .flbl { order: -1; }
    .ftrack { height: 10px; align-items: stretch; }
    .fbar { height: 100%; width: var(--p); border-radius: 5px; }
    .tbl { border: 0; overflow: visible; }
    table, tbody, tr, td { display: block; }
    thead { display: none; }
    tr { margin-bottom: 10px; border: 1px solid var(--line); border-radius: 10px; overflow: hidden; }
    td { border-top: 0; padding: 4px 14px; }
    td:last-child { padding-bottom: 12px; }
    td.r { text-align: left; }
    td[data-l]::before { content: attr(data-l) ' '; color: var(--muted); }
    .tops { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>
<?php }

function page_foot() { ?>
</body>
</html>
<?php }
