<?php
// Visitor log. script.js posts one "view" when a page opens and a "leave" when the visitor
// goes away (sections reached, clicks, seconds on site). Each is one JSON line in
// visits/data/visits.log.php; visits/index.php reads it back and looks the IPs up. The file starts with
// an exit line, so even a host that ignores .htaccess can't serve its contents.

header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }

$in = json_decode((string) file_get_contents('php://input', false, null, 0, 4096), true);
if (!is_array($in) || !isset($in['t'], $in['id'])) { http_response_code(400); exit; }

$dir = __DIR__ . '/visits/data';
$file = $dir . '/visits.log.php';
// A failed save answers 500 with the reason in X-Track-Error (visible in the browser's Network tab)
function fail($why) { header('X-Track-Error: ' . $why); http_response_code(500); exit; }
if (!is_dir($dir) && !@mkdir($dir, 0755, true)) fail('cannot create visits/data');
if (!is_writable($dir)) fail('visits/data is not writable');
if (is_file($file) && filesize($file) > 20 * 1024 * 1024) { http_response_code(507); exit; } // 20 MB cap
if (!is_file($file) && @file_put_contents($file, "<?php exit; ?>\n", LOCK_EX) === false) fail('cannot create visits.log.php');

function str_in($v, $max = 300) { return is_scalar($v) ? substr(trim((string) $v), 0, $max) : ''; }
function names_in($v) {
  if (!is_array($v)) return [];
  $out = [];
  foreach (array_slice($v, 0, 40) as $n) if (is_string($n) && preg_match('/^[a-z0-9-]{1,40}$/', $n)) $out[] = $n;
  return array_values(array_unique($out));
}
// The visitor's public IP. REMOTE_ADDR, unless the host sits behind a proxy that hands us a private address.
function client_ip() {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  $public = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
  if (!filter_var($ip, FILTER_VALIDATE_IP, $public) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    if (filter_var($first, FILTER_VALIDATE_IP)) $ip = $first;
  }
  return $ip;
}

$row = ['t' => $in['t'] === 'leave' ? 'leave' : 'view', 'id' => preg_replace('/[^a-z0-9]/i', '', str_in($in['id'], 40)), 'at' => time()];
if ($row['id'] === '') { http_response_code(400); exit; }

if ($row['t'] === 'view') {
  $row += [
    'ip' => client_ip(),
    'ua' => str_in($_SERVER['HTTP_USER_AGENT'] ?? '', 400),
    'lang' => str_in(explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')[0], 20),
    'ref' => str_in($in['ref'] ?? ''),
    'path' => str_in($in['path'] ?? '', 200),
    'tz' => str_in($in['tz'] ?? '', 60),
    'scr' => str_in($in['scr'] ?? '', 20),
    'back' => !empty($in['back']),
  ];
} else {
  $row += [
    'sections' => names_in($in['sections'] ?? []),
    'clicks' => names_in($in['clicks'] ?? []),
    'secs' => max(0, min(86400, (int) ($in['secs'] ?? 0))),
  ];
}

// JSON_HEX_TAG writes < and > as < / >, so visitor-supplied text can never put PHP into the file
if (file_put_contents($file, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . "\n", FILE_APPEND | LOCK_EX) === false) fail('cannot write visits.log.php');
http_response_code(204);
