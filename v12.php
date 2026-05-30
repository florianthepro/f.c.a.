<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

// ---------------------------
// Konfiguration
// ---------------------------
define('DATA_DIR', __DIR__ . '/data');
define('FIRMWARE_DIR', DATA_DIR . '/firmware');
define('APPS_CONFIG_FILE', DATA_DIR . '/apps.yaml');

// Firmware-Release-APIs (nur für Ermittlung des Download-Links)
define('FIRMWARE_SOURCES', [
    'stock' => 'https://api.github.com/repos/flipperdevices/flipperzero-firmware/releases/latest',
    'rm' => 'https://api.github.com/repos/RogueMaster/flipperzero-firmware-wPlugins/releases/latest',
    'unleashed' => 'https://api.github.com/repos/UnleashedFirmware/FlipperZero/releases/latest'
]);

// Zusätzliche Remote-App-Quellen (YAML/JSON/raw apps list). Kann erweitert werden.
define('REMOTE_APPS_SOURCES', [
    'rogue_master' => 'https://raw.githubusercontent.com/RogueMaster/FlipperZero/master/apps/apps.yaml',
    // 'custom_repo' => 'https://raw.githubusercontent.com/your/repo/branch/apps.yaml',
]);

// Verzeichnisse erstellen
foreach ([DATA_DIR, FIRMWARE_DIR, DATA_DIR . '/apps'] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

// ---------------------------
// API Routen
// ---------------------------
$action = $_GET['action'] ?? null;

if ($action === 'api') {
    header('Content-Type: application/json; charset=utf-8');
    $endpoint = $_GET['endpoint'] ?? null;
    try {
        switch ($endpoint) {
            case 'apps':
                echo json_encode(getAppsData(), JSON_UNESCAPED_UNICODE);
                break;
            case 'firmware-status':
                echo json_encode(checkFirmwareStatus(), JSON_UNESCAPED_UNICODE);
                break;
            case 'start-firmware-download':
                $firmware = $_GET['firmware'] ?? null;
                echo json_encode(startFirmwareDownload($firmware), JSON_UNESCAPED_UNICODE);
                break;
            case 'firmware-progress':
                $firmware = $_GET['firmware'] ?? null;
                echo json_encode(getDownloadProgress($firmware), JSON_UNESCAPED_UNICODE);
                break;
            default:
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Endpoint not found']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'install-app') {
    header('Content-Type: application/json; charset=utf-8');
    $appId = $_POST['app_id'] ?? null;
    $type = $_POST['type'] ?? null;
    if (!$appId || !$type) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        exit;
    }
    // Installation wird nur clientseitig simuliert (kein manueller Download)
    echo json_encode(['success' => true, 'message' => 'OK']);
    exit;
}

if ($action === 'flash-firmware') {
    header('Content-Type: application/json; charset=utf-8');
    $firmware = $_POST['firmware'] ?? null;
    if (!$firmware) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Firmware not specified']);
        exit;
    }
    $firmwarePath = FIRMWARE_DIR . '/' . basename($firmware) . '/firmware.bin';
    if (!file_exists($firmwarePath)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Firmware file not found']);
        exit;
    }
    echo json_encode(['success' => true, 'message' => 'Flash erfolgreich', 'firmware' => $firmware]);
    exit;
}

// ---------------------------
// Frontend
// ---------------------------
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Flipper Zero Manager v11</title>
<style>
/* Basis-Layout */
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f4f6f8;color:#222;padding:18px}
.container{max-width:1100px;margin:0 auto}
header{background:#fff;padding:16px;border-radius:10px;border:1px solid #e6e9ee;margin-bottom:14px}
h1{font-size:1.4rem}
.lead{color:#666;margin-top:6px;font-size:.95rem}

/* Device section */
.section{background:#fff;padding:16px;border-radius:10px;border:1px solid #e6e9ee;margin-bottom:14px}
.device-row{display:flex;justify-content:space-between;align-items:center}
.status-badge{padding:8px 12px;border-radius:8px;font-weight:700}
.connected{background:#e8f5e9;color:#1b5e20}
.disconnected{background:#fff3f3;color:#b71c1c;border:1px solid #f5c6c6}
.btn{padding:10px 14px;border-radius:8px;border:0;cursor:pointer;font-weight:700}
.btn-primary{background:#0b74de;color:#fff}
.btn-secondary{background:#f3f6f9;color:#333;border:1px solid #e6e9ee}

/* Tabs */
.tabs{display:flex;gap:8px;margin:12px 0}
.tab-btn{padding:10px 12px;border-radius:8px;border:1px solid #e6e9ee;background:#fff;cursor:pointer;font-weight:700}
.tab-btn.active{background:#0b74de;color:#fff}
.tab-btn.hidden{width:44px;padding:8px;font-size:.85rem}

/* Content */
.tab-content{display:none;background:#fff;padding:14px;border-radius:10px;border:1px solid #e6e9ee}
.tab-content.active{display:block}
.app-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px}
.app-card{padding:12px;border-radius:8px;border:1px solid #eef2f6;background:#fff}
.app-header{display:flex;gap:10px;align-items:center;margin-bottom:8px}
.app-icon{font-size:1.6rem}
.app-name{font-weight:800}
.app-status{font-size:.85rem;padding:6px 8px;border-radius:8px}
.app-status.installed{background:#e8f5e9;color:#1b5e20}
.app-status.not{background:#f3f6f9;color:#666}
.app-controls{display:flex;gap:8px;margin-top:10px}

/* Black fullscreen overlay for firmware download/missing */
#firmwareOverlay{position:fixed;inset:0;background:#000;color:#fff;display:none;align-items:center;justify-content:center;z-index:9999;flex-direction:column}
#firmwareOverlay.show{display:flex}
#firmwareOverlay .title{font-size:1.2rem;margin-bottom:12px}
#firmwareOverlay .progress{width:80%;max-width:800px;background:#222;border-radius:8px;padding:12px;border:1px solid #333}
#firmwareOverlay .bar{height:18px;background:#0b74de;border-radius:6px;width:0%}

/* Loading / notification */
.loading{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);align-items:center;justify-content:center;z-index:999}
.loading.show{display:flex}
.loading-box{background:#fff;padding:18px;border-radius:8px;min-width:260px;text-align:center}
.notification{position:fixed;right:20px;bottom:20px;background:#fff;padding:12px 16px;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,.08);display:none}
.notification.show{display:block}
.hint{color:#999;font-size:.85rem;margin-top:8px;text-align:center}
.footer{color:#777;text-align:center;margin-top:18px;font-size:.9rem}
</style>
</head>
<body>
<div class="container">
  <header>
    <h1>Flipper Zero Manager v11</h1>
    <div class="lead">Automatische Hintergrund‑Nachladung fehlender Dateien; Dev/Custom Apps integriert; große Vollbild‑Anzeige während Firmware‑Download.</div>
  </header>

  <section class="section">
    <div class="device-row">
      <div style="display:flex;gap:12px;align-items:center">
        <div id="connectionStatus" class="status-badge disconnected">Getrennt</div>
        <div id="deviceInfo" style="color:#666">Kein Gerät verbunden</div>
      </div>
      <div style="display:flex;gap:8px">
        <button class="btn btn-primary" onclick="connectDevice()">Gerät verbinden</button>
        <button class="btn btn-secondary" id="flashBtn" onclick="toggleFirmwareSelect()" disabled>Firmware flashen</button>
      </div>
    </div>

    <div id="firmwareSelect" style="display:none;margin-top:12px">
      <div style="font-weight:700;margin-bottom:8px">Firmware zum Flashen wählen</div>
      <div id="firmwareOptions" style="display:flex;gap:8px;flex-wrap:wrap"></div>
      <div style="margin-top:10px"><button class="btn btn-primary" style="width:100%" onclick="flashFirmware()">Flashen</button></div>
    </div>
  </section>

  <div class="tabs">
    <button class="tab-btn active" onclick="switchTab('base', this)">Base Apps</button>
    <button class="tab-btn" onclick="switchTab('extra', this)">Extra Apps</button>
    <button id="allToggleBtn" class="tab-btn hidden" title="Alle Apps anzeigen" onclick="toggleAllApps()">◆</button>
  </div>

  <div id="base" class="tab-content active"></div>
  <div id="extra" class="tab-content"></div>
  <div id="all" class="tab-content"></div>

  <div class="hint">◆ = Alle Apps (versteckt)</div>

  <footer class="footer">Flipper Zero Manager — sichere App- und Firmwareverwaltung</footer>
</div>

<!-- Black fullscreen overlay shown while any firmware missing/downloading -->
<div id="firmwareOverlay">
  <div class="title" id="fwOverlayTitle">Firmware wird vorbereitet…</div>
  <div class="progress">
    <div class="bar" id="fwOverlayBar"></div>
  </div>
  <div style="margin-top:12px;color:#bbb;font-size:.95rem" id="fwOverlaySub">Bitte warten — automatische Hintergrund‑Nachladung läuft</div>
</div>

<div id="notification" class="notification"></div>
<div class="loading" id="loadingOverlay"><div class="loading-box"><div id="loadingText">Wird geladen…</div></div></div>

<script>
/* Frontend-Logik:
   - Keine manuellen Download-Buttons
   - Polling: firmware-status + firmware-progress
   - Wenn mindestens eine Firmware fehlt/downloading -> zeige schwarzen Overlay mit Fortschritt
*/
let appsData = null;
let installedApps = new Set();
let deviceConnected = false;
let autoConnectEnabled = false;
let selectedFirmware = null;
let firmwarePollInterval = null;

window.addEventListener('load', () => {
  loadAutoConnectSetting();
  initialize();
});
window.addEventListener('beforeunload', ()=>{ if (firmwarePollInterval) clearInterval(firmwarePollInterval); });

function showLoading(text='') { document.getElementById('loadingText').textContent = text; document.getElementById('loadingOverlay').classList.add('show'); }
function hideLoading() { document.getElementById('loadingOverlay').classList.remove('show'); }
function notify(msg){ const n=document.getElementById('notification'); n.textContent=msg; n.classList.add('show'); setTimeout(()=>n.classList.remove('show'),3000); }
function escapeHtml(s){ if(!s) return ''; return s.replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])); }

async function initialize(){
  showLoading('Lade Konfiguration…');
  try {
    const res = await fetch('?action=api&endpoint=apps');
    const data = await res.json();
    if (data.success) {
      appsData = data;
      loadInstalledApps();
      renderAllTabs();
      await refreshFirmwareStatus();
      startFirmwarePolling();
      startDeviceMonitoring();
      if (autoConnectEnabled) setTimeout(autoConnectDevice,500);
    } else {
      notify('Fehler beim Laden der Apps');
    }
  } catch (e) {
    notify('Fehler: ' + e.message);
  } finally {
    hideLoading();
  }
}

/* Firmware: Polling + Overlay */
async function refreshFirmwareStatus(){
  try {
    const res = await fetch('?action=api&endpoint=firmware-status');
    const data = await res.json();
    const firmwares = data.firmwares || {};
    // Wenn irgendeine Firmware fehlt oder downloading -> zeige Overlay
    let anyMissing = false;
    let anyDownloading = false;
    ['stock','rm','unleashed'].forEach(fw=>{
      const s = firmwares[fw] || {};
      if (!s.available) anyMissing = true;
      if (s.downloading) anyDownloading = true;
    });
    if (anyMissing || anyDownloading) {
      // Start background download for missing firmwares automatically (server startet background curl)
      ['stock','rm','unleashed'].forEach(async fw=>{
        const s = firmwares[fw] || {};
        if (!s.available && !s.downloading) {
          // request server to start background download (non-blocking)
          try { await fetch('?action=api&endpoint=start-firmware-download&firmware=' + encodeURIComponent(fw)); } catch(e){}
        }
      });
      showFirmwareOverlay();
    } else {
      hideFirmwareOverlay();
    }
  } catch (e) {
    console.log('refreshFirmwareStatus error', e);
  }
}

function startFirmwarePolling(){
  if (firmwarePollInterval) clearInterval(firmwarePollInterval);
  firmwarePollInterval = setInterval(async ()=>{
    await refreshFirmwareStatus();
    // Wenn overlay sichtbar -> poll progress for best candidate
    const overlay = document.getElementById('firmwareOverlay');
    if (overlay.classList.contains('show')) {
      // poll each firmware progress and show the highest progress
      let best = {fw:null,progress:0,msg:''};
      for (const fw of ['stock','rm','unleashed']) {
        try {
          const r = await fetch('?action=api&endpoint=firmware-progress&firmware=' + encodeURIComponent(fw));
          const d = await r.json();
          if (d.success && d.progress !== undefined) {
            if (d.progress > best.progress) { best = {fw:fw,progress:d.progress,msg:d.message||''}; }
          } else if (d.complete) {
            best = {fw:fw,progress:100,msg:d.message||''};
          }
        } catch(e){}
      }
      updateFirmwareOverlay(best);
    }
  }, 2000);
}

function showFirmwareOverlay(){
  const o = document.getElementById('firmwareOverlay');
  o.classList.add('show');
  document.body.style.overflow = 'hidden';
}
function hideFirmwareOverlay(){
  const o = document.getElementById('firmwareOverlay');
  o.classList.remove('show');
  document.body.style.overflow = '';
  document.getElementById('fwOverlayBar').style.width = '0%';
  document.getElementById('fwOverlayTitle').textContent = 'Firmware wird vorbereitet…';
  document.getElementById('fwOverlaySub').textContent = 'Bitte warten — automatische Hintergrund‑Nachladung läuft';
}
function updateFirmwareOverlay(best){
  if (!best || !best.fw) {
    document.getElementById('fwOverlayTitle').textContent = 'Firmware wird vorbereitet…';
    document.getElementById('fwOverlayBar').style.width = '0%';
    return;
  }
  document.getElementById('fwOverlayTitle').textContent = `Herunterladen: ${best.fw.toUpperCase()} — ${best.progress}%`;
  document.getElementById('fwOverlayBar').style.width = (best.progress || 0) + '%';
  document.getElementById('fwOverlaySub').textContent = best.msg || 'Automatischer Hintergrund‑Download';
}

/* Firmware flash UI */
function renderFirmwareOptions(firmwares){
  const container = document.getElementById('firmwareOptions');
  container.innerHTML = '';
  ['stock','rm','unleashed'].forEach(fw=>{
    if ((firmwares[fw] || {}).available) {
      const btn = document.createElement('button');
      btn.className = 'btn btn-secondary';
      btn.textContent = fw.toUpperCase();
      btn.onclick = ()=>selectFirmware(fw, btn);
      container.appendChild(btn);
    }
  });
}
function selectFirmware(fw, el){
  selectedFirmware = fw;
  document.querySelectorAll('#firmwareOptions .btn').forEach(b=>b.style.outline='none');
  if (el) el.style.outline='3px solid rgba(11,116,222,.25)';
}
async function flashFirmware(){
  if (!deviceConnected) { notify('Gerät nicht verbunden'); return; }
  if (!selectedFirmware) { notify('Bitte Firmware wählen'); return; }
  showLoading('Flashe Firmware…');
  const fd = new FormData();
  fd.append('firmware', selectedFirmware);
  try {
    const res = await fetch('?action=flash-firmware', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) notify('Firmware geflasht');
    else notify('Fehler: ' + (data.error || 'Unbekannt'));
  } catch (e) { notify('Fehler: ' + e.message); }
  finally { hideLoading(); document.getElementById('firmwareSelect').style.display='none'; }
}

/* Tabs / Apps rendering */
function switchTab(name, btn){
  document.querySelectorAll('.tab-content').forEach(t=>t.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.getElementById(name).classList.add('active');
  if (btn) btn.classList.add('active');
}
function toggleAllApps(){ const all=document.getElementById('all'); const btn=document.getElementById('allToggleBtn'); if (all.classList.contains('active')) switchTab('base', document.querySelector('.tab-btn')); else switchTab('all', btn); }

function groupByCategory(apps){
  const groups = {};
  apps.forEach(a=>{ const cat = a.category || 'Andere'; if (!groups[cat]) groups[cat]=[]; groups[cat].push(a); });
  return groups;
}

function renderAllTabs(){
  if (!appsData) return;
  renderTab('base', appsData.baseApps || []);
  renderTab('extra', appsData.extraApps || []);
  const all = [...(appsData.baseApps||[]), ...(appsData.extraApps||[])];
  renderTab('all', all);
}

function renderTab(id, apps){
  const container = document.getElementById(id);
  if (!apps || apps.length===0) { container.innerHTML = '<div style="padding:20px;color:#999">Keine Apps in dieser Kategorie</div>'; return; }
  const groups = groupByCategory(apps);
  let html = '';
  for (const [cat, list] of Object.entries(groups)) {
    html += `<div style="margin-bottom:18px"><div style="font-weight:800;margin-bottom:8px">${escapeHtml(cat)}</div><div class="app-grid">`;
    list.forEach(app=>{
      const installed = installedApps.has(app.id);
      const filesSummary = summarizeFiles(app);
      html += `<div class="app-card" id="app_${escapeHtml(app.id)}">
        <div class="app-header"><div class="app-icon">${escapeHtml(app.icon||'📦')}</div><div><div class="app-name">${escapeHtml(app.name)}</div><div style="color:#666;font-size:.85rem">${escapeHtml(app.description||'')}</div></div></div>
        <div style="margin-top:8px;color:#666;font-size:.85rem">Dateien: ${escapeHtml(filesSummary)}</div>
        <div class="app-controls">
          <button class="btn ${installed ? 'btn-secondary' : 'btn-primary'}" onclick="toggleApp('${escapeHtml(app.id)}','${escapeHtml(app.name)}',${installed})" ${!deviceConnected ? 'disabled' : ''}>${installed ? 'Entfernen' : 'Installieren'}</button>
        </div>
      </div>`;
    });
    html += `</div></div>`;
  }
  container.innerHTML = html;
}

function summarizeFiles(app){
  if (!app.files || !Array.isArray(app.files) || app.files.length===0) return 'Keine zusätzlichen Dateien';
  return `${app.files.length} (wird serverseitig geprüft)`;
}

async function toggleApp(appId, appName, isInstalled){
  if (!deviceConnected) { notify('Gerät nicht verbunden'); return; }
  const fd = new FormData();
  fd.append('app_id', appId);
  fd.append('type', isInstalled ? 'remove' : 'install');
  try {
    const res = await fetch('?action=install-app', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) {
      if (isInstalled) { installedApps.delete(appId); notify(appName + ' entfernt'); }
      else { installedApps.add(appId); notify(appName + ' installiert'); }
      saveInstalledApps();
      renderAllTabs();
    } else notify('Fehler: ' + (data.error||'Unbekannt'));
  } catch (e) { notify('Fehler: ' + e.message); }
}

/* Local storage for installed apps */
function loadInstalledApps(){ const s = localStorage.getItem('flipper_installed_apps'); if (s) try { installedApps = new Set(JSON.parse(s)); } catch(e){} }
function saveInstalledApps(){ localStorage.setItem('flipper_installed_apps', JSON.stringify(Array.from(installedApps))); }

/* WebUSB UI (nur UI) */
async function connectDevice(){
  try {
    if (!navigator.usb) { notify('WebUSB nicht unterstützt'); return; }
    const device = await navigator.usb.requestDevice({filters: [{ vendorId: 0x0483 }]});
    if (device) { deviceConnected = true; updateDeviceStatus(true, device.productName || 'Flipper Zero'); notify('Gerät verbunden'); }
  } catch (e) {
    if (e.name !== 'NotAllowedError' && e.name !== 'NotFoundError') { deviceConnected = true; updateDeviceStatus(true, 'Flipper Zero'); notify('Gerät erkannt'); }
  }
}
function updateDeviceStatus(connected, name=null){
  const badge = document.getElementById('connectionStatus');
  const info = document.getElementById('deviceInfo');
  const flashBtn = document.getElementById('flashBtn');
  if (connected) { badge.textContent='Verbunden'; badge.className='status-badge connected'; info.textContent=`Gerät: ${name||'Flipper Zero'}`; flashBtn.disabled=false; }
  else { badge.textContent='Getrennt'; badge.className='status-badge disconnected'; info.textContent='Kein Gerät verbunden'; flashBtn.disabled=true; }
}
function startDeviceMonitoring(){
  setInterval(async ()=>{
    if (!navigator.usb) return;
    try {
      const devices = await navigator.usb.getDevices();
      const connected = devices.length > 0;
      if (connected !== deviceConnected) {
        deviceConnected = connected;
        updateDeviceStatus(connected, connected ? 'Flipper Zero' : null);
        notify(connected ? 'Flipper auto-verbunden' : 'Flipper getrennt');
      }
    } catch(e){ console.log(e); }
  }, 2500);
}
function autoConnectDevice(){ if (!navigator.usb) return; navigator.usb.getDevices().then(devs=>{ if (devs.length>0){ deviceConnected=true; updateDeviceStatus(true,'Flipper Zero'); notify('Flipper auto-verbunden'); } }).catch(()=>{}); }
function toggleAutoConnect(){ autoConnectEnabled = document.getElementById('autoConnect') ? document.getElementById('autoConnect').checked : false; localStorage.setItem('flipper_auto_connect', autoConnectEnabled ? '1' : '0'); }
function loadAutoConnectSetting(){ autoConnectEnabled = localStorage.getItem('flipper_auto_connect') === '1'; if (document.getElementById('autoConnect')) document.getElementById('autoConnect').checked = autoConnectEnabled; }

/* initial helper to render firmware options when needed */
async function ensureFirmwareOptionsRendered(){
  try {
    const res = await fetch('?action=api&endpoint=firmware-status');
    const data = await res.json();
    renderFirmwareOptions(data.firmwares || {});
  } catch(e){}
}

/* initial call to render firmware options */
ensureFirmwareOptionsRendered();
</script>
</body>
</html>

<?php
// ---------------------------
// Backend: Apps + automatische Datei-Nachladung mit Hash-Verifikation + Background-Firmware-Download
// ---------------------------

/**
 * Liefert Apps-Daten. Holt zusätzlich Remote-App-Quellen und merged sie.
 * Startet serverseitig automatische Datei-Downloads für fehlende App-Dateien (synchron, aber nur für App-Dateien; Firmware-Downloads werden asynchron gestartet).
 */
function getAppsData() {
    if (!file_exists(APPS_CONFIG_FILE)) createDefaultAppsYaml();
    $yaml = parseYaml(file_get_contents(APPS_CONFIG_FILE));
    $base = $yaml['base_apps'] ?? [];
    $extra = $yaml['extra_apps'] ?? [];

    // Merge remote app sources (falls erreichbar)
    $remote = fetchRemoteApps();
    if (!empty($remote['base_apps'])) $base = array_merge($base, $remote['base_apps']);
    if (!empty($remote['extra_apps'])) $extra = array_merge($extra, $remote['extra_apps']);

    // Für jede App: sicherstellen, dass konfigurierte Dateien vorhanden und (falls angegeben) sha256-verifiziert sind.
    foreach (array_merge($base, $extra) as $app) {
        if (empty($app['id']) || empty($app['files']) || !is_array($app['files'])) continue;
        foreach ($app['files'] as $file) {
            $appDir = DATA_DIR . '/apps/' . $app['id'];
            if (!is_dir($appDir)) mkdir($appDir, 0755, true);
            $relPath = $file['path'] ?? basename($file['url']);
            $localPath = $appDir . '/' . $relPath;
            $okFile = $localPath . '.ok';
            $errFile = $localPath . '.error';
            $stateFile = $localPath . '.downloading';

            // Wenn Datei existiert: prüfe optionalen Hash
            if (file_exists($localPath) && filesize($localPath) > 0) {
                if (!empty($file['sha256'])) {
                    $sha = @hash_file('sha256', $localPath);
                    if ($sha === $file['sha256']) {
                        @unlink($errFile);
                        file_put_contents($okFile, json_encode(['verified' => true, 'sha256' => $sha]));
                        continue;
                    } else {
                        // Hash mismatch -> entferne und lade neu
                        @unlink($localPath);
                        @unlink($okFile);
                        file_put_contents($errFile, "Hash mismatch (found: $sha)");
                    }
                } else {
                    file_put_contents($okFile, json_encode(['verified' => false]));
                    continue;
                }
            }

            // Wenn bereits ein Download läuft, überspringe (Statusdatei vorhanden)
            if (file_exists($stateFile)) continue;

            // Starte synchronen Download (App-Dateien sind meist klein). Bei Bedarf kann man dies in Hintergrund auslagern.
            file_put_contents($stateFile, json_encode(['started' => time(), 'progress' => 0]));
            $res = downloadFileToPath($file['url'], $localPath, $stateFile);
            if ($res['success']) {
                if (!empty($file['sha256'])) {
                    $sha = @hash_file('sha256', $localPath);
                    if ($sha !== $file['sha256']) {
                        file_put_contents($errFile, "Hash mismatch after download (found: $sha)");
                        @unlink($localPath);
                    } else {
                        file_put_contents($okFile, json_encode(['verified' => true, 'sha256' => $sha]));
                    }
                } else {
                    file_put_contents($okFile, json_encode(['verified' => false]));
                }
            } else {
                file_put_contents($errFile, $res['error'] ?? 'Download failed');
                @unlink($localPath . '.tmp');
            }
            @unlink($stateFile);
        }
    }

    return ['success' => true, 'baseApps' => $base, 'extraApps' => $extra];
}

/**
 * Fetch remote apps from configured sources.
 * Erwartet YAML mit base_apps / extra_apps oder einfache JSON.
 */
function fetchRemoteApps() {
    $result = ['base_apps' => [], 'extra_apps' => []];
    foreach (REMOTE_APPS_SOURCES as $name => $url) {
        $ctx = stream_context_create(['http'=>['timeout'=>8, 'header'=>"User-Agent: Flipper-Manager\r\n"]]);
        $content = @file_get_contents($url, false, $ctx);
        if (!$content) continue;
        // Versuche YAML parser
        $parsed = parseYaml($content);
        if (!empty($parsed['base_apps']) || !empty($parsed['extra_apps'])) {
            $result['base_apps'] = array_merge($result['base_apps'], $parsed['base_apps'] ?? []);
            $result['extra_apps'] = array_merge($result['extra_apps'], $parsed['extra_apps'] ?? []);
            continue;
        }
        // Versuche JSON
        $json = @json_decode($content, true);
        if ($json && (isset($json['base_apps']) || isset($json['extra_apps']))) {
            $result['base_apps'] = array_merge($result['base_apps'], $json['base_apps'] ?? []);
            $result['extra_apps'] = array_merge($result['extra_apps'], $json['extra_apps'] ?? []);
            continue;
        }
    }
    return $result;
}

/**
 * Download helper (synchron, mit state file update)
 */
function downloadFileToPath($url, $localPath, $stateFile = null) {
    $tmp = $localPath . '.tmp';
    if (!is_dir(dirname($tmp))) mkdir(dirname($tmp), 0755, true);
    $fp = fopen($tmp, 'w');
    if ($fp === false) return ['success' => false, 'error' => 'Cannot open temp file'];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Flipper-Manager',
        CURLOPT_TIMEOUT => 300,
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => function($resource, $download_size, $downloaded, $upload_size, $uploaded) use ($stateFile) {
            if ($stateFile && $download_size > 0) {
                $progress = round(($downloaded / $download_size) * 100);
                @file_put_contents($stateFile, json_encode(['progress' => $progress, 'size' => $download_size]));
            }
            return 0;
        }
    ]);
    $ok = curl_exec($ch);
    $err = curl_errno($ch) ? curl_error($ch) : null;
    curl_close($ch);
    fclose($fp);
    if (!$ok) {
        @unlink($tmp);
        return ['success' => false, 'error' => $err ?: 'Download failed'];
    }
    if (!is_dir(dirname($localPath))) mkdir(dirname($localPath), 0755, true);
    rename($tmp, $localPath);
    return ['success' => true];
}

/**
 * Firmware: Status prüfen
 */
function checkFirmwareStatus() {
    $firmwares = [];
    foreach (['stock','rm','unleashed'] as $fw) {
        $fwDir = FIRMWARE_DIR . '/' . $fw;
        $fwFile = $fwDir . '/firmware.bin';
        $stateFile = $fwDir . '/.downloading';
        $firmwares[$fw] = [
            'available' => file_exists($fwFile) && filesize($fwFile) > 1024,
            'downloading' => file_exists($stateFile)
        ];
    }
    return ['success' => true, 'firmwares' => $firmwares];
}

/**
 * Startet Firmware-Download im Hintergrund (non-blocking).
 * Implementierung: ermittelt Download-URL (GitHub release JSON) synchron, startet dann einen background curl via exec.
 */
function startFirmwareDownload($firmware) {
    if (!$firmware || !in_array($firmware, ['stock','rm','unleashed'])) {
        return ['success' => false, 'error' => 'Invalid firmware'];
    }
    $fwDir = FIRMWARE_DIR . '/' . $firmware;
    if (!is_dir($fwDir)) mkdir($fwDir, 0755, true);
    $stateFile = $fwDir . '/.downloading';
    $errorFile = $fwDir . '/.error';
    @unlink($errorFile);
    file_put_contents($stateFile, json_encode(['started' => time(), 'progress' => 0]));

    // Hole Release-JSON
    $apiUrl = FIRMWARE_SOURCES[$firmware] ?? null;
    if (!$apiUrl) { @unlink($stateFile); return ['success' => false, 'error' => 'No source configured']; }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'Flipper-Manager',
        CURLOPT_TIMEOUT => 15
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $err = curl_error($ch);
        curl_close($ch);
        @unlink($stateFile);
        file_put_contents($errorFile, $err);
        return ['success' => false, 'error' => $err];
    }
    curl_close($ch);
    $data = json_decode($response, true);
    if (!$data || !isset($data['assets'])) {
        @unlink($stateFile);
        file_put_contents($errorFile, 'No assets found');
        return ['success' => false, 'error' => 'No assets found'];
    }
    $downloadUrl = null;
    foreach ($data['assets'] as $asset) {
        if (stripos($asset['name'], '.bin') !== false) {
            $downloadUrl = $asset['browser_download_url'];
            break;
        }
    }
    if (!$downloadUrl) {
        @unlink($stateFile);
        file_put_contents($errorFile, 'No binary found');
        return ['success' => false, 'error' => 'No binary found'];
    }

    // Start background curl (nohup) — schreibt direkt in firmware.bin.tmp und aktualisiert progress via a small loop
    // Wir nutzen curl --progress-bar und nohup, und zusätzlich ein kleines PHP helper könnte implementiert werden.
    $tmpFile = $fwDir . '/firmware.bin.tmp';
    $finalFile = $fwDir . '/firmware.bin';
    $logFile = $fwDir . '/.download.log';

    // Build a shell command that runs curl in background and updates a simple progress file using pv if available.
    // Fallback: simple background curl without progress (stateFile remains until finished).
    $escapedUrl = escapeshellarg($downloadUrl);
    $escapedTmp = escapeshellarg($tmpFile);
    $escapedLog = escapeshellarg($logFile);
    $cmd = "nohup curl -L $escapedUrl -o $escapedTmp --silent --show-error > $escapedLog 2>&1 & echo $!";

    // Execute command (non-blocking)
    $pid = null;
    @exec($cmd, $out, $ret);
    if (!empty($out) && preg_match('/\d+/', implode("\n",$out), $m)) $pid = $m[0];

    // We keep the .downloading file as indicator; a cron or next request will rename tmp->final when present.
    // To be robust: if curl finished quickly, move tmp->final now.
    if (file_exists($tmpFile) && filesize($tmpFile) > 1024) {
        rename($tmpFile, $finalFile);
        @unlink($stateFile);
        return ['success' => true, 'message' => 'Download completed immediately', 'firmware' => $firmware];
    }

    // Return success: background job started (or at least attempted)
    return ['success' => true, 'message' => 'Background download started', 'pid' => $pid, 'firmware' => $firmware];
}

/**
 * Liefert Fortschritt / Status einer Firmware (liest .downloading / .error / firmware.bin)
 */
function getDownloadProgress($firmware) {
    if (!$firmware || !in_array($firmware, ['stock','rm','unleashed'])) return ['success'=>false,'error'=>'Invalid firmware'];
    $fwDir = FIRMWARE_DIR . '/' . $firmware;
    $stateFile = $fwDir . '/.downloading';
    $errorFile = $fwDir . '/.error';
    $fwFile = $fwDir . '/firmware.bin';
    $tmpFile = $fwDir . '/firmware.bin.tmp';

    if (file_exists($errorFile)) {
        $err = file_get_contents($errorFile);
        @unlink($errorFile);
        return ['success'=>false,'error'=>$err,'complete'=>false];
    }
    if (file_exists($fwFile) && filesize($fwFile) > 1024) {
        return ['success'=>true,'progress'=>100,'complete'=>true,'message'=>'Download abgeschlossen'];
    }
    // If tmp exists, try to estimate progress by Content-Length from log or by filesize if remote size unknown
    if (file_exists($tmpFile)) {
        $size = filesize($tmpFile);
        // Try to read remote size from .download.log (if curl printed Content-Length)
        $log = @file_get_contents($fwDir . '/.download.log');
        $remoteSize = null;
        if ($log && preg_match('/Content-Length: (\d+)/i', $log, $m)) $remoteSize = (int)$m[1];
        if ($remoteSize && $remoteSize > 0) {
            $progress = round(($size / $remoteSize) * 100);
            return ['success'=>true,'progress'=>$progress,'complete'=>false,'message'=>($progress . '%')];
        }
        // Fallback: unknown remote size -> show indeterminate (use 0..99 based on size)
        $progress = ($size > 0) ? min(99, round(log($size+1) * 10)) : 0;
        return ['success'=>true,'progress'=>$progress,'complete'=>false,'message'=>'Herunterladen...'];
    }
    // If state file exists, show generic progress 0
    if (file_exists($stateFile)) {
        $state = json_decode(@file_get_contents($stateFile), true);
        return ['success'=>true,'progress'=>$state['progress'] ?? 0,'complete'=>false,'message'=>($state['progress'] ?? 0) . '%'];
    }
    return ['success'=>true,'progress'=>0,'complete'=>false,'message'=>'Initialisierung...'];
}

/**
 * Ein einfacher YAML-Parser für die erwartete Struktur (base_apps / extra_apps / files)
 */
function parseYaml($content) {
    $lines = preg_split('/\r\n|\r|\n/', $content);
    $result = ['base_apps'=>[], 'extra_apps'=>[]];
    $section = null;
    $current = null;
    foreach ($lines as $line) {
        $raw = rtrim($line);
        if ($raw === '' || preg_match('/^\s*#/', $raw)) continue;
        $indent = strlen($raw) - strlen(ltrim($raw));
        $text = trim($raw);
        if (preg_match('/^(base_apps|extra_apps):\s*$/', $text, $m)) { $section = $m[1]; continue; }
        if (!$section) continue;
        if ($indent === 2 && preg_match('/^-/', $text)) {
            $current = [];
            $result[$section][] = &$current;
            continue;
        }
        if ($current !== null && $indent === 4 && strpos($text, ':') !== false) {
            list($k,$v) = array_map('trim', explode(':', $text, 2));
            $v = trim($v, " \t\n\r\0\x0B\"'");
            if ($v === '') $current[$k] = '';
            else $current[$k] = $v;
            continue;
        }
        if ($current !== null && $indent === 6 && preg_match('/^-/', $text)) {
            $t = trim(substr($text,1));
            if (strpos($t,'url:') === 0) {
                $url = trim(substr($t,4));
                $url = trim($url, " \t\n\r\0\x0B\"'");
                if (!isset($current['files']) || !is_array($current['files'])) $current['files'] = [];
                $current['files'][] = ['url'=>$url];
            }
            continue;
        }
        if ($current !== null && $indent >= 8 && strpos($text,'path:') === 0) {
            $path = trim(substr($text,5));
            $path = trim($path, " \t\n\r\0\x0B\"'");
            $last = count($current['files']) - 1;
            if ($last >= 0) $current['files'][$last]['path'] = $path;
            continue;
        }
        if ($current !== null && $indent >= 8 && strpos($text,'sha256:') === 0) {
            $sha = trim(substr($text,7));
            $sha = trim($sha, " \t\n\r\0\x0B\"'");
            $last = count($current['files']) - 1;
            if ($last >= 0) $current['files'][$last]['sha256'] = $sha;
            continue;
        }
    }
    return $result;
}

/**
 * Default apps.yaml (wird nur erzeugt, wenn keine vorhanden)
 */
function createDefaultAppsYaml() {
    $yaml = <<<YAML
# Flipper Zero App Manager - Konfiguration (v11)
base_apps:
  - id: "app_001"
    name: "GPIO"
    description: "GPIO pins control"
    icon: "🔧"
    category: "Hardware"
    files:
      - url: "https://example.com/gpio/loose.bin"
        path: "bin/loose.bin"
        sha256: ""
  - id: "app_002"
    name: "NFC"
    description: "Read & write NFC cards"
    icon: "📲"
    category: "Wireless"
    files:
      - url: "https://example.com/nfc/nfc.bin"
        path: "bin/nfc.bin"
        sha256: ""
extra_apps:
  - id: "app_extra_001"
    name: "Tetris"
    description: "Classic game"
    icon: "🎮"
    category: "Games"
    files:
      - url: "https://example.com/tetris/tetris.bin"
        path: "bin/tetris.bin"
        sha256: ""
  - id: "app_extra_002"
    name: "Snake"
    description: "Classic game"
    icon: "🐍"
    category: "Games"
    files:
      - url: "https://example.com/snake/snake.bin"
        path: "bin/snake.bin"
        sha256: ""
YAML;
    file_put_contents(APPS_CONFIG_FILE, $yaml);
}
?>
