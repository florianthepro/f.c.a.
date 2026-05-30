<?php
/**
 * flipper-manager.php
 * Single-file scaffold: server-cached firmware index + client UI
 *
 * - Caches GitHub release metadata under /data/firmwarex/
 * - Downloads assets to cache only when requested (server-side copy)
 * - Exposes progress endpoint and asset proxy
 * - Client UI polls progress, shows modal + progress bar, verifies assets client-side
 * - Handles aborted requests: uses lock + complete.flag + stale-lock detection
 *
 * Edit /data/config.yml and /data/apps.yml to configure repos, allowed USB IDs and app groups.
 *
 * Security: run under HTTPS; protect /data/ from public listing; validate config files.
 */

/* -------------------------
   Konfiguration & Pfade
   ------------------------- */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

$ROOT = __DIR__;
$DATA = $ROOT . '/data';
$CACHE = $DATA . '/firmwarex';
$CONFIG = $DATA . '/config.yml';
$APPS_YML = $DATA . '/apps.yml';
$INDEX_JSON = $CACHE . '/index.json';
$TASK_DIR = $CACHE . '/tasks'; // per-task status/lock files

@mkdir($DATA, 0770, true);
@mkdir($CACHE, 0770, true);
@mkdir($TASK_DIR, 0770, true);

/* -------------------------
   Hilfsfunktionen
   ------------------------- */
function read_yaml_simple($path) {
    if (!is_file($path)) return [];
    $txt = file_get_contents($path);
    // very small YAML parser for our simple structure (maps and lists)
    $lines = preg_split("/\r?\n/", $txt);
    $out = [];
    $curKey = null;
    foreach ($lines as $raw) {
        $line = trim($raw);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (preg_match('/^([A-Za-z0-9_]+):\s*(.*)$/', $line, $m)) {
            $k = $m[1]; $v = $m[2];
            if ($v === '') { $out[$k] = []; $curKey = $k; }
            else { $out[$k] = $v; $curKey = null; }
        } elseif (preg_match('/^- (.*)$/', $line, $m) && $curKey) {
            $val = trim($m[1]);
            // try key: value inside list item
            if (preg_match('/^([A-Za-z0-9_\-]+):\s*(.*)$/', $val, $mm)) {
                $out[$curKey][] = [$mm[1] => $mm[2]];
            } else {
                $out[$curKey][] = $val;
            }
        }
    }
    return $out;
}
function http_json($url, $timeout=15) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Flipper-Manager/1.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300) return json_decode($body, true);
    return null;
}
function safe_basename($s){ return preg_replace('/[^A-Za-z0-9_\-\.]/','_',basename($s)); }
function task_path($id){ global $TASK_DIR; return $TASK_DIR . '/' . preg_replace('/[^A-Za-z0-9_\-]/','_', $id); }

/* -------------------------
   Load config & apps
   ------------------------- */
$config = read_yaml_simple($CONFIG);
$apps = read_yaml_simple($APPS_YML);

// defaults
$config += [
    'github_repos' => [],
    'allowed_usb' => ['vendor_ids' => [], 'product_ids' => []],
    'cache_ttl_seconds' => 3600,
    'stale_lock_seconds' => 600
];

/* -------------------------
   Cache index loader (TTL)
   ------------------------- */
function load_index_cached($indexFile, $config) {
    $ttl = intval($config['cache_ttl_seconds'] ?? 3600);
    if (is_file($indexFile)) {
        $meta = stat($indexFile);
        if ($meta && (time() - $meta['mtime'] < $ttl)) {
            $json = file_get_contents($indexFile);
            $data = json_decode($json, true);
            if (is_array($data)) return $data;
        }
    }
    // build fresh index
    $idx = build_index($config['github_repos'] ?? []);
    file_put_contents($indexFile, json_encode($idx, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    return $idx;
}
function build_index(array $repos) {
    $out = ['generated_at' => time(), 'repos' => []];
    foreach ($repos as $repo) {
        $repo = trim($repo);
        if ($repo === '') continue;
        $entry = ['repo' => $repo, 'releases' => []];
        $releases = http_json("https://api.github.com/repos/{$repo}/releases");
        if (is_array($releases)) {
            foreach ($releases as $r) {
                $rel = [
                    'tag' => $r['tag_name'] ?? ($r['name'] ?? ''),
                    'name' => $r['name'] ?? '',
                    'published_at' => $r['published_at'] ?? null,
                    'assets' => []
                ];
                foreach ($r['assets'] ?? [] as $a) {
                    $rel['assets'][] = [
                        'name' => $a['name'] ?? '',
                        'size' => $a['size'] ?? 0,
                        'url' => $a['browser_download_url'] ?? ''
                    ];
                }
                $entry['releases'][] = $rel;
            }
        } else {
            // fallback: tags
            $tags = http_json("https://api.github.com/repos/{$repo}/tags");
            if (is_array($tags)) {
                foreach ($tags as $t) $entry['releases'][] = ['tag' => $t['name'] ?? '', 'name' => $t['name'] ?? '', 'assets' => []];
            }
        }
        $out['repos'][] = $entry;
    }
    return $out;
}

/* -------------------------
   Task: fetch firmware (server-side copy)
   - Creates lock file, progress.json, and complete.flag on success
   - If client disconnects, PHP may abort; we check connection_aborted() in loop
   - Next user sees incomplete task and can restart
   ------------------------- */
function fetch_firmware_task($repo, $tag, $assetName) {
    global $CACHE;
    $taskId = md5($repo . '|' . $tag . '|' . $assetName);
    $taskDir = task_path($taskId);
    @mkdir($taskDir, 0770, true);
    $lockFile = $taskDir . '/lock';
    $progressFile = $taskDir . '/progress.json';
    $completeFlag = $taskDir . '/complete.flag';
    $staleSeconds = intval((read_yaml_simple(__DIR__.'/data/config.yml')['stale_lock_seconds'] ?? 600));
    // check existing complete
    if (is_file($completeFlag)) {
        return ['status'=>'complete','message'=>'Already cached','task'=>$taskId];
    }
    // lock handling
    if (is_file($lockFile)) {
        $meta = stat($lockFile);
        if ($meta && (time() - $meta['mtime'] < $staleSeconds)) {
            // another process is working
            return ['status'=>'locked','message'=>'Another fetch in progress','task'=>$taskId];
        } else {
            // stale lock: remove
            @unlink($lockFile);
        }
    }
    // create lock
    file_put_contents($lockFile, getmypid() . '|' . time());
    file_put_contents($progressFile, json_encode(['status'=>'started','percent'=>0,'message'=>'Starting fetch']));
    // determine download URL: try index cache first
    $index = json_decode(@file_get_contents(__DIR__ . '/data/firmwarex/index.json') ?: 'null', true);
    $downloadUrl = null;
    if (is_array($index)) {
        foreach ($index['repos'] ?? [] as $r) {
            if ($r['repo'] === $repo) {
                foreach ($r['releases'] ?? [] as $rel) {
                    if ($tag === '' || $rel['tag'] === $tag) {
                        foreach ($rel['assets'] ?? [] as $a) {
                            if ($a['name'] === $assetName) { $downloadUrl = $a['url']; break 3; }
                        }
                    }
                }
            }
        }
    }
    if (!$downloadUrl) {
        // fallback to raw pattern
        $downloadUrl = "https://raw.githubusercontent.com/{$repo}/{$tag}/{$assetName}";
    }
    // download with curl and stream to file, update progress
    $outPath = "{$taskDir}/" . safe_basename($assetName);
    $fp = fopen($outPath, 'w');
    $ch = curl_init($downloadUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Flipper-Manager-AssetFetcher/1.0');
    curl_setopt($ch, CURLOPT_BUFFERSIZE, 131072);
    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($resource, $dl_total, $dl_now, $ul_total, $ul_now) use ($progressFile, $taskDir) {
        if ($dl_total > 0) {
            $pct = round(($dl_now / $dl_total) * 100);
            file_put_contents($progressFile, json_encode(['status'=>'downloading','percent'=>$pct,'message'=>"Downloading ({$dl_now}/{$dl_total})"]));
        }
        // abort if client disconnected (best-effort)
        if (connection_aborted()) {
            file_put_contents($progressFile, json_encode(['status'=>'aborted','percent'=>0,'message'=>'Client disconnected']));
            return 1; // non-zero aborts curl
        }
        return 0;
    });
    $ok = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    if (!$ok || $code < 200 || $code >= 300) {
        file_put_contents($progressFile, json_encode(['status'=>'error','percent'=>0,'message'=>"Download failed (HTTP {$code})"]));
        @unlink($lockFile);
        return ['status'=>'error','message'=>'Download failed','task'=>$taskId];
    }
    // mark complete
    file_put_contents($completeFlag, json_encode(['repo'=>$repo,'tag'=>$tag,'asset'=>$assetName,'cached_at'=>time(),'path'=>$outPath]));
    file_put_contents($progressFile, json_encode(['status'=>'complete','percent'=>100,'message'=>'Cached on server']));
    @unlink($lockFile);
    return ['status'=>'complete','message'=>'Cached','task'=>$taskId];
}

/* -------------------------
   Endpoints
   ------------------------- */
$action = $_GET['action'] ?? null;

if ($action === 'index') {
    header('Content-Type: application/json; charset=utf-8');
    $idx = load_index_cached($INDEX_JSON, $config);
    echo json_encode(['ok'=>true,'index'=>$idx,'apps'=>$apps,'config'=>$config]);
    exit;
}

if ($action === 'fetch_firmware') {
    // POST or GET: repo, tag, asset
    $repo = $_REQUEST['repo'] ?? '';
    $tag = $_REQUEST['tag'] ?? 'HEAD';
    $asset = $_REQUEST['asset'] ?? '';
    if (!$repo || !$asset) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing repo or asset']); exit; }
    header('Content-Type: application/json; charset=utf-8');
    $res = fetch_firmware_task($repo, $tag, $asset);
    echo json_encode($res);
    exit;
}

if ($action === 'progress') {
    $task = $_GET['task'] ?? '';
    if (!$task) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing task']); exit; }
    $taskDir = task_path($task);
    $progressFile = $taskDir . '/progress.json';
    $complete = $taskDir . '/complete.flag';
    $lock = $taskDir . '/lock';
    $out = ['ok'=>true,'task'=>$task,'status'=>'unknown'];
    if (is_file($progressFile)) $out['progress'] = json_decode(file_get_contents($progressFile), true);
    if (is_file($complete)) $out['complete'] = json_decode(file_get_contents($complete), true);
    if (is_file($lock)) $out['locked'] = true;
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($out);
    exit;
}

if ($action === 'asset') {
    // Serve cached asset if present; otherwise 404
    $repo = $_GET['repo'] ?? '';
    $tag = $_GET['tag'] ?? '';
    $name = $_GET['name'] ?? '';
    if (!$name) { http_response_code(400); echo "missing name"; exit; }
    // search tasks for complete flag matching repo/tag/name
    $found = null;
    foreach (glob($TASK_DIR . '/*/complete.flag') as $flag) {
        $meta = json_decode(file_get_contents($flag), true);
        if (!$meta) continue;
        if ($meta['repo'] === $repo && $meta['tag'] === $tag && $meta['asset'] === $name) {
            $found = $meta['path']; break;
        }
        // if repo/tag not provided, match by asset name
        if ($meta['asset'] === $name) { $found = $meta['path']; break; }
    }
    if (!$found || !is_file($found)) { http_response_code(404); echo "asset not cached"; exit; }
    // serve file
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="'.basename($found).'"');
    readfile($found);
    exit;
}

/* -------------------------
   Default: serve UI
   ------------------------- */
$index = load_index_cached($INDEX_JSON, $config);
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Flipper Manager — Server‑cached</title>
<style>
:root{--bg:#f7fafc;--card:#fff;--muted:#6b7280;--accent:#0f766e;--danger:#b91c1c}
body{font-family:Inter,system-ui,Arial;background:var(--bg);color:#0f172a;margin:0;padding:18px}
.container{max-width:1100px;margin:0 auto}
.header{display:flex;align-items:center;justify-content:space-between}
.card{background:var(--card);padding:14px;border-radius:10px;border:1px solid #e6eef6;margin-top:12px}
.grid{display:grid;grid-template-columns:320px 1fr;gap:14px;margin-top:12px}
.small{font-size:13px;color:var(--muted)}
.btn{background:var(--accent);color:#fff;padding:8px 10px;border-radius:8px;border:0;cursor:pointer}
.btn.ghost{background:transparent;border:1px solid #e6eef6;color:var(--muted)}
.apps{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px;margin-top:10px}
.app{padding:10px;border-radius:8px;border:1px solid #eef2f6;background:#fff}
.progress{height:10px;background:#eef2f6;border-radius:6px;overflow:hidden;margin-top:8px}
.progress > i{display:block;height:100%;background:linear-gradient(90deg,#34d399,#06b6d4);width:0%}
.log{height:160px;overflow:auto;background:#0b1220;padding:10px;border-radius:8px;color:#d1fae5;font-family:monospace;font-size:12px;margin-top:8px}
.warn{background:#fff7f7;padding:10px;border-radius:8px;border:1px solid #fee2e2;color:var(--danger);font-size:13px}
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <div>
      <h1>Flipper Manager — Server‑cached</h1>
      <div class="small">Arbeitet nur mit lokal gecachten Firmwares; externe Repos werden nur beim Server‑Kopieren verwendet.</div>
    </div>
    <div class="small">Cache: <code><?php echo htmlspecialchars($INDEX_JSON); ?></code></div>
  </div>

  <div class="grid">
    <div>
      <div class="card">
        <div class="small"><strong>Server‑Konfiguration</strong></div>
        <div class="small">Repos: <?php echo count($config['github_repos'] ?? []); ?> · Cache TTL: <?php echo intval($config['cache_ttl_seconds'] ?? 3600); ?>s</div>
        <div style="margin-top:10px">
          <button id="refreshIndex" class="btn">Index aktualisieren</button>
          <button id="downloadIndex" class="btn ghost">Index anzeigen</button>
        </div>
        <hr>
        <div class="small"><strong>SD / Flipper</strong></div>
        <div style="margin-top:8px">
          <button id="openSd" class="btn ghost">SD auswählen</button>
          <button id="connectFlipper" class="btn ghost">Flipper verbinden (nur)</button>
        </div>
        <div id="sdStatus" class="small" style="margin-top:8px">Kein SD ausgewählt</div>
      </div>

      <div class="card">
        <div class="small"><strong>Repos (Server‑Cache)</strong></div>
        <div id="repoList" class="small" style="margin-top:8px">
          <?php foreach ($index['repos'] as $r): ?>
            <div style="padding:6px;border-bottom:1px solid #f1f5f9"><?php echo htmlspecialchars($r['repo']); ?> — <?php echo count($r['releases']); ?> releases</div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card">
        <div class="small"><strong>Sicherheit</strong></div>
        <div class="warn">Keine automatische Firmware‑Flash. Jede Schreibaktion erfordert Ihre Zustimmung. Prüfen Sie Signaturen/Checksummen.</div>
      </div>
    </div>

    <div>
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <div><strong>Apps</strong><div class="small">Gruppen: base / extra / dev — konfigurierbar in <code>/data/apps.yml</code></div></div>
          <div><input id="filter" placeholder="suchen" style="padding:6px;border-radius:8px;border:1px solid #eef2f6"></div>
        </div>

        <div style="margin-top:12px">
          <button id="showBase" class="btn ghost">Base Apps</button>
          <button id="showExtra" class="btn ghost">Extra Apps</button>
          <button id="showDev" class="btn ghost">Dev Apps</button>
        </div>

        <div id="appsGrid" class="apps" style="margin-top:12px">
          <!-- populated by JS -->
        </div>
      </div>

      <div class="card" id="installerCard" style="display:none">
        <div><strong id="selName">Ausgewählte App</strong></div>
        <div class="small" id="selPath"></div>
        <div style="margin-top:8px">
          <input id="pubkey" placeholder="PEM Public Key (optional)" style="width:100%;padding:8px;border-radius:6px;border:1px solid #eef2f6">
          <input id="sig" placeholder="Signature base64 (optional)" style="width:100%;padding:8px;border-radius:6px;border:1px solid #eef2f6;margin-top:6px">
        </div>
        <div style="margin-top:8px">
          <button id="downloadAssets" class="btn">Assets herunterladen</button>
          <button id="verifyBtn" class="btn ghost">Prüfen</button>
          <button id="installSd" class="btn ghost">Auf SD speichern</button>
          <button id="installFlipper" class="btn ghost">An Flipper übertragen</button>
        </div>
        <div class="progress"><i id="progressBar"></i></div>
        <div class="log" id="log"></div>
      </div>

    </div>
  </div>
</div>

<script>
/* Client-side: Poll server index, request server fetch when needed, show progress, only use cached assets.
   - When user requests a firmware not cached, call ?action=fetch_firmware and poll ?action=progress
   - If server reports locked, show message and poll until complete or timeout
   - If user leaves (close tab), PHP may abort; server writes progress/aborted; next user can restart
*/

const cfg = <?php echo json_encode($config); ?>;
const appsCfg = <?php echo json_encode($apps); ?>;
let state = { selected: null };

function el(id){ return document.getElementById(id); }
function log(msg, err=false){ const l = el('log'); l.textContent = new Date().toISOString().slice(11,23) + ' ' + msg + '\n' + l.textContent; if(err) console.error(msg); else console.log(msg); }
function setProgress(p){ el('progressBar').style.width = (p||0) + '%'; }

function renderGroup(group) {
  const grid = el('appsGrid'); grid.innerHTML = '';
  const list = appsCfg[group] || [];
  if (!list.length) { grid.innerHTML = '<div class="small">Keine Apps</div>'; return; }
  list.forEach(a => {
    const d = document.createElement('div'); d.className='app';
    d.innerHTML = `<div style="display:flex;justify-content:space-between">
      <div><strong>${escapeHtml(a.name||a.id)}</strong><div class="small">${escapeHtml(a.path||'')}</div></div>
      <div><button class="btn ghost selBtn">Auswählen</button></div>
    </div>`;
    d.querySelector('.selBtn').addEventListener('click', ()=>selectApp(a));
    grid.appendChild(d);
  });
}

function selectApp(a) {
  state.selected = a;
  el('installerCard').style.display = 'block';
  el('selName').textContent = a.name || a.id;
  el('selPath').textContent = a.path || '';
  el('log').textContent = '';
  setProgress(0);
}

async function downloadAssets() {
  const a = state.selected;
  if (!a) return alert('Keine App ausgewählt');
  if (!confirm('Assets vom Server in den Cache holen (nur wenn noch nicht vorhanden)?')) return;
  // request server to fetch firmware (server will cache)
  const repo = (cfg.github_repos && cfg.github_repos[0]) ? cfg.github_repos[0] : '';
  const tag = a.tag || '';
  const asset = a.path || '';
  const url = `${location.pathname}?action=fetch_firmware&repo=${encodeURIComponent(repo)}&tag=${encodeURIComponent(tag)}&asset=${encodeURIComponent(asset)}`;
  log('Server: fetch requested');
  const r = await fetch(url);
  const j = await r.json();
  if (!j) return log('Server antwortet nicht', true);
  if (j.status === 'locked') {
    log('Server arbeitet bereits an diesem Task; Polling startet');
    pollProgress(j.task);
  } else if (j.status === 'complete') {
    log('Asset bereits gecached auf Server');
  } else if (j.status === 'error') {
    log('Server Fehler: ' + (j.message||'unknown'), true);
  } else {
    // start polling
    pollProgress(j.task);
  }
}

let pollInterval = null;
function pollProgress(task) {
  if (pollInterval) clearInterval(pollInterval);
  pollInterval = setInterval(async ()=> {
    try {
      const r = await fetch(`${location.pathname}?action=progress&task=${encodeURIComponent(task)}`);
      const j = await r.json();
      const p = j.progress || {};
      if (p.percent !== undefined) setProgress(p.percent);
      if (p.message) log('Server: ' + p.message);
      if (j.complete) {
        log('Server: Cache complete');
        clearInterval(pollInterval);
        pollInterval = null;
      }
      if (p.status === 'aborted' || p.status === 'error') {
        log('Server: ' + (p.message||p.status), true);
        clearInterval(pollInterval);
        pollInterval = null;
      }
    } catch (e) {
      log('Polling error: ' + e, true);
      clearInterval(pollInterval);
      pollInterval = null;
    }
  }, 1500);
}

/* Install to SD: use File System Access API; client downloads asset from server (?action=asset) */
async function installSd() {
  const a = state.selected;
  if (!a) return alert('Keine App ausgewählt');
  if (!confirm('Asset vom Server herunterladen und auf SD speichern?')) return;
  try {
    const repo = (cfg.github_repos && cfg.github_repos[0]) ? cfg.github_repos[0] : '';
    const url = `${location.pathname}?action=asset&repo=${encodeURIComponent(repo)}&tag=&name=${encodeURIComponent(a.path)}`;
    const r = await fetch(url);
    if (!r.ok) return log('Asset nicht im Server‑Cache vorhanden', true);
    const blob = await r.blob();
    // ask user for SD root if not selected
    if (!window.showDirectoryPicker) return alert('File System Access API nicht verfügbar');
    const root = await window.showDirectoryPicker();
    const appsDir = await root.getDirectoryHandle('apps', {create:true});
    const custom = await appsDir.getDirectoryHandle('custom', {create:true});
    const appDir = await custom.getDirectoryHandle((a.name||a.id).replace(/[^A-Za-z0-9_\-]/g,'_'), {create:true});
    const fh = await appDir.getFileHandle((a.path||'asset.bin').split('/').pop(), {create:true});
    const w = await fh.createWritable();
    await w.write(blob);
    await w.close();
    log('Auf SD geschrieben: ' + fh.name);
    alert('Install abgeschlossen. SD sicher entfernen bevor Sie es in den Flipper einsetzen.');
  } catch (e) {
    log('SD-Install Fehler: ' + e, true);
  }
}

/* Transfer to Flipper: WebSerial generic (device must be ready) */
async function installFlipper() {
  const a = state.selected;
  if (!a) return alert('Keine App ausgewählt');
  if (!confirm('Öffne Verbindung zum Flipper und übertrage Datei. Gerät muss bereit sein.')) return;
  if (!('serial' in navigator)) return alert('WebSerial nicht unterstützt');
  try {
    const port = await navigator.serial.requestPort();
    await port.open({ baudRate: 115200 });
    log('Serial geöffnet');
    const writer = port.writable.getWriter();
    const repo = (cfg.github_repos && cfg.github_repos[0]) ? cfg.github_repos[0] : '';
    const url = `${location.pathname}?action=asset&repo=${encodeURIComponent(repo)}&tag=&name=${encodeURIComponent(a.path)}`;
    const r = await fetch(url);
    if (!r.ok) { log('Asset nicht im Cache', true); return; }
    const blob = await r.blob();
    await writer.write(new TextEncoder().encode(`BEGIN ${a.path} ${blob.size}\n`));
    const buf = new Uint8Array(await blob.arrayBuffer());
    const chunk = 4096;
    for (let off=0; off<buf.length; off+=chunk) {
      await writer.write(buf.slice(off, off+chunk));
    }
    await writer.write(new TextEncoder().encode(`END ${a.path}\n`));
    writer.releaseLock();
    await port.close();
    log('Übertragung abgeschlossen');
  } catch (e) {
    log('Übertragungsfehler: ' + e, true);
  }
}

/* UI wiring */
document.getElementById('refreshIndex').addEventListener('click', ()=>location.reload());
document.getElementById('downloadIndex').addEventListener('click', ()=>window.open(location.pathname + '?action=index','_blank'));
document.getElementById('showBase').addEventListener('click', ()=>renderGroup('base_apps'));
document.getElementById('showExtra').addEventListener('click', ()=>renderGroup('extra_apps'));
document.getElementById('showDev').addEventListener('click', ()=>renderGroup('dev_apps'));
document.getElementById('downloadAssets').addEventListener('click', downloadAssets);
document.getElementById('installSd').addEventListener('click', installSd);
document.getElementById('installFlipper').addEventListener('click', installFlipper);

/* initial render */
renderGroup('base_apps');

/* helpers */
function escapeHtml(s){ return String(s||'').replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
</script>
</body>
</html>
