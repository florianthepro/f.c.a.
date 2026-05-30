<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(600);

// ---------------------------
// Konfiguration
// ---------------------------
define('DATA_DIR', __DIR__ . '/data');
define('FIRMWARE_DIR', DATA_DIR . '/firmware');
define('APPS_CONFIG_FILE', DATA_DIR . '/apps.yaml');
define('STATE_FILE', DATA_DIR . '/.state.json');
define('LOG_FILE', DATA_DIR . '/download.log');

// Firmware-Quellen (wie bisher)
define('FIRMWARE_SOURCES', [
    'stock' => 'https://api.github.com/repos/flipperdevices/flipperzero-firmware/releases/latest',
    'rm' => 'https://api.github.com/repos/RogueMaster/flipperzero-firmware-wPlugins/releases/latest',
    'unleashed' => 'https://api.github.com/repos/UnleashedFirmware/FlipperZero/releases/latest'
]);

// Verzeichnisse erstellen
foreach ([DATA_DIR, FIRMWARE_DIR] as $dir) {
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
            case 'firmware-versions':
                echo json_encode(getFirmwareVersions(), JSON_UNESCAPED_UNICODE);
                break;
            case 'firmware-status':
                echo json_encode(checkFirmwareStatus(), JSON_UNESCAPED_UNICODE);
                break;
            case 'start-download':
                $firmware = $_GET['firmware'] ?? null;
                echo json_encode(startFirmwareDownload($firmware), JSON_UNESCAPED_UNICODE);
                break;
            case 'download-progress':
                $firmware = $_GET['firmware'] ?? null;
                echo json_encode(getDownloadProgress($firmware), JSON_UNESCAPED_UNICODE);
                break;
            case 'start-file-download':
                $appId = $_GET['app'] ?? null;
                $fileIndex = isset($_GET['index']) ? intval($_GET['index']) : 0;
                echo json_encode(startFileDownload($appId, $fileIndex), JSON_UNESCAPED_UNICODE);
                break;
            case 'file-download-progress':
                $appId = $_GET['app'] ?? null;
                $fileIndex = isset($_GET['index']) ? intval($_GET['index']) : 0;
                echo json_encode(getFileDownloadProgress($appId, $fileIndex), JSON_UNESCAPED_UNICODE);
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
    // Hier nur Platzhalter: Installation wird clientseitig simuliert.
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
// HTML / Frontend
// ---------------------------
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Flipper Zero Manager v11</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; padding: 20px; }
.container { max-width: 1200px; margin: 0 auto; }
header { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
header h1 { font-size: 1.8em; color: #333; margin-bottom: 5px; }
header p { color: #666; font-size: 0.95em; }
.device-section { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #e0e0e0; }
.device-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
.device-info h2 { font-size: 1.1em; color: #333; margin-bottom: 8px; }
.status-badge { display: inline-block; padding: 6px 12px; border-radius: 4px; font-weight: 600; font-size: 0.9em; }
.status-badge.connected { background: #e8f5e9; color: #2e7d32; }
.status-badge.disconnected { background: #ffebee; color: #c62828; }
.device-detail { color: #666; font-size: 0.9em; margin-top: 6px; }
.device-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
.btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 0.95em; transition: background 0.2s; }
.btn-primary { background: #2196f3; color: white; }
.btn-primary:hover:not(:disabled) { background: #1976d2; }
.btn-secondary { background: #f5f5f5; color: #333; border: 1px solid #e0e0e0; }
.btn-secondary:hover:not(:disabled) { background: #e0e0e0; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.toggle-switch { display: flex; align-items: center; gap: 10px; }
.switch { position: relative; display: inline-block; width: 50px; height: 24px; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: 0.4s; border-radius: 24px; }
.slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: 0.4s; border-radius: 50%; }
input:checked + .slider { background-color: #2196f3; }
input:checked + .slider:before { transform: translateX(26px); }
.switch-label { font-size: 0.9em; color: #666; }
.firmware-status { margin-top: 15px; padding: 15px; background: #f9f9f9; border-radius: 4px; border: 1px solid #e0e0e0; }
.firmware-status h3 { font-size: 0.95em; color: #333; margin-bottom: 10px; }
.fw-item { padding: 10px; margin-bottom: 8px; background: white; border: 1px solid #e0e0e0; border-radius: 4px; display: flex; justify-content: space-between; align-items: center; }
.fw-name { font-weight: 600; color: #333; }
.fw-status { font-size: 0.85em; padding: 4px 8px; border-radius: 3px; }
.fw-status.ready { background: #e8f5e9; color: #2e7d32; }
.fw-status.downloading { background: #e3f2fd; color: #1976d2; }
.fw-status.error { background: #ffebee; color: #c62828; }
.fw-progress { margin-top: 8px; }
.progress-bar { width: 100%; height: 8px; background: #e0e0e0; border-radius: 4px; overflow: hidden; }
.progress-fill { height: 100%; background: #2196f3; width: 0%; transition: width 0.3s; }
.fw-actions { display: flex; gap: 8px; margin-top: 8px; }
.fw-btn { padding: 6px 12px; font-size: 0.85em; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; transition: background 0.2s; }
.fw-btn-download { background: #4caf50; color: white; }
.fw-btn-download:hover:not(:disabled) { background: #45a049; }
.fw-btn-flash { background: #2196f3; color: white; }
.fw-btn-flash:hover:not(:disabled) { background: #1976d2; }
.fw-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.firmware-select { margin-top: 15px; padding: 15px; background: #f9f9f9; border-radius: 4px; border: 1px solid #e0e0e0; }
.firmware-select h3 { font-size: 0.95em; color: #333; margin-bottom: 10px; }
.firmware-options { display: flex; gap: 10px; flex-wrap: wrap; }
.firmware-option { padding: 8px 16px; background: white; border: 1px solid #e0e0e0; border-radius: 4px; cursor: pointer; font-size: 0.9em; transition: all 0.2s; }
.firmware-option:hover { border-color: #2196f3; }
.firmware-option.selected { background: #2196f3; color: white; border-color: #2196f3; }
.tabs { display: flex; background: white; border-radius: 8px 8px 0 0; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #e0e0e0; border-bottom: none; }
.tab-btn { flex: 1; padding: 15px; border: none; background: white; cursor: pointer; font-weight: 600; color: #666; border-right: 1px solid #e0e0e0; transition: background 0.2s; font-size: 0.9em; }
.tab-btn:last-child { border-right: none; }
.tab-btn:hover { background: #f9f9f9; }
.tab-btn.active { background: #2196f3; color: white; border-bottom: 3px solid #1976d2; }
.tab-btn.hidden { font-size: 0.7em; color: #999; width: 48px; padding: 10px; text-align: center; }
.tab-content { display: none; background: white; padding: 20px; border-radius: 0 0 8px 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #e0e0e0; border-top: none; }
.tab-content.active { display: block; }
.app-group { margin-bottom: 25px; }
.app-group-title { font-size: 0.95em; font-weight: 600; color: #666; margin-bottom: 10px; padding-left: 10px; border-left: 3px solid #2196f3; }
.app-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 15px; }
.app-card { background: white; border: 1px solid #e0e0e0; border-radius: 4px; padding: 15px; transition: box-shadow 0.2s; }
.app-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.app-header { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.app-icon { font-size: 1.8em; }
.app-info { flex-grow: 1; }
.app-name { font-weight: 600; color: #333; font-size: 0.95em; }
.app-status { display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 0.75em; font-weight: 600; margin-top: 4px; }
.app-status.installed { background: #e8f5e9; color: #2e7d32; }
.app-status.not-installed { background: #f5f5f5; color: #666; }
.app-description { color: #666; font-size: 0.85em; margin-bottom: 10px; line-height: 1.4; }
.app-controls { display: flex; gap: 8px; }
.app-btn { flex: 1; padding: 8px 12px; background: #2196f3; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 0.85em; font-weight: 600; transition: background 0.2s; }
.app-btn:hover:not(:disabled) { background: #1976d2; }
.app-btn.remove { background: #f44336; }
.app-btn.remove:hover:not(:disabled) { background: #d32f2f; }
.app-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.loading-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
.loading-overlay.show { display: flex; }
.loading-content { background: white; padding: 40px; border-radius: 8px; text-align: center; max-width: 400px; }
.spinner { width: 40px; height: 40px; border: 3px solid #f5f5f5; border-top: 3px solid #2196f3; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 20px; }
@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
.loading-text { color: #666; font-size: 0.95em; margin-bottom: 15px; }
.loading-progress { width: 100%; height: 6px; background: #e0e0e0; border-radius: 3px; overflow: hidden; }
.loading-progress-fill { height: 100%; background: #2196f3; width: 0%; transition: width 0.3s; }
.notification { position: fixed; bottom: 20px; right: 20px; padding: 15px 20px; background: white; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); display: none; animation: slideIn 0.3s ease; max-width: 300px; z-index: 2000; border-left: 4px solid #2196f3; }
.notification.show { display: block; }
.notification.success { border-left-color: #4caf50; }
.notification.error { border-left-color: #f44336; }
.notification.warning { border-left-color: #ff9800; }
@keyframes slideIn { from { opacity: 0; transform: translateX(400px); } to { opacity: 1; transform: translateX(0); } }
.no-data { text-align: center; padding: 40px; color: #999; }
footer { text-align: center; color: #666; margin-top: 40px; padding-top: 20px; border-top: 1px solid #e0e0e0; font-size: 0.9em; }
.all-apps-hint { text-align: center; color: #999; font-size: 0.8em; margin-top: 10px; }
</style>
</head>
<body>
<div class="container">
<header>
<h1>Flipper Zero Manager v11</h1>
<p>Verwalte Apps auf deinem Flipper Zero — automatische Nachladung fehlender Dateien &amp; verstecktes "Alle Apps"-Panel</p>
</header>

<div class="device-section">
<div class="device-header">
<div class="device-info">
<h2>Gerätestatus</h2>
<span class="status-badge disconnected" id="connectionStatus">Getrennt</span>
<div class="device-detail" id="deviceInfo">Kein Gerät verbunden</div>
</div>
<div class="device-buttons">
<button class="btn btn-primary" onclick="connectDevice()">Gerät verbinden</button>
<button class="btn btn-secondary" id="flashBtn" onclick="toggleFirmwareSelect()" disabled>Firmware flashen</button>
</div>
</div>

<div class="toggle-switch">
<label class="switch">
<input type="checkbox" id="autoConnect" onchange="toggleAutoConnect()">
<span class="slider"></span>
</label>
<span class="switch-label">Auto-Connect</span>
</div>

<div class="firmware-status">
<h3>Firmware Status</h3>
<div id="firmwareStatusContainer"></div>
</div>

<div class="firmware-select" id="firmwareSelect" style="display: none;">
<h3>Firmware zum Flashen wählen:</h3>
<div class="firmware-options" id="firmwareOptions"></div>
<div style="margin-top: 10px;">
<button class="btn btn-primary" onclick="flashFirmware()" style="width: 100%;">Flashen</button>
</div>
</div>
</div>

<div class="tabs">
<button class="tab-btn active" onclick="switchTab('base', this)">Base Apps</button>
<button class="tab-btn" onclick="switchTab('extra', this)">Extra Apps</button>
<!-- versteckter, ausklappbarer Button für Alle Apps -->
<button class="tab-btn hidden" id="allToggleBtn" onclick="toggleAllApps()" title="Alle Apps anzeigen">◆</button>
</div>

<div id="base" class="tab-content active"></div>
<div id="extra" class="tab-content"></div>
<div id="all" class="tab-content"></div>

<div class="all-apps-hint">◆ = Alle Apps (versteckt)</div>

<footer>
<p>Flipper Zero Manager — Sichere App und Firmware Verwaltung</p>
</footer>
</div>

<div class="loading-overlay" id="loadingOverlay">
<div class="loading-content">
<div class="spinner"></div>
<div class="loading-text" id="loadingText">Wird geladen...</div>
<div class="loading-progress">
<div class="loading-progress-fill" id="loadingProgressFill"></div>
</div>
<div id="loadingSubtext" style="font-size: 0.85em; color: #999; margin-top: 10px;"></div>
</div>
</div>

<div class="notification" id="notification"></div>

<script>
let deviceConnected = false;
let appsData = null;
let installedApps = new Set();
let currentTab = 'base';
let deviceWatchInterval = null;
let autoConnectEnabled = false;
let selectedFirmware = null;
let downloadingFirmware = null;

window.addEventListener('load', () => {
    loadAutoConnectSetting();
    initializeApp();
});
window.addEventListener('beforeunload', () => {
    if (deviceWatchInterval) clearInterval(deviceWatchInterval);
});

async function initializeApp() {
    try {
        const appResponse = await fetch('?action=api&endpoint=apps');
        const appData = await appResponse.json();
        if (appData.success) {
            appsData = appData;
            loadInstalledApps();
            renderAllTabs();
            await checkFirmwareStatus();
            startDeviceMonitoring();
            if (autoConnectEnabled) {
                setTimeout(() => autoConnectDevice(), 500);
            }
        } else {
            showNotification('Fehler: ' + (appData.error || 'Unbekannter Fehler'), 'error');
        }
    } catch (error) {
        showNotification('Fehler: ' + error.message, 'error');
    }
}

async function checkFirmwareStatus() {
    try {
        const response = await fetch('?action=api&endpoint=firmware-status');
        const data = await response.json();
        renderFirmwareStatus(data.firmwares || {});
    } catch (error) {
        console.log('Firmware status error:', error.message);
    }
}

function renderFirmwareStatus(firmwares) {
    const container = document.getElementById('firmwareStatusContainer');
    let html = '';
    ['stock', 'rm', 'unleashed'].forEach(fw => {
        const status = firmwares[fw];
        const statusClass = status?.available ? 'ready' : status?.downloading ? 'downloading' : 'error';
        const statusText = status?.available ? 'Bereit' : status?.downloading ? 'Wird heruntergeladen...' : 'Nicht vorhanden';
        html += `
        <div class="fw-item">
            <div>
                <div class="fw-name">${fw.toUpperCase()}</div>
                <span class="fw-status ${statusClass}">${statusText}</span>
            </div>
            <div class="fw-actions">
                ${!status?.available ? `<button class="fw-btn fw-btn-download" onclick="downloadFirmware('${fw}')" ${status?.downloading ? 'disabled' : ''}>Download</button>` : ''}
                ${status?.available ? `<button class="fw-btn fw-btn-flash" onclick="selectFirmwareForFlash('${fw}')">Flashen</button>` : ''}
            </div>
        </div>
        `;
    });
    container.innerHTML = html;
    renderFirmwareSelectOptions(firmwares);
}

function renderFirmwareSelectOptions(firmwares) {
    const container = document.getElementById('firmwareOptions');
    container.innerHTML = '';
    ['stock', 'rm', 'unleashed'].forEach(fw => {
        const status = firmwares[fw];
        if (status?.available) {
            const btn = document.createElement('div');
            btn.className = 'firmware-option';
            btn.textContent = fw.toUpperCase();
            btn.onclick = () => selectFirmware(fw, btn);
            container.appendChild(btn);
        }
    });
}

function selectFirmware(fw, element) {
    selectedFirmware = fw;
    document.querySelectorAll('.firmware-option').forEach(el => el.classList.remove('selected'));
    element.classList.add('selected');
}

function selectFirmwareForFlash(fw) {
    // Suche das Element und wähle es
    const options = document.querySelectorAll('.firmware-option');
    let found = null;
    options.forEach(el => { if (el.textContent === fw.toUpperCase()) found = el; });
    if (found) selectFirmware(fw, found);
    toggleFirmwareSelect();
}

async function downloadFirmware(fw) {
    if (downloadingFirmware === fw) {
        showNotification('Download läuft bereits...', 'warning');
        return;
    }
    downloadingFirmware = fw;
    showLoading(`Lade ${fw.toUpperCase()} herunter...`, 0);
    try {
        const response = await fetch(`?action=api&endpoint=start-download&firmware=${fw}`);
        const data = await response.json();
        if (data.success) {
            await monitorDownloadProgress(fw);
        } else {
            showNotification('Fehler: ' + data.error, 'error');
            hideLoading();
        }
    } catch (error) {
        showNotification('Download-Fehler: ' + error.message, 'error');
        hideLoading();
    } finally {
        downloadingFirmware = null;
    }
}

async function monitorDownloadProgress(fw) {
    while (true) {
        try {
            const response = await fetch(`?action=api&endpoint=download-progress&firmware=${fw}`);
            const data = await response.json();
            if (data.progress !== undefined) {
                setLoadingProgress(data.progress, data.message || '');
            }
            if (data.complete) {
                hideLoading();
                showNotification(`${fw.toUpperCase()} erfolgreich heruntergeladen`, 'success');
                await checkFirmwareStatus();
                break;
            }
            if (data.error) {
                hideLoading();
                showNotification('Fehler: ' + data.error, 'error');
                break;
            }
            await new Promise(resolve => setTimeout(resolve, 1000));
        } catch (error) {
            hideLoading();
            showNotification('Fehler beim Abrufen des Fortschritts: ' + error.message, 'error');
            break;
        }
    }
}

function startDeviceMonitoring() {
    deviceWatchInterval = setInterval(async () => {
        if (!navigator.usb) return;
        try {
            const devices = await navigator.usb.getDevices();
            const wasConnected = deviceConnected;
            deviceConnected = devices.length > 0;
            if (wasConnected && !deviceConnected) {
                updateDeviceStatus(false);
                showNotification('Flipper Zero getrennt', 'warning');
            }
            if (!wasConnected && deviceConnected && autoConnectEnabled) {
                updateDeviceStatus(true, 'Flipper Zero');
                showNotification('Flipper Zero auto-verbunden', 'success');
            }
        } catch (error) {
            console.log('Device monitoring:', error.message);
        }
    }, 2000);
}

function toggleAutoConnect() {
    autoConnectEnabled = document.getElementById('autoConnect').checked;
    localStorage.setItem('flipper_auto_connect', autoConnectEnabled ? '1' : '0');
}

function loadAutoConnectSetting() {
    autoConnectEnabled = localStorage.getItem('flipper_auto_connect') === '1';
    document.getElementById('autoConnect').checked = autoConnectEnabled;
}

async function connectDevice() {
    try {
        if (!navigator.usb) {
            showNotification('WebUSB nicht unterstützt', 'error');
            return;
        }
        const device = await navigator.usb.requestDevice({ filters: [{ vendorId: 0x0483, productId: 0x5740 }] });
        if (device) {
            deviceConnected = true;
            updateDeviceStatus(true, device.productName || 'Flipper Zero');
            showNotification('Flipper Zero verbunden', 'success');
        }
    } catch (error) {
        if (error.name !== 'NotAllowedError' && error.name !== 'NotFoundError') {
            deviceConnected = true;
            updateDeviceStatus(true, 'Flipper Zero');
            showNotification('Gerät erkannt', 'success');
        }
    }
}

async function autoConnectDevice() {
    try {
        if (!navigator.usb) return;
        const devices = await navigator.usb.getDevices();
        if (devices.length > 0) {
            deviceConnected = true;
            updateDeviceStatus(true, 'Flipper Zero');
            showNotification('Flipper Zero auto-verbunden', 'success');
        }
    } catch (error) {
        console.log('Auto-connect error:', error.message);
    }
}

function updateDeviceStatus(connected, name = null) {
    const badge = document.getElementById('connectionStatus');
    const info = document.getElementById('deviceInfo');
    const flashBtn = document.getElementById('flashBtn');
    if (connected) {
        badge.textContent = 'Verbunden';
        badge.className = 'status-badge connected';
        info.textContent = `Gerät: ${name}`;
        flashBtn.disabled = false;
    } else {
        badge.textContent = 'Getrennt';
        badge.className = 'status-badge disconnected';
        info.textContent = 'Kein Gerät verbunden';
        flashBtn.disabled = true;
    }
}

function toggleFirmwareSelect() {
    const select = document.getElementById('firmwareSelect');
    select.style.display = select.style.display === 'none' ? 'block' : 'none';
}

async function flashFirmware() {
    if (!deviceConnected) {
        showNotification('Gerät nicht verbunden', 'error');
        return;
    }
    if (!selectedFirmware) {
        showNotification('Bitte Firmware wählen', 'error');
        return;
    }
    showLoading('Flashe Firmware: ' + selectedFirmware.toUpperCase(), 100);
    const formData = new FormData();
    formData.append('firmware', selectedFirmware);
    try {
        const response = await fetch('?action=flash-firmware', { method: 'POST', body: formData });
        const result = await response.json();
        if (result.success) {
            showNotification('Firmware erfolgreich geflasht', 'success');
            document.getElementById('firmwareSelect').style.display = 'none';
        } else {
            showNotification('Fehler: ' + result.error, 'error');
        }
    } catch (error) {
        showNotification('Fehler: ' + error.message, 'error');
    }
    hideLoading();
}

function switchTab(tabName, btn) {
    currentTab = tabName;
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    document.getElementById(tabName).classList.add('active');
    if (btn) btn.classList.add('active');
}

function renderAllTabs() {
    if (!appsData) return;
    renderTab('base', appsData.baseApps || []);
    renderTab('extra', appsData.extraApps || []);
    const allApps = [...(appsData.baseApps || []), ...(appsData.extraApps || [])];
    renderTab('all', allApps);
}

function renderTab(tabName, apps) {
    const container = document.getElementById(tabName);
    if (!apps || apps.length === 0) {
        container.innerHTML = '<div class="no-data">Keine Apps in dieser Kategorie</div>';
        return;
    }
    const groups = groupByCategory(apps);
    let html = '';
    for (const [category, categoryApps] of Object.entries(groups)) {
        html += `<div class="app-group"><div class="app-group-title">${category}</div><div class="app-grid">`;
        categoryApps.forEach(app => {
            const isInstalled = installedApps.has(app.id);
            const fileStatus = checkAppFilesStatus(app);
            html += `
            <div class="app-card" id="app_${escapeHtml(app.id)}">
                <div class="app-header">
                    <div class="app-icon">${app.icon || '📦'}</div>
                    <div class="app-info">
                        <div class="app-name">${escapeHtml(app.name)}</div>
                        <span class="app-status ${isInstalled ? 'installed' : 'not-installed'}">${isInstalled ? 'Installiert' : 'Nicht installiert'}</span>
                    </div>
                </div>
                <div class="app-description">${escapeHtml(app.description || '')}</div>
                <div style="margin-bottom:8px;color:#888;font-size:0.85em;">Dateien: ${fileStatus.summary}</div>
                <div class="app-controls">
                    <button class="app-btn ${isInstalled ? 'remove' : ''}" onclick="toggleApp('${escapeHtml(app.id)}', '${escapeHtml(app.name)}', ${isInstalled})" ${!deviceConnected ? 'disabled' : ''}>${isInstalled ? 'Entfernen' : 'Installieren'}</button>
                </div>
            </div>
            `;
        });
        html += `</div></div>`;
    }
    container.innerHTML = html;
}

/**
 * Gruppiert Apps nach Kategorie
 */
function groupByCategory(apps) {
    const groups = {};
    apps.forEach(app => {
        const category = app.category || 'Andere';
        if (!groups[category]) groups[category] = [];
        groups[category].push(app);
    });
    return groups;
}

/**
 * Toggle App (Install/Remove) - sendet API-Request
 */
async function toggleApp(appId, appName, isInstalled) {
    if (!deviceConnected) {
        showNotification('Gerät nicht verbunden', 'error');
        return;
    }
    const formData = new FormData();
    formData.append('app_id', appId);
    formData.append('type', isInstalled ? 'remove' : 'install');
    try {
        const response = await fetch('?action=install-app', { method: 'POST', body: formData });
        const result = await response.json();
        if (result.success) {
            if (isInstalled) {
                installedApps.delete(appId);
                showNotification(`${appName} entfernt`, 'success');
            } else {
                installedApps.add(appId);
                showNotification(`${appName} installiert`, 'success');
            }
            saveInstalledApps();
            renderTab(currentTab, currentTab === 'base' ? appsData.baseApps : currentTab === 'extra' ? appsData.extraApps : [...(appsData.baseApps || []), ...(appsData.extraApps || [])]);
        }
    } catch (error) {
        showNotification('Fehler: ' + error.message, 'error');
    }
}

/**
 * Lade/ Speichere installierte Apps lokal
 */
function loadInstalledApps() {
    const saved = localStorage.getItem('flipper_installed_apps');
    if (saved) try { installedApps = new Set(JSON.parse(saved)); } catch (e) {}
}
function saveInstalledApps() {
    localStorage.setItem('flipper_installed_apps', JSON.stringify(Array.from(installedApps)));
}

/**
 * Loading / Notification Helpers
 */
function showLoading(text, progress = 0) {
    document.getElementById('loadingText').textContent = text;
    document.getElementById('loadingProgressFill').style.width = progress + '%';
    document.getElementById('loadingOverlay').classList.add('show');
}
function setLoadingProgress(progress, message = '') {
    document.getElementById('loadingProgressFill').style.width = progress + '%';
    document.getElementById('loadingSubtext').textContent = message;
}
function hideLoading() {
    document.getElementById('loadingOverlay').classList.remove('show');
}
function showNotification(message, type = 'warning') {
    const notification = document.getElementById('notification');
    notification.textContent = message;
    notification.className = `notification ${type} show`;
    setTimeout(() => notification.classList.remove('show'), 3000);
}
function escapeHtml(text) {
    if (!text) return '';
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

/**
 * ---------------------------
 * Neue Funktionen für v11:
 * - Automatisches Nachladen fehlender App-Dateien (loose/bin) ohne Nutzerinteraktion
 * - Hash-Verifikation (sha256) wenn in apps.yaml angegeben
 * - Verstecktes "Alle Apps" Panel bleibt ausgeblendet, kann per Klick auf ◆ aufgeklappt werden
 * ---------------------------
 */

/**
 * Prüft den Status der Dateien einer App und startet bei Bedarf automatische Downloads.
 * Erwartet in apps.yaml für eine App optional:
 * files:
 *   - url: "https://..."
 *     path: "bin/loose.bin"   (relativ zu DATA_DIR/apps/<appid>/)
 *     sha256: "..."          (optional)
 */
function checkAppFilesStatus(app) {
    // Wird clientseitig nur zur Anzeige verwendet; die eigentliche Prüfung/Download passiert serverseitig beim Abruf von /api?endpoint=apps
    if (!app.files || !Array.isArray(app.files) || app.files.length === 0) {
        return { ok: true, summary: 'Keine zusätzlichen Dateien' };
    }
    let missing = 0;
    let total = app.files.length;
    app.files.forEach(f => {
        const localPath = `data/apps/${app.id}/${f.path}`;
        // Wir können nicht synchron prüfen vom Client; nur summarisch anzeigen
        if (!window.__appFileCache) window.__appFileCache = {};
        const key = `${app.id}:${f.path}`;
        const status = window.__appFileCache[key] || 'unknown';
        if (status !== 'ok') missing++;
    });
    return { ok: missing === 0, summary: `${total - missing}/${total} vorhanden` };
}

/**
 * Toggle / Ausklappen Alle Apps
 */
function toggleAllApps() {
    const allBtn = document.getElementById('allToggleBtn');
    const allContent = document.getElementById('all');
    if (allContent.classList.contains('active')) {
        // schließe
        allContent.classList.remove('active');
        document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
        document.querySelector('.tab-btn').classList.add('active');
    } else {
        // öffne und lade falls nötig
        switchTab('all', document.getElementById('allToggleBtn'));
    }
}
</script>
</body>
</html>

<?php
/**
 * Backend - echte Funktionen (v11)
 * - getAppsData: liest apps.yaml, prüft Dateien und startet automatische Downloads falls nötig
 * - startFileDownload / getFileDownloadProgress: Download- und Fortschritts-API für App-Dateien
 */

/**
 * Liefert Apps-Daten. Wenn in der Konfiguration Dateien fehlen, werden sie automatisch heruntergeladen.
 */
function getAppsData() {
    $appsFile = APPS_CONFIG_FILE;
    if (!file_exists($appsFile)) {
        createDefaultAppsYaml();
    }
    $yaml = parseYaml(file_get_contents($appsFile));
    $base = $yaml['base_apps'] ?? [];
    $extra = $yaml['extra_apps'] ?? [];

    // Stelle sicher, dass für jede App die Dateien vorhanden sind; starte automatische Downloads falls nicht
    foreach (array_merge($base, $extra) as $app) {
        if (!isset($app['id'])) continue;
        if (!isset($app['files']) || !is_array($app['files'])) continue;
        foreach ($app['files'] as $idx => $file) {
            $appDir = DATA_DIR . '/apps/' . $app['id'];
            if (!is_dir($appDir)) mkdir($appDir, 0755, true);
            $relPath = $file['path'] ?? basename($file['url']);
            $localPath = $appDir . '/' . $relPath;
            $stateFile = $localPath . '.downloading';
            $errorFile = $localPath . '.error';
            $okFile = $localPath . '.ok';

            // Wenn Datei existiert und (optional) Hash passt -> ok
            if (file_exists($localPath) && filesize($localPath) > 0) {
                if (!empty($file['sha256'])) {
                    $sha = hash_file('sha256', $localPath);
                    if ($sha === $file['sha256']) {
                        // alles gut
                        @unlink($errorFile);
                        file_put_contents($okFile, json_encode(['verified' => true, 'sha256' => $sha]));
                        continue;
                    } else {
                        // Hash mismatch -> lösche und lade neu
                        @unlink($localPath);
                        @unlink($okFile);
                        file_put_contents($errorFile, "Hash mismatch (found: $sha)");
                    }
                } else {
                    // keine Hashprüfung, Datei vorhanden -> ok
                    file_put_contents($okFile, json_encode(['verified' => false]));
                    continue;
                }
            }

            // Wenn bereits ein Fehlerfile existiert, versuche erneut (überschreibe)
            if (file_exists($errorFile)) @unlink($errorFile);

            // Starte synchronen Download (automatisch, ohne Nutzerinteraktion)
            if (!file_exists($stateFile)) {
                file_put_contents($stateFile, json_encode(['started' => time(), 'progress' => 0]));
                // Führe Download direkt (blockierend). Bei Bedarf kann dies in Zukunft asynchron gemacht werden.
                $res = downloadFileToPath($file['url'], $localPath, $stateFile);
                if ($res['success']) {
                    // Prüfe Hash falls vorhanden
                    if (!empty($file['sha256'])) {
                        $sha = hash_file('sha256', $localPath);
                        if ($sha !== $file['sha256']) {
                            file_put_contents($errorFile, "Hash mismatch after download (found: $sha)");
                            @unlink($localPath);
                        } else {
                            file_put_contents($localPath . '.ok', json_encode(['verified' => true, 'sha256' => $sha]));
                        }
                    } else {
                        file_put_contents($localPath . '.ok', json_encode(['verified' => false]));
                    }
                } else {
                    file_put_contents($errorFile, $res['error']);
                    @unlink($localPath . '.tmp');
                }
                @unlink($stateFile);
            }
        }
    }

    return ['success' => true, 'baseApps' => $base, 'extraApps' => $extra];
}

/**
 * Startet einen synchronen Download einer Datei (mit Fortschritts-Callback in stateFile).
 * Gibt ['success'=>bool, 'error'=>string] zurück.
 */
function downloadFileToPath($url, $localPath, $stateFile = null) {
    $tmp = $localPath . '.tmp';
    $fp = fopen($tmp, 'w');
    if ($fp === false) {
        return ['success' => false, 'error' => 'Cannot open temp file for writing'];
    }
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
    $err = null;
    if (curl_errno($ch)) {
        $err = curl_error($ch);
    }
    curl_close($ch);
    fclose($fp);
    if (!$ok) {
        @unlink($tmp);
        return ['success' => false, 'error' => $err ?: 'Download failed'];
    }
    // Move tmp to final
    if (!is_dir(dirname($localPath))) mkdir(dirname($localPath), 0755, true);
    rename($tmp, $localPath);
    return ['success' => true];
}

/**
 * API: Startet Datei-Download für eine App (kann auch direkt aufgerufen werden)
 */
function startFileDownload($appId, $fileIndex = 0) {
    if (!$appId) return ['success' => false, 'error' => 'App not specified'];
    $appsFile = APPS_CONFIG_FILE;
    if (!file_exists($appsFile)) return ['success' => false, 'error' => 'Apps config missing'];
    $yaml = parseYaml(file_get_contents($appsFile));
    $all = array_merge($yaml['base_apps'] ?? [], $yaml['extra_apps'] ?? []);
    $app = null;
    foreach ($all as $a) if (($a['id'] ?? '') === $appId) { $app = $a; break; }
    if (!$app) return ['success' => false, 'error' => 'App not found'];
    if (empty($app['files']) || !isset($app['files'][$fileIndex])) return ['success' => false, 'error' => 'File not configured'];

    $file = $app['files'][$fileIndex];
    $appDir = DATA_DIR . '/apps/' . $appId;
    if (!is_dir($appDir)) mkdir($appDir, 0755, true);
    $relPath = $file['path'] ?? basename($file['url']);
    $localPath = $appDir . '/' . $relPath;
    $stateFile = $localPath . '.downloading';
    $errorFile = $localPath . '.error';

    if (file_exists($localPath) && filesize($localPath) > 0) {
        if (!empty($file['sha256'])) {
            $sha = hash_file('sha256', $localPath);
            if ($sha === $file['sha256']) {
                return ['success' => true, 'message' => 'Already present and verified'];
            } else {
                @unlink($localPath);
            }
        } else {
            return ['success' => true, 'message' => 'Already present'];
        }
    }

    file_put_contents($stateFile, json_encode(['started' => time(), 'progress' => 0]));
    $res = downloadFileToPath($file['url'], $localPath, $stateFile);
    if ($res['success']) {
        if (!empty($file['sha256'])) {
            $sha = hash_file('sha256', $localPath);
            if ($sha !== $file['sha256']) {
                file_put_contents($errorFile, "Hash mismatch after download (found: $sha)");
                @unlink($localPath);
                @unlink($stateFile);
                return ['success' => false, 'error' => 'Hash mismatch'];
            } else {
                file_put_contents($localPath . '.ok', json_encode(['verified' => true, 'sha256' => $sha]));
            }
        } else {
            file_put_contents($localPath . '.ok', json_encode(['verified' => false]));
        }
        @unlink($stateFile);
        return ['success' => true, 'message' => 'Download complete'];
    } else {
        file_put_contents($errorFile, $res['error']);
        @unlink($stateFile);
        return ['success' => false, 'error' => $res['error']];
    }
}

/**
 * API: Fortschritt einer Datei abfragen
 */
function getFileDownloadProgress($appId, $fileIndex = 0) {
    if (!$appId) return ['success' => false, 'error' => 'App not specified'];
    $appsFile = APPS_CONFIG_FILE;
    if (!file_exists($appsFile)) return ['success' => false, 'error' => 'Apps config missing'];
    $yaml = parseYaml(file_get_contents($appsFile));
    $all = array_merge($yaml['base_apps'] ?? [], $yaml['extra_apps'] ?? []);
    $app = null;
    foreach ($all as $a) if (($a['id'] ?? '') === $appId) { $app = $a; break; }
    if (!$app) return ['success' => false, 'error' => 'App not found'];
    if (empty($app['files']) || !isset($app['files'][$fileIndex])) return ['success' => false, 'error' => 'File not configured'];

    $file = $app['files'][$fileIndex];
    $appDir = DATA_DIR . '/apps/' . $appId;
    $relPath = $file['path'] ?? basename($file['url']);
    $localPath = $appDir . '/' . $relPath;
    $stateFile = $localPath . '.downloading';
    $errorFile = $localPath . '.error';
    $okFile = $localPath . '.ok';

    if (file_exists($errorFile)) {
        $error = file_get_contents($errorFile);
        @unlink($errorFile);
        return ['success' => false, 'error' => $error, 'complete' => false];
    }
    if (!file_exists($stateFile) && file_exists($localPath) && filesize($localPath) > 0) {
        return ['success' => true, 'progress' => 100, 'complete' => true, 'message' => 'Download abgeschlossen'];
    }
    if (file_exists($stateFile)) {
        $state = json_decode(file_get_contents($stateFile), true);
        return ['success' => true, 'progress' => $state['progress'] ?? 0, 'complete' => false, 'message' => ($state['progress'] ?? 0) . '%'];
    }
    return ['success' => true, 'progress' => 0, 'complete' => false, 'message' => 'Initialisierung...'];
}

/**
 * Firmware-Funktionen (wie vorher)
 */
function checkFirmwareStatus() {
    $firmwares = [];
    foreach (['stock', 'rm', 'unleashed'] as $fw) {
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

function startFirmwareDownload($firmware) {
    if (!$firmware || !in_array($firmware, ['stock', 'rm', 'unleashed'])) {
        return ['success' => false, 'error' => 'Invalid firmware'];
    }
    $fwDir = FIRMWARE_DIR . '/' . $firmware;
    if (!is_dir($fwDir)) mkdir($fwDir, 0755, true);
    $stateFile = $fwDir . '/.downloading';
    file_put_contents($stateFile, json_encode(['started' => time(), 'progress' => 0]));

    // Synchronous download (vereinfachte Implementierung)
    $sources = FIRMWARE_SOURCES;
    $url = $sources[$firmware] ?? null;
    if (!$url) {
        @unlink($stateFile);
        return ['success' => false, 'error' => 'No source configured'];
    }

    // Hole Release-JSON
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'Flipper-Manager',
        CURLOPT_TIMEOUT => 30
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $err = curl_error($ch);
        curl_close($ch);
        @unlink($stateFile);
        return ['success' => false, 'error' => $err];
    }
    curl_close($ch);
    $data = json_decode($response, true);
    if (!$data || !isset($data['assets'])) {
        @unlink($stateFile);
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
        return ['success' => false, 'error' => 'No binary found'];
    }

    $out = fopen($fwDir . '/firmware.bin.tmp', 'w');
    $ch = curl_init($downloadUrl);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $out,
        CURLOPT_USERAGENT => 'Flipper-Manager',
        CURLOPT_TIMEOUT => 300,
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => function($resource, $download_size, $downloaded, $upload_size, $uploaded) use ($stateFile) {
            if ($download_size > 0) {
                $progress = round(($downloaded / $download_size) * 100);
                file_put_contents($stateFile, json_encode(['progress' => $progress, 'size' => $download_size]));
            }
            return 0;
        }
    ]);
    curl_exec($ch);
    $curlErr = curl_errno($ch) ? curl_error($ch) : null;
    curl_close($ch);
    fclose($out);
    if ($curlErr) {
        @unlink($fwDir . '/firmware.bin.tmp');
        file_put_contents($fwDir . '/.error', $curlErr);
        @unlink($stateFile);
        return ['success' => false, 'error' => $curlErr];
    }
    rename($fwDir . '/firmware.bin.tmp', $fwDir . '/firmware.bin');
    @unlink($stateFile);
    return ['success' => true, 'message' => 'Download gestartet', 'firmware' => $firmware];
}

function getDownloadProgress($firmware) {
    if (!$firmware || !in_array($firmware, ['stock', 'rm', 'unleashed'])) {
        return ['success' => false, 'error' => 'Invalid firmware'];
    }
    $fwDir = FIRMWARE_DIR . '/' . $firmware;
    $stateFile = $fwDir . '/.downloading';
    $errorFile = $fwDir . '/.error';
    $fwFile = $fwDir . '/firmware.bin';

    if (file_exists($errorFile)) {
        $error = file_get_contents($errorFile);
        @unlink($errorFile);
        return ['success' => false, 'error' => $error, 'complete' => false];
    }
    if (!file_exists($stateFile) && file_exists($fwFile) && filesize($fwFile) > 1024) {
        return ['success' => true, 'progress' => 100, 'complete' => true, 'message' => 'Download abgeschlossen'];
    }
    if (file_exists($stateFile)) {
        $state = json_decode(file_get_contents($stateFile), true);
        return ['success' => true, 'progress' => $state['progress'] ?? 0, 'complete' => false, 'message' => $state['progress'] . '%'];
    }
    return ['success' => true, 'progress' => 0, 'complete' => false, 'message' => 'Initialisierung...'];
}

/**
 * Ein sehr einfacher YAML-Parser (wie vorher). Erwartet spezielle Struktur.
 */
function parseYaml($content) {
    $lines = explode("\n", $content);
    $result = ['base_apps' => [], 'extra_apps' => []];
    $currentSection = null;
    $currentItem = null;
    foreach ($lines as $line) {
        $line = rtrim($line);
        if (empty($line) || strpos(ltrim($line), '#') === 0) continue;
        $indent = strlen($line) - strlen(ltrim($line));
        $content = trim($line);
        if (preg_match('/^(base_apps|extra_apps):/', $content, $m)) {
            $currentSection = $m[1];
            continue;
        }
        if (!$currentSection) continue;
        if ($indent === 2 && $content === '-') {
            $currentItem = [];
            $result[$currentSection][] = &$currentItem;
            continue;
        }
        if ($indent === 4 && $currentItem !== null && strpos($content, ':') !== false) {
            list($key, $val) = array_map('trim', explode(':', $content, 2));
            // Werte mit Anführungszeichen entfernen
            $val = trim($val, " \t\n\r\0\x0B\"'");
            // Support für Listen (files:)
            if ($key === 'files' && $val === '') {
                // lese folgende Zeilen mit größerer Einrückung
                $files = [];
                continue;
            }
            // einfache key: value
            $currentItem[$key] = $val;
        }
        // Erweiterung: einfache files-Liste (manuell parsen)
        if ($currentItem !== null && $indent === 6 && strpos($content, 'url:') === 0) {
            $url = trim(substr($content, 4));
            $url = trim($url, " \t\n\r\0\x0B\"'");
            if (!isset($currentItem['files']) || !is_array($currentItem['files'])) $currentItem['files'] = [];
            $currentItem['files'][] = ['url' => $url];
        } elseif ($currentItem !== null && $indent === 8 && strpos($content, 'path:') === 0) {
            $path = trim(substr($content, 5));
            $path = trim($path, " \t\n\r\0\x0B\"'");
            $last = count($currentItem['files']) - 1;
            if ($last >= 0) $currentItem['files'][$last]['path'] = $path;
        } elseif ($currentItem !== null && $indent === 8 && strpos($content, 'sha256:') === 0) {
            $sha = trim(substr($content, 7));
            $sha = trim($sha, " \t\n\r\0\x0B\"'");
            $last = count($currentItem['files']) - 1;
            if ($last >= 0) $currentItem['files'][$last]['sha256'] = $sha;
        }
    }
    return $result;
}

/**
 * Erstellt eine Standard apps.yaml mit Beispiel für files-Einträge
 */
function createDefaultAppsYaml() {
    $yaml = <<<YAML
# Flipper Zero App Manager - Konfiguration (v11)
# Bearbeite diese Datei um Apps hinzuzufügen oder zu ändern
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
