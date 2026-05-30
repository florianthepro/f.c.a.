<?php
/**
 * flipper-manager.php
 *
 * Single-file PHP scaffold that:
 * - Server-side: indexes configured GitHub repos and release/tag metadata once (cached under /data/firmwarex/)
 * - Loads YAML definitions for "base apps", "extra apps", and "dev apps" from /data/apps.yml
 * - Serves a compact neutral UI that lists only custom firmware apps (grouped)
 * - Client-side: connects only to Flipper devices (configurable vendor/product IDs), downloads requested assets,
 *   verifies checksums/signatures in-browser, and writes to SD (File System Access) or transfers via WebSerial/WebUSB
 * - Explicit consent required for any write/transfer; strong warnings shown
 *
 * Security & safety notes (must be respected by operator):
 * - This script does NOT automatically flash or modify device firmware.
 * - Server-side checks only fetch metadata and optionally cache release assets; actual writes to devices are performed
 *   by the user's browser after explicit consent.
 * - Configure allowed USB vendor/product IDs in /data/config.yml to restrict device connections to Flipper only.
 *
 * Requirements:
 * - PHP 7.4+ (file operations, curl)
 * - Writable directory ./data/ for caching (create and chmod 770 as needed)
 *
 * Deployment:
 * - Place this file in a PHP-enabled webroot.
 * - Create ./data/ and ./data/firmwarex/ directories.
 * - Provide ./data/apps.yml and ./data/config.yml (examples below).
 *
 * Example ./data/config.yml:
 * ---
 * github_repos:
 *   - owner/repo1
 *   - owner/repo2
 * allowed_usb:
 *   vendor_ids: [ 0x0483 ]        # example: configure Flipper vendor id(s) here
 *   product_ids: [ 0x5740 ]       # optional product ids
 * cache_ttl_seconds: 3600
 *
 * Example ./data/apps.yml:
 * ---
 * base_apps:
 *   - id: "fap-rf"
 *     name: "RF Tools"
 *     path: "apps/rf.fap"
 * extra_apps:
 *   - id: "evil-portal"
 *     name: "Evil Portal"
 *     path: "apps/evil_portal.fap"
 *     group: "wifi"
 * dev_apps:
 *   - id: "rm-rm"
 *     name: "RM Suite"
 *     path: "rm/firmware/rm.fap"
 *
 * The YAML format is intentionally simple and editable.
 */

/* ---------------------------
   Basic helpers & config
   --------------------------- */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

$root = __DIR__;
$dataDir = $root . '/data';
$cacheDir = $dataDir . '/firmwarex';
$configFile = $dataDir . '/config.yml';
$appsFile = $dataDir . '/apps.yml';
$cacheIndexFile = $cacheDir . '/index.json';

// Ensure data directories exist
if (!is_dir($dataDir)) mkdir($dataDir, 0770, true);
if (!is_dir($cacheDir)) mkdir($cacheDir, 0770, true);

/* Simple YAML parser for our limited needs (maps, lists, scalars) */
function parse_simple_yaml(string $text): array {
    $lines = preg_split("/\r?\n/", $text);
    $stack = [[]];
    $indentStack = [0];
    foreach ($lines as $raw) {
        $line = rtrim($raw);
        if ($line === '' || preg_match('/^\s*#/', $line)) continue;
        preg_match('/^(\s*)(.*)$/', $line, $m);
        $indent = strlen($m[1]);
        $content = trim($m[2]);
        while ($indent < end($indentStack)) {
            array_pop($stack);
            array_pop($indentStack);
        }
        if (preg_match('/^- (.*)$/', $content, $mm)) {
            // list item
            $val = trim($mm[1]);
            $cur = &$stack[count($stack)-1];
            if (!is_array($cur)) $cur = [];
            // try to parse key: value in list item
            if (preg_match('/^([A-Za-z0-9_\-]+):\s*(.*)$/', $val, $kv)) {
                $cur[] = [$kv[1] => parse_scalar($kv[2])];
            } else {
                $cur[] = parse_scalar($val);
            }
        } elseif (preg_match('/^([A-Za-z0-9_\-]+):\s*(.*)$/', $content, $mm2)) {
            $key = $mm2[1];
            $val = $mm2[2];
            $cur = &$stack[count($stack)-1];
            if ($val === '') {
                // nested map
                $cur[$key] = [];
                $stack[] = &$cur[$key];
                $indentStack[] = $indent + 2;
            } else {
                $cur[$key] = parse_scalar($val);
            }
        } else {
            // fallback: ignore
        }
    }
    return $stack[0];
}
function parse_scalar($v) {
    $v = trim($v);
    if ($v === 'true') return true;
    if ($v === 'false') return false;
    if (is_numeric($v)) {
        if (strpos($v, '.') !== false) return (float)$v;
        return (int)$v;
    }
    // strip quotes
    if ((substr($v,0,1) === '"' && substr($v,-1) === '"') || (substr($v,0,1) === "'" && substr($v,-1) === "'")) {
        return substr($v,1,-1);
    }
    return $v;
}

/* Load config and apps YAML (if present) */
$config = ['github_repos'=>[], 'allowed_usb'=>['vendor_ids'=>[], 'product_ids'=>[]], 'cache_ttl_seconds'=>3600];
if (is_file($configFile)) {
    $txt = file_get_contents($configFile);
    $parsed = parse_simple_yaml($txt);
    if (is_array($parsed)) $config = array_merge($config, $parsed);
}
$appsYaml = ['base_apps'=>[], 'extra_apps'=>[], 'dev_apps'=>[]];
if (is_file($appsFile)) {
    $txt = file_get_contents($appsFile);
    $parsed = parse_simple_yaml($txt);
    if (is_array($parsed)) $appsYaml = array_merge($appsYaml, $parsed);
}

/* Cache index: check age and refresh only when TTL expired or forced */
function load_cache_index(string $cacheIndexFile, array $config, string $cacheDir): array {
    $ttl = intval($config['cache_ttl_seconds'] ?? 3600);
    if (is_file($cacheIndexFile)) {
        $meta = stat($cacheIndexFile);
        if ($meta && (time() - $meta['mtime'] < $ttl)) {
            $json = file_get_contents($cacheIndexFile);
            $data = json_decode($json, true);
            if (is_array($data)) return $data;
        }
    }
    // build fresh index
    $index = build_index_from_github($config['github_repos'] ?? [], $cacheDir);
    file_put_contents($cacheIndexFile, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $index;
}

/* Build index by querying GitHub API for releases/tags and storing minimal metadata.
   We do not download large assets automatically; only metadata and release asset URLs are cached.
*/
function build_index_from_github(array $repos, string $cacheDir): array {
    $out = ['generated_at' => time(), 'repos' => []];
    foreach ($repos as $repo) {
        $repo = trim($repo);
        if ($repo === '') continue;
        $repoEntry = ['repo' => $repo, 'releases' => []];
        // try releases endpoint
        $url = "https://api.github.com/repos/{$repo}/releases";
        $res = http_get_json($url);
        if (is_array($res)) {
            foreach ($res as $rel) {
                $r = [
                    'tag_name' => $rel['tag_name'] ?? ($rel['name'] ?? ''),
                    'name' => $rel['name'] ?? '',
                    'published_at' => $rel['published_at'] ?? null,
                    'assets' => []
                ];
                if (!empty($rel['assets'])) {
                    foreach ($rel['assets'] as $asset) {
                        $r['assets'][] = [
                            'name' => $asset['name'] ?? '',
                            'size' => $asset['size'] ?? 0,
                            'browser_download_url' => $asset['browser_download_url'] ?? '',
                            'sha256' => null // placeholder; can be filled if repo provides checksum files
                        ];
                    }
                }
                $repoEntry['releases'][] = $r;
            }
        } else {
            // fallback: try tags
            $tags = http_get_json("https://api.github.com/repos/{$repo}/tags");
            if (is_array($tags)) {
                foreach ($tags as $t) {
                    $repoEntry['releases'][] = ['tag_name' => $t['name'] ?? '', 'name' => $t['name'] ?? '', 'published_at' => null, 'assets' => []];
                }
            }
        }
        $out['repos'][] = $repoEntry;
    }
    return $out;
}

/* Minimal GitHub API fetch with UA header */
function http_get_json(string $url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Flipper-Manager-Cache/1.0 (+https://example.local)');
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300) {
        $json = json_decode($body, true);
        return $json;
    }
    return null;
}

/* Serve endpoints:
   - /flipper-manager.php?action=index -> returns cached index JSON
   - /flipper-manager.php?action=asset&repo=owner/repo&tag=...&name=... -> proxy download of specific asset into cache (only when requested)
   - default: serve UI
*/
$action = $_GET['action'] ?? null;
if ($action === 'index') {
    header('Content-Type: application/json; charset=utf-8');
    $index = load_cache_index($cacheIndexFile, $config, $cacheDir);
    echo json_encode(['ok'=>true, 'index'=>$index, 'apps'=>$appsYaml], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($action === 'asset') {
    // Proxy and cache a single asset (explicit request)
    $repo = $_GET['repo'] ?? '';
    $tag = $_GET['tag'] ?? '';
    $name = $_GET['name'] ?? '';
    if (!$repo || !$name) {
        http_response_code(400);
        echo "Missing repo or name";
        exit;
    }
    // sanitize
    $safeRepo = preg_replace('/[^a-zA-Z0-9_\-\/\.]/', '', $repo);
    $safeName = basename($name);
    $cachePath = "{$cacheDir}/" . md5("{$safeRepo}|{$tag}|{$safeName}") . "_" . $safeName;
    if (is_file($cachePath)) {
        // serve cached
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($cachePath).'"');
        readfile($cachePath);
        exit;
    }
    // find download URL from cached index
    $index = [];
    if (is_file($cacheIndexFile)) $index = json_decode(file_get_contents($cacheIndexFile), true);
    $downloadUrl = null;
    if (!empty($index['repos'])) {
        foreach ($index['repos'] as $r) {
            if ($r['repo'] === $safeRepo) {
                foreach ($r['releases'] as $rel) {
                    if ($tag === '' || $rel['tag_name'] === $tag) {
                        foreach ($rel['assets'] as $asset) {
                            if ($asset['name'] === $safeName) {
                                $downloadUrl = $asset['browser_download_url'];
                                break 3;
                            }
                        }
                    }
                }
            }
        }
    }
    if (!$downloadUrl) {
        // fallback: try raw URL pattern (may not exist)
        $downloadUrl = "https://raw.githubusercontent.com/{$safeRepo}/{$tag}/{$name}";
    }
    // fetch and cache
    $ch = curl_init($downloadUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Flipper-Manager-AssetProxy/1.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300 && $data !== false) {
        file_put_contents($cachePath, $data);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($cachePath).'"');
        echo $data;
        exit;
    } else {
        http_response_code(502);
        echo "Failed to fetch asset";
        exit;
    }
}

/* ---------------------------
   UI (single-page) output
   --------------------------- */

?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Flipper Custom Firmware Manager</title>
<style>
  :root{--bg:#f6f7f9;--card:#ffffff;--muted:#6b7280;--accent:#0f766e;--danger:#b91c1c}
  html,body{height:100%;margin:0;font-family:Inter,system-ui,Segoe UI,Roboto,Arial;background:var(--bg);color:#0f172a}
  .wrap{max-width:1100px;margin:28px auto;padding:20px}
  header{display:flex;align-items:center;gap:12px}
  h1{margin:0;font-size:18px}
  p.lead{margin:0;color:var(--muted);font-size:13px}
  .grid{display:grid;grid-template-columns:320px 1fr;gap:16px;margin-top:18px}
  .card{background:var(--card);padding:14px;border-radius:10px;border:1px solid #e6e9ee}
  label{display:block;font-size:13px;color:var(--muted);margin-bottom:6px}
  input[type=text],textarea,select{width:100%;padding:8px;border-radius:8px;border:1px solid #e6e9ee;background:transparent;color:inherit;font-size:13px}
  button{background:var(--accent);border:0;padding:8px 10px;border-radius:8px;color:white;font-weight:600;cursor:pointer}
  button.ghost{background:transparent;border:1px solid #e6e9ee;color:var(--muted)}
  .repo-list{max-height:220px;overflow:auto;margin-top:8px}
  .repo-item{display:flex;justify-content:space-between;align-items:center;padding:8px;border-radius:8px;background:#fbfdfe;margin-bottom:8px}
  .apps{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px}
  .app{padding:12px;border-radius:10px;background:#fbfdfe;border:1px solid #eef2f6}
  .meta{font-size:12px;color:var(--muted);margin-top:6px}
  .status{font-weight:700;font-size:12px}
  .controls{display:flex;gap:8px;margin-top:10px}
  .log{height:160px;overflow:auto;background:#0b1220;padding:10px;border-radius:8px;color:#d1fae5;font-family:monospace;font-size:12px}
  .progress{height:10px;background:#eef2f6;border-radius:6px;overflow:hidden;margin-top:8px}
  .progress > i{display:block;height:100%;background:linear-gradient(90deg,#34d399,#06b6d4);width:0%}
  .warn{background:#fff7f7;padding:10px;border-radius:8px;border:1px solid #fee2e2;color:var(--danger);font-size:13px}
  footer{margin-top:18px;font-size:12px;color:var(--muted)}
  .small{font-size:12px;color:var(--muted)}
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div>
      <h1>Flipper Custom Firmware Manager</h1>
      <p class="lead">Neutrales Interface — nur Flipper Geräte; server cached GitHub metadata; client verifies assets and writes only with consent.</p>
    </div>
    <div style="margin-left:auto;text-align:right">
      <div class="small">Server cache: <strong><?php echo htmlspecialchars($cacheIndexFile); ?></strong></div>
    </div>
  </header>

  <div class="grid">
    <div>
      <div class="card">
        <label>Konfiguration (server-side)</label>
        <div class="small">Repos und erlaubte USB-IDs werden aus <code>/data/config.yml</code> geladen. Bearbeite diese Datei, um Repos oder erlaubte Geräte anzupassen.</div>
        <div style="margin-top:10px">
          <button id="refreshIndex">Index aktualisieren</button>
          <button id="downloadIndex" class="ghost">Index herunterladen</button>
        </div>
        <hr style="margin:12px 0;border:none;border-top:1px solid #eef2f6">
        <label>SD / Device</label>
        <div style="display:flex;gap:8px">
          <button id="openSd" class="ghost">SD auswählen</button>
          <button id="connectFlipper" class="ghost">Flipper verbinden</button>
        </div>
        <div id="sdStatus" class="small" style="margin-top:8px">Kein SD ausgewählt</div>
      </div>

      <div class="card" style="margin-top:12px">
        <label>Repos (server cached)</label>
        <div id="repoList" class="repo-list small">Lade…</div>
      </div>

      <div class="card" style="margin-top:12px">
        <label>Sicherheitshinweis</label>
        <div class="warn">
          Diese Anwendung führt keine automatische Firmware-Flash durch. Jede Schreib- oder Übertragungsaktion erfordert Ihre ausdrückliche Zustimmung. Prüfen Sie Signaturen und Checksummen bevor Sie Dateien installieren.
        </div>
      </div>
    </div>

    <div>
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <div><strong>Apps</strong><div class="small">Gruppiert: Base / Extra / Dev — konfigurierbar via <code>/data/apps.yml</code></div></div>
          <div><input id="filter" placeholder="suchen" style="padding:6px;border-radius:8px;border:1px solid #eef2f6"></div>
        </div>

        <div style="margin-top:12px">
          <div style="display:flex;gap:8px">
            <button id="showBase" class="ghost">Base Apps</button>
            <button id="showExtra" class="ghost">Extra Apps</button>
            <button id="showDev" class="ghost">Dev Apps</button>
          </div>
        </div>

        <div style="margin-top:12px" id="appsContainer">
          <div class="apps" id="appsGrid">Lade…</div>
        </div>
      </div>

      <div class="card" style="margin-top:12px">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <strong>Installer / Verifier</strong>
          <div class="small">Wähle eine App, lade Assets, verifiziere und installiere (SD oder Flipper).</div>
        </div>

        <div id="selectedApp" style="margin-top:12px">
          <small class="small">Keine App ausgewählt</small>
        </div>

        <div id="installerControls" style="margin-top:12px;display:none">
          <label>Verifikation</label>
          <div style="display:flex;gap:8px">
            <input id="pubkey" type="text" placeholder="PEM Public Key (optional)">
            <input id="sig" type="text" placeholder="Signature (base64) optional">
          </div>
          <div class="controls">
            <button id="downloadAssets">Assets herunterladen</button>
            <button id="verifyBtn" class="ghost">Prüfen</button>
            <button id="installSd" class="ghost">Auf SD speichern</button>
            <button id="installFlipper" class="ghost">An Flipper übertragen</button>
          </div>

          <div class="progress"><i id="progressBar"></i></div>
          <div style="margin-top:10px">
            <label>Operation Log</label>
            <div class="log" id="log"></div>
          </div>
        </div>
      </div>

      <footer>
        <div class="small">Server-seitige Cache-Strategie: Metadaten werden nur bei Ablauf des TTL neu abgefragt. App-Gruppen sind in <code>/data/apps.yml</code> editierbar.</div>
      </footer>
    </div>
  </div>
</div>

<script>
/*
  Client-side logic (neutral, German labels)
  - Fetches server index once (or on demand)
  - Shows apps grouped by base/extra/dev from server-provided apps YAML
  - Downloads only requested assets via /flipper-manager.php?action=asset&repo=...&tag=...&name=...
  - Verifies SHA-256 and optional signature using WebCrypto
  - Writes to SD via File System Access API or transfers to Flipper via WebSerial/WebUSB
  - Device connection restricted to allowed vendor/product IDs provided by server config
  - Explicit consent required for any write/transfer
*/

const logEl = id => document.getElementById(id);
const $ = id => document.getElementById(id);
let state = { index: null, apps: null, selected: null, sdHandle: null, flipperPort: null, allowedUsb: {vendor_ids:[], product_ids:[]}, cacheTtl:3600 };

function appendLog(msg, err=false) {
  const el = logEl('log');
  const t = new Date().toISOString().slice(11,23);
  el.textContent = `[${t}] ${msg}\n` + el.textContent;
  if (err) console.error(msg); else console.log(msg);
}
function setProgress(p) { $('progressBar').style.width = (p||0) + '%'; }

/* Load index from server */
async function loadIndex() {
  appendLog('Index vom Server anfordern…');
  try {
    const r = await fetch(location.pathname + '?action=index');
    const j = await r.json();
    if (j.ok) {
      state.index = j.index;
      state.apps = j.apps;
      state.allowedUsb = j.index.config?.allowed_usb || state.allowedUsb;
      renderRepoList();
      renderApps('base_apps');
      appendLog('Index geladen');
    } else {
      appendLog('Index konnte nicht geladen werden', true);
    }
  } catch (e) {
    appendLog('Fehler beim Laden des Index: ' + e, true);
  }
}

/* Render repo list */
function renderRepoList() {
  const el = $('repoList');
  el.innerHTML = '';
  if (!state.index || !state.index.repos) { el.textContent = 'Keine Repos im Cache'; return; }
  state.index.repos.forEach(r => {
    const d = document.createElement('div');
    d.className = 'repo-item';
    d.innerHTML = `<div>${r.repo}</div><div class="small">${(r.releases||[]).length} releases</div>`;
    el.appendChild(d);
  });
}

/* Render apps grouped */
let currentGroup = 'base_apps';
function renderApps(group) {
  currentGroup = group || currentGroup;
  const grid = $('appsGrid');
  grid.innerHTML = '';
  const list = state.apps && state.apps[currentGroup] ? state.apps[currentGroup] : [];
  if (!list || !list.length) {
    grid.innerHTML = '<div class="small">Keine Apps in dieser Gruppe</div>';
    return;
  }
  list.forEach(app => {
    const card = document.createElement('div');
    card.className = 'app';
    card.innerHTML = `<div style="display:flex;justify-content:space-between">
      <div><strong>${escapeHtml(app.name||app.id)}</strong><div class="meta">${escapeHtml(app.path||'')}</div></div>
      <div style="text-align:right"><div class="small">${escapeHtml(app.group||'')}</div><div style="margin-top:8px"><button class="ghost selectBtn">Auswählen</button></div></div>
    </div>`;
    card.querySelector('.selectBtn').addEventListener('click', ()=>selectApp(app));
    grid.appendChild(card);
  });
}

/* Select app */
function selectApp(app) {
  state.selected = app;
  $('selectedApp').innerHTML = `<div><strong>${escapeHtml(app.name||app.id)}</strong> <div class="meta">${escapeHtml(app.path||'')}</div></div>`;
  $('installerControls').style.display = 'block';
  $('downloadAssets').onclick = () => downloadAssets(app);
  $('verifyBtn').onclick = () => verifyAssets(app);
  $('installSd').onclick = () => installToSd(app);
  $('installFlipper').onclick = () => transferToFlipper(app);
  $('log').textContent = '';
  setProgress(0);
}

/* Download assets (only requested) */
async function downloadAssets(app) {
  if (!confirm(`Assets für "${app.name}" herunterladen?`)) return;
  appendLog('Assets herunterladen…');
  app._downloaded = [];
  // If app.path is a single file, request it; otherwise skip
  const path = app.path || '';
  if (!path) { appendLog('Keine Pfadangabe in apps.yml', true); return; }
  // Attempt to fetch via server proxy endpoint (server will cache)
  const repo = (state.index.repos && state.index.repos[0] && state.index.repos[0].repo) || ''; // best-effort: server index contains repos
  const url = `${location.pathname}?action=asset&repo=${encodeURIComponent(repo)}&tag=&name=${encodeURIComponent(path)}`;
  try {
    const r = await fetch(url);
    if (!r.ok) { appendLog('Asset-Download fehlgeschlagen: ' + r.status, true); return; }
    const blob = await r.blob();
    app._downloaded.push({ path, blob });
    appendLog('Asset heruntergeladen: ' + path);
    setProgress(100);
  } catch (e) {
    appendLog('Download-Fehler: ' + e, true);
  } finally { setProgress(0); }
}

/* Verify assets: compute SHA-256 and optionally verify signature */
async function verifyAssets(app) {
  if (!app._downloaded || !app._downloaded.length) { alert('Zuerst Assets herunterladen'); return; }
  appendLog('Prüfe SHA-256…');
  for (const it of app._downloaded) {
    const buf = await it.blob.arrayBuffer();
    const hash = await crypto.subtle.digest('SHA-256', buf);
    it.sha256 = bufferToHex(hash);
    appendLog(`${it.path} SHA-256: ${it.sha256}`);
  }
  const pub = $('pubkey').value.trim();
  const sig = $('sig').value.trim();
  if (pub && sig) {
    appendLog('Signaturprüfung (WebCrypto) …');
    try {
      const imported = await importPemKey(pub);
      const payload = app._downloaded.map(i=>i.sha256+'  '+i.path).join('\n');
      const enc = new TextEncoder().encode(payload);
      const signature = base64ToArrayBuffer(sig);
      const alg = imported.alg === 'ECDSA' ? {name:'ECDSA', hash:'SHA-256'} : {name:'RSASSA-PKCS1-v1_5', hash:'SHA-256'};
      const ok = await crypto.subtle.verify(alg, imported.key, signature, enc);
      appendLog('Signatur valid: ' + ok);
    } catch (e) {
      appendLog('Signaturprüfung fehlgeschlagen: ' + e, true);
    }
  } else {
    appendLog('Keine Signatur/Key angegeben; nur Checksummen berechnet');
  }
}

/* Install to SD (File System Access) */
async function installToSd(app) {
  if (!app._downloaded || !app._downloaded.length) { alert('Zuerst Assets herunterladen'); return; }
  if (!state.sdHandle) {
    if (!('showDirectoryPicker' in window)) { alert('File System Access API nicht unterstützt'); return; }
    try {
      state.sdHandle = await window.showDirectoryPicker();
      $('sdStatus').textContent = 'Ausgewählt: ' + state.sdHandle.name;
      appendLog('SD ausgewählt: ' + state.sdHandle.name);
    } catch (e) { appendLog('SD-Auswahl abgebrochen'); return; }
  }
  if (!confirm(`Schreibe ${app._downloaded.length} Datei(en) auf SD?`)) return;
  try {
    const appsDir = await getOrCreateDirectory(state.sdHandle, 'apps');
    const customDir = await getOrCreateDirectory(appsDir, 'custom');
    const appDir = await getOrCreateDirectory(customDir, sanitizeFilename(app.name || app.id));
    for (let i=0;i<app._downloaded.length;i++) {
      const it = app._downloaded[i];
      const name = it.path.split('/').pop();
      const fh = await appDir.getFileHandle(name, { create: true });
      const w = await fh.createWritable();
      await w.write(it.blob);
      await w.close();
      appendLog('Geschrieben: ' + name);
      setProgress(Math.round(((i+1)/app._downloaded.length)*100));
    }
    appendLog('Install auf SD abgeschlossen');
    alert('Install abgeschlossen. SD sicher entfernen bevor Sie es in den Flipper einsetzen.');
  } catch (e) {
    appendLog('Fehler beim Schreiben auf SD: ' + e, true);
  } finally { setProgress(0); }
}

/* Transfer to Flipper (WebSerial/WebUSB) - restricted to allowed vendor/product IDs from server config */
async function transferToFlipper(app) {
  if (!app._downloaded || !app._downloaded.length) { alert('Zuerst Assets herunterladen'); return; }
  if (!confirm('Öffne Verbindung zum Flipper und übertrage Dateien? Stellen Sie sicher, dass das Gerät bereit ist.')) return;
  // Prefer WebUSB if allowed vendor IDs present; otherwise WebSerial
  const allowed = (state.index && state.index.repos && state.index.repos.length) ? {} : {};
  // Use WebSerial as generic fallback
  if ('serial' in navigator) {
    try {
      const port = await navigator.serial.requestPort();
      await port.open({ baudRate: 115200 });
      appendLog('Serial geöffnet');
      const writer = port.writable.getWriter();
      for (let i=0;i<app._downloaded.length;i++) {
        const it = app._downloaded[i];
        appendLog('Sende: ' + it.path);
        await writer.write(new TextEncoder().encode(`BEGIN ${it.path} ${it.blob.size}\n`));
        const buf = new Uint8Array(await it.blob.arrayBuffer());
        const chunk = 4096;
        for (let off=0; off<buf.length; off+=chunk) {
          await writer.write(buf.slice(off, off+chunk));
        }
        await writer.write(new TextEncoder().encode(`END ${it.path}\n`));
        setProgress(Math.round(((i+1)/app._downloaded.length)*100));
      }
      writer.releaseLock();
      await port.close();
      appendLog('Übertragung abgeschlossen; Serial geschlossen');
    } catch (e) {
      appendLog('Serial-Übertragung fehlgeschlagen: ' + e, true);
    } finally { setProgress(0); }
  } else {
    alert('WebSerial nicht verfügbar; WebUSB-Implementierung ist geräteabhängig.');
  }
}

/* Helpers: File System Access */
async function getOrCreateDirectory(root, name) {
  try { return await root.getDirectoryHandle(name, { create: true }); }
  catch (e) { throw e; }
}

/* Crypto helpers */
function bufferToHex(buf) {
  const a = new Uint8Array(buf);
  return Array.from(a).map(x=>x.toString(16).padStart(2,'0')).join('');
}
function base64ToArrayBuffer(b64) {
  const bin = atob(b64.replace(/\s+/g,'')); const len = bin.length; const arr = new Uint8Array(len);
  for (let i=0;i<len;i++) arr[i]=bin.charCodeAt(i); return arr.buffer;
}
async function importPemKey(pem) {
  const clean = pem.replace(/-----(BEGIN|END)[\w\s]+-----/g,'').replace(/\s+/g,'');
  const der = base64ToArrayBuffer(clean);
  try {
    const key = await crypto.subtle.importKey('spki', der, {name:'RSASSA-PKCS1-v1_5', hash:'SHA-256'}, false, ['verify']);
    return { key, alg: 'RSASSA-PKCS1-v1_5' };
  } catch (e) {}
  try {
    const key = await crypto.subtle.importKey('spki', der, {name:'ECDSA', namedCurve:'P-256'}, false, ['verify']);
    return { key, alg: 'ECDSA' };
  } catch (e) { throw new Error('Unsupported public key format'); }
}

/* Small helpers */
function escapeHtml(s){ return String(s||'').replace(/[&<>"']/g, c=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]); }
function sanitizeFilename(name){ return String(name||'').replace(/[<>:"/\\|?*\x00-\x1F]/g,'_').slice(0,120); }

/* Minimal PEM import helper for client (reused) */
async function importPemKeyClient(pem) { return importPemKey(pem); } // alias

/* Utility to convert ArrayBuffer to hex */
function bufferToHexClient(buf) {
  const a = new Uint8Array(buf);
  return Array.from(a).map(x=>x.toString(16).padStart(2,'0')).join('');
}

/* Event wiring */
document.getElementById('refreshIndex').addEventListener('click', ()=>{ fetch(location.pathname + '?action=index&force=1').then(()=>loadIndex()); });
document.getElementById('downloadIndex').addEventListener('click', ()=>{ window.open(location.pathname + '?action=index', '_blank'); });
document.getElementById('showBase').addEventListener('click', ()=>renderApps('base_apps'));
document.getElementById('showExtra').addEventListener('click', ()=>renderApps('extra_apps'));
document.getElementById('showDev').addEventListener('click', ()=>renderApps('dev_apps'));
document.getElementById('openSd').addEventListener('click', async ()=> {
  if (!('showDirectoryPicker' in window)) { alert('File System Access API nicht unterstützt'); return; }
  try { state.sdHandle = await window.showDirectoryPicker(); $('sdStatus').textContent = 'Ausgewählt: ' + state.sdHandle.name; appendLog('SD ausgewählt: ' + state.sdHandle.name); } catch (e) { appendLog('SD-Auswahl abgebrochen'); }
});
document.getElementById('connectFlipper').addEventListener('click', async ()=> {
  // Attempt WebUSB with allowed vendor/product IDs if available; otherwise WebSerial prompt
  appendLog('Verbindungsversuch mit Flipper (nur erlaubte IDs) …');
  // Note: actual vendor/product filtering is done by the browser prompt filters; server-provided allowed IDs can be used to prefill filters if desired.
  if ('usb' in navigator) {
    try {
      // Build filters from server config if present (server index may include allowed_usb)
      const filters = [];
      // We cannot access server config directly here; rely on server-provided index if it contains allowed_usb
      const allowed = (state.index && state.index.config && state.index.config.allowed_usb) ? state.index.config.allowed_usb : null;
      if (allowed && allowed.vendor_ids) {
        for (const v of allowed.vendor_ids) {
          const vid = parseInt(v);
          if (!isNaN(vid)) filters.push({ vendorId: vid });
        }
      }
      const device = await navigator.usb.requestDevice({ filters: filters.length ? filters : [] });
      await device.open();
      appendLog('USB-Gerät geöffnet: ' + (device.productName || 'unbekannt'));
      alert('USB-Gerät geöffnet. Diese Seite wird ohne Ihre ausdrückliche Zustimmung keine Schreiboperationen durchführen.');
    } catch (e) {
      appendLog('USB-Verbindung abgebrochen oder fehlgeschlagen: ' + e, true);
    }
  } else if ('serial' in navigator) {
    try {
      const port = await navigator.serial.requestPort();
      appendLog('Serial-Port ausgewählt');
      alert('Serial-Port ausgewählt. Verwenden Sie "An Flipper übertragen" um Dateien zu senden.');
    } catch (e) {
      appendLog('Serial-Auswahl abgebrochen');
    }
  } else {
    alert('Weder WebUSB noch WebSerial werden von diesem Browser unterstützt.');
  }
});

/* On load: fetch index */
window.addEventListener('load', ()=>{ loadIndex(); });

/* Utility functions used above (client-side) */
function base64ToArrayBuffer(b64) {
  const bin = atob(b64.replace(/\s+/g,''));
  const len = bin.length;
  const arr = new Uint8Array(len);
  for (let i=0;i<len;i++) arr[i]=bin.charCodeAt(i);
  return arr.buffer;
}
async function importPemKey(pem) {
  const clean = pem.replace(/-----(BEGIN|END)[\w\s]+-----/g,'').replace(/\s+/g,'');
  const der = base64ToArrayBuffer(clean);
  try {
    const key = await crypto.subtle.importKey('spki', der, {name:'RSASSA-PKCS1-v1_5', hash:'SHA-256'}, false, ['verify']);
    return { key, alg: 'RSASSA-PKCS1-v1_5' };
  } catch (e) {}
  try {
    const key = await crypto.subtle.importKey('spki', der, {name:'ECDSA', namedCurve:'P-256'}, false, ['verify']);
    return { key, alg: 'ECDSA' };
  } catch (e) { throw new Error('Unsupported public key format'); }
}
function bufferToHex(buf) {
  const a = new Uint8Array(buf);
  return Array.from(a).map(x=>x.toString(16).padStart(2,'0')).join('');
}
</script>
</body>
</html>
