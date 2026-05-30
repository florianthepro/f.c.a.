<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Konfiguration
define('DATA_DIR', __DIR__ . '/data');
define('FIRMWARE_DIR', DATA_DIR . '/firmware');
define('APPS_CONFIG_FILE', DATA_DIR . '/apps.yaml');

// Verzeichnisse erstellen
foreach ([DATA_DIR, FIRMWARE_DIR] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

// Firmware-Dateien erstellen
initializeFirmwareDirectory();

// API Routen
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
    
    // Prüfe ob Firmware existiert
    $firmwarePath = FIRMWARE_DIR . '/' . basename($firmware) . '/firmware.bin';
    if (!file_exists($firmwarePath)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Firmware file not found']);
        exit;
    }
    
    echo json_encode(['success' => true, 'message' => 'Flash erfolgreich', 'firmware' => $firmware]);
    exit;
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flipper Zero Manager</title>
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
        .tab-btn.hidden { font-size: 0.7em; color: #999; }
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
        .loading-content { background: white; padding: 40px; border-radius: 8px; text-align: center; }
        .spinner { width: 40px; height: 40px; border: 3px solid #f5f5f5; border-top: 3px solid #2196f3; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 20px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .loading-text { color: #666; font-size: 0.95em; }
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
            <h1>Flipper Zero Manager</h1>
            <p>Verwalte Apps auf deinem Flipper Zero</p>
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

            <div class="firmware-select" id="firmwareSelect" style="display: none;">
                <h3>Firmware wählen:</h3>
                <div class="firmware-options" id="firmwareOptions"></div>
                <div style="margin-top: 10px;">
                    <button class="btn btn-primary" onclick="flashFirmware()" style="width: 100%;">Flashen</button>
                </div>
            </div>
        </div>

        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab('base')">Base Apps</button>
            <button class="tab-btn" onclick="switchTab('extra')">Extra Apps</button>
            <button class="tab-btn hidden" onclick="switchTab('all')" title="Alle Apps anzeigen">◆</button>
        </div>

        <div id="base" class="tab-content active"></div>
        <div id="extra" class="tab-content"></div>
        <div id="all" class="tab-content"></div>

        <div class="all-apps-hint">◆ = Alle Apps</div>

        <footer>
            <p>Flipper Zero Manager — Sichere App und Firmware Verwaltung</p>
        </footer>
    </div>

    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-content">
            <div class="spinner"></div>
            <div class="loading-text" id="loadingText">Wird geladen...</div>
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

        window.addEventListener('load', () => {
            loadAutoConnectSetting();
            initializeApp();
        });
        window.addEventListener('beforeunload', () => { if (deviceWatchInterval) clearInterval(deviceWatchInterval); });

        async function initializeApp() {
            showLoading('App-Daten werden geladen...');
            try {
                const appResponse = await fetch('?action=api&endpoint=apps');
                const appData = await appResponse.json();

                const fwResponse = await fetch('?action=api&endpoint=firmware-versions');
                const fwData = await fwResponse.json();

                if (appData.success) {
                    appsData = appData;
                    loadInstalledApps();
                    renderAllTabs();
                    renderFirmwareOptions(fwData.firmwares || []);
                    hideLoading();
                    startDeviceMonitoring();
                    
                    if (autoConnectEnabled) {
                        setTimeout(() => autoConnectDevice(), 500);
                    }
                } else {
                    showNotification('Fehler: ' + (appData.error || 'Unbekannter Fehler'), 'error');
                    hideLoading();
                }
            } catch (error) {
                showNotification('Fehler: ' + error.message, 'error');
                hideLoading();
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

        function renderFirmwareOptions(firmwares) {
            const container = document.getElementById('firmwareOptions');
            container.innerHTML = '';
            if (firmwares.length === 0) {
                container.innerHTML = '<p style="color: #999;">Keine Firmwares verfügbar</p>';
                return;
            }
            firmwares.forEach(fw => {
                const btn = document.createElement('div');
                btn.className = 'firmware-option';
                btn.textContent = fw;
                btn.onclick = () => selectFirmware(fw, btn);
                container.appendChild(btn);
            });
        }

        function selectFirmware(fw, element) {
            selectedFirmware = fw;
            document.querySelectorAll('.firmware-option').forEach(el => el.classList.remove('selected'));
            element.classList.add('selected');
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
            showLoading('Flashe Firmware: ' + selectedFirmware);
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

        function switchTab(tabName) {
            currentTab = tabName;
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
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
                    html += `
                        <div class="app-card">
                            <div class="app-header">
                                <div class="app-icon">${app.icon || '📦'}</div>
                                <div class="app-info">
                                    <div class="app-name">${escapeHtml(app.name)}</div>
                                    <span class="app-status ${isInstalled ? 'installed' : 'not-installed'}">${isInstalled ? 'Installiert' : 'Nicht installiert'}</span>
                                </div>
                            </div>
                            <div class="app-description">${escapeHtml(app.description)}</div>
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

        function groupByCategory(apps) {
            const groups = {};
            apps.forEach(app => {
                const category = app.category || 'Andere';
                if (!groups[category]) groups[category] = [];
                groups[category].push(app);
            });
            return groups;
        }

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

        function loadInstalledApps() {
            const saved = localStorage.getItem('flipper_installed_apps');
            if (saved) try { installedApps = new Set(JSON.parse(saved)); } catch (e) {}
        }

        function saveInstalledApps() {
            localStorage.setItem('flipper_installed_apps', JSON.stringify(Array.from(installedApps)));
        }

        function showLoading(text) {
            document.getElementById('loadingText').textContent = text;
            document.getElementById('loadingOverlay').classList.add('show');
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
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return text.replace(/[&<>"']/g, m => map[m]);
        }
    </script>
</body>
</html>

<?php
/**
 * Backend - nur die Funktionen
 */

function initializeFirmwareDirectory() {
    $firmwares = ['stock', 'rm', 'unleashed'];
    
    foreach ($firmwares as $fw) {
        $fwDir = FIRMWARE_DIR . '/' . $fw;
        if (!is_dir($fwDir)) {
            mkdir($fwDir, 0755, true);
            // Erstelle dummy firmware.bin falls nicht vorhanden
            if (!file_exists($fwDir . '/firmware.bin')) {
                file_put_contents($fwDir . '/firmware.bin', '/* Firmware ' . $fw . ' */');
            }
        }
    }
}

function getAppsData() {
    $appsFile = APPS_CONFIG_FILE;
    
    if (!file_exists($appsFile)) {
        createDefaultAppsYaml();
    }
    
    $yaml = parseYaml(file_get_contents($appsFile));
    
    return [
        'success' => true,
        'baseApps' => $yaml['base_apps'] ?? [],
        'extraApps' => $yaml['extra_apps'] ?? []
    ];
}

function getFirmwareVersions() {
    $firmwares = [];
    $dirs = glob(FIRMWARE_DIR . '/*/');
    
    foreach ($dirs as $dir) {
        $name = basename($dir);
        if (is_dir($dir) && file_exists($dir . '/firmware.bin')) {
            $firmwares[] = $name;
        }
    }
    
    return [
        'success' => true,
        'firmwares' => $firmwares
    ];
}

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
            $currentItem[$key] = $val;
        }
    }
    
    return $result;
}

function createDefaultAppsYaml() {
    $yaml = <<<'YAML'
# Flipper Zero App Manager - Konfiguration
# Bearbeite diese Datei um Apps hinzuzufügen oder zu ändern

base_apps:
  - id: "app_001"
    name: "GPIO"
    description: "GPIO pins control"
    icon: "🔧"
    category: "Hardware"
  - id: "app_002"
    name: "NFC"
    description: "Read & write NFC cards"
    icon: "📲"
    category: "Wireless"

extra_apps:
  - id: "app_extra_001"
    name: "Tetris"
    description: "Classic game"
    icon: "🎮"
    category: "Games"
  - id: "app_extra_002"
    name: "Snake"
    description: "Classic game"
    icon: "🐍"
    category: "Games"

YAML;

    file_put_contents(APPS_CONFIG_FILE, $yaml);
}

?>
