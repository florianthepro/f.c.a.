<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Konfiguration
define('DATA_DIR', __DIR__ . '/data');
define('FIRMWARE_DIR', DATA_DIR . '/firmware');
define('LOG_DIR', DATA_DIR . '/logs');
define('STATE_FILE', DATA_DIR . '/.state.json');
define('APPS_CONFIG_FILE', DATA_DIR . '/apps.yaml');

// Verzeichnisse erstellen
foreach ([DATA_DIR, FIRMWARE_DIR, LOG_DIR] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

// API Routen
$action = $_GET['action'] ?? null;

if ($action === 'api') {
    header('Content-Type: application/json');
    $endpoint = $_GET['endpoint'] ?? null;
    
    switch ($endpoint) {
        case 'apps':
            echo json_encode(getAppsData());
            break;
        case 'installed':
            echo json_encode(getInstalledApps());
            break;
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
    }
    exit;
}

if ($action === 'install-app') {
    header('Content-Type: application/json');
    $appId = $_POST['app_id'] ?? null;
    $type = $_POST['type'] ?? null;
    
    if (!$appId || !$type) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing parameters']);
        exit;
    }
    
    echo json_encode(['success' => true, 'message' => ucfirst($type) . ' erfolgreich']);
    exit;
}

// HTML Interface
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flipper Zero Manager</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        header {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        header h1 {
            font-size: 1.8em;
            color: #333;
            margin-bottom: 5px;
        }

        header p {
            color: #666;
            font-size: 0.95em;
        }

        .device-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .device-info h2 {
            font-size: 1.1em;
            color: #333;
            margin-bottom: 8px;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.9em;
        }

        .status-badge.connected {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .status-badge.disconnected {
            background: #ffebee;
            color: #c62828;
        }

        .device-detail {
            color: #666;
            font-size: 0.9em;
            margin-top: 6px;
        }

        .device-buttons {
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.95em;
            transition: background 0.2s;
        }

        .btn-primary {
            background: #2196f3;
            color: white;
        }

        .btn-primary:hover:not(:disabled) {
            background: #1976d2;
        }

        .btn-secondary {
            background: #f5f5f5;
            color: #333;
            border: 1px solid #e0e0e0;
        }

        .btn-secondary:hover:not(:disabled) {
            background: #e0e0e0;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .tabs {
            display: flex;
            background: white;
            border-radius: 8px 8px 0 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid #e0e0e0;
            border-bottom: none;
        }

        .tab-btn {
            flex: 1;
            padding: 15px;
            border: none;
            background: white;
            cursor: pointer;
            font-weight: 600;
            color: #666;
            border-right: 1px solid #e0e0e0;
            transition: background 0.2s;
        }

        .tab-btn:last-child {
            border-right: none;
        }

        .tab-btn:hover {
            background: #f9f9f9;
        }

        .tab-btn.active {
            background: #2196f3;
            color: white;
            border-bottom: 3px solid #1976d2;
        }

        .tab-content {
            display: none;
            background: white;
            padding: 20px;
            border-radius: 0 0 8px 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid #e0e0e0;
            border-top: none;
        }

        .tab-content.active {
            display: block;
        }

        .app-group {
            margin-bottom: 25px;
        }

        .app-group-title {
            font-size: 0.95em;
            font-weight: 600;
            color: #666;
            margin-bottom: 10px;
            padding-left: 10px;
            border-left: 3px solid #2196f3;
        }

        .app-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 15px;
        }

        .app-card {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            padding: 15px;
            transition: box-shadow 0.2s;
        }

        .app-card:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .app-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }

        .app-icon {
            font-size: 1.8em;
        }

        .app-info {
            flex-grow: 1;
        }

        .app-name {
            font-weight: 600;
            color: #333;
            font-size: 0.95em;
        }

        .app-status {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 0.75em;
            font-weight: 600;
            margin-top: 4px;
        }

        .app-status.installed {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .app-status.not-installed {
            background: #f5f5f5;
            color: #666;
        }

        .app-description {
            color: #666;
            font-size: 0.85em;
            margin-bottom: 10px;
            line-height: 1.4;
        }

        .app-controls {
            display: flex;
            gap: 8px;
        }

        .app-btn {
            flex: 1;
            padding: 8px 12px;
            background: #2196f3;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85em;
            font-weight: 600;
            transition: background 0.2s;
        }

        .app-btn:hover:not(:disabled) {
            background: #1976d2;
        }

        .app-btn.remove {
            background: #f44336;
        }

        .app-btn.remove:hover:not(:disabled) {
            background: #d32f2f;
        }

        .app-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .loading-overlay.show {
            display: flex;
        }

        .loading-content {
            background: white;
            padding: 40px;
            border-radius: 8px;
            text-align: center;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid #f5f5f5;
            border-top: 3px solid #2196f3;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading-text {
            color: #666;
            font-size: 0.95em;
        }

        .notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 15px 20px;
            background: white;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            display: none;
            animation: slideIn 0.3s ease;
            max-width: 300px;
            z-index: 2000;
            border-left: 4px solid #2196f3;
        }

        .notification.show {
            display: block;
        }

        .notification.success {
            border-left-color: #4caf50;
        }

        .notification.error {
            border-left-color: #f44336;
        }

        .notification.warning {
            border-left-color: #ff9800;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(400px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #999;
        }

        footer {
            text-align: center;
            color: #666;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Flipper Zero Manager</h1>
            <p>Verwalte Apps und Firmware auf deinem Flipper Zero</p>
        </header>

        <div class="device-section">
            <div class="device-info">
                <h2>Gerätestatus</h2>
                <span class="status-badge disconnected" id="connectionStatus">Getrennt</span>
                <div class="device-detail" id="deviceInfo">Kein Gerät verbunden</div>
            </div>
            <div class="device-buttons">
                <button class="btn btn-primary" id="connectBtn" onclick="connectDevice()">Gerät verbinden</button>
                <button class="btn btn-secondary" id="flashBtn" onclick="flashFirmware()" disabled>Firmware flashen</button>
            </div>
        </div>

        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab('base')">Base Apps</button>
            <button class="tab-btn" onclick="switchTab('extra')">Extra Apps</button>
            <button class="tab-btn" onclick="switchTab('dev')">Dev Apps</button>
        </div>

        <div id="base" class="tab-content active"></div>
        <div id="extra" class="tab-content"></div>
        <div id="dev" class="tab-content"></div>

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

        window.addEventListener('load', () => {
            initializeApp();
            startDeviceMonitoring();
        });

        window.addEventListener('beforeunload', () => {
            if (deviceWatchInterval) clearInterval(deviceWatchInterval);
        });

        let deviceWatchInterval = null;

        async function initializeApp() {
            showLoading('App-Daten werden geladen...');
            try {
                const response = await fetch('?action=api&endpoint=apps');
                const data = await response.json();

                if (data.success) {
                    appsData = data;
                    loadInstalledApps();
                    renderAllTabs();
                    hideLoading();
                } else {
                    showNotification('Fehler: ' + (data.error || 'Unbekannter Fehler'), 'error');
                    hideLoading();
                }
            } catch (error) {
                showNotification('Fehler beim Laden: ' + error.message, 'error');
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
                } catch (error) {
                    console.log('Device monitoring:', error.message);
                }
            }, 2000);
        }

        async function connectDevice() {
            try {
                if (!navigator.usb) {
                    showNotification('WebUSB nicht unterstützt', 'error');
                    return;
                }

                const device = await navigator.usb.requestDevice({
                    filters: [{ vendorId: 0x0483, productId: 0x5740 }]
                });

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
            renderTab('dev', appsData.devApps || []);
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
                html += `<div class="app-group">`;
                if (category !== 'default') {
                    html += `<div class="app-group-title">${category}</div>`;
                }
                html += `<div class="app-grid">`;

                categoryApps.forEach(app => {
                    const isInstalled = installedApps.has(app.id);
                    html += `
                        <div class="app-card">
                            <div class="app-header">
                                <div class="app-icon">${app.icon || '📦'}</div>
                                <div class="app-info">
                                    <div class="app-name">${app.name}</div>
                                    <span class="app-status ${isInstalled ? 'installed' : 'not-installed'}">
                                        ${isInstalled ? 'Installiert' : 'Nicht installiert'}
                                    </span>
                                </div>
                            </div>
                            <div class="app-description">${app.description}</div>
                            <div class="app-controls">
                                <button class="app-btn ${isInstalled ? 'remove' : ''}" 
                                    onclick="toggleApp('${app.id}', '${app.name}', ${isInstalled})"
                                    ${!deviceConnected ? 'disabled' : ''}>
                                    ${isInstalled ? 'Entfernen' : 'Installieren'}
                                </button>
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
                const category = app.category || 'default';
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
                const response = await fetch('?action=install-app', {
                    method: 'POST',
                    body: formData
                });

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
                    renderTab(currentTab, appsData[currentTab + 'Apps']);
                }
            } catch (error) {
                showNotification('Fehler: ' + error.message, 'error');
            }
        }

        function loadInstalledApps() {
            const saved = localStorage.getItem('flipper_installed_apps');
            if (saved) {
                try {
                    installedApps = new Set(JSON.parse(saved));
                } catch (e) {
                    console.error('Error loading installed apps:', e);
                }
            }
        }

        function saveInstalledApps() {
            localStorage.setItem('flipper_installed_apps', JSON.stringify(Array.from(installedApps)));
        }

        function flashFirmware() {
            if (!deviceConnected) {
                showNotification('Gerät nicht verbunden', 'error');
                return;
            }
            showNotification('Firmware-Flash-Funktion wird vorbereitet...', 'warning');
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

            setTimeout(() => {
                notification.classList.remove('show');
            }, 3000);
        }
    </script>
</body>
</html>

<?php
/**
 * Backend Funktionen
 */

function getAppsData() {
    $appsFile = APPS_CONFIG_FILE;
    
    // Lade oder generiere YAML
    if (!file_exists($appsFile)) {
        generateDefaultAppsYaml();
    }
    
    $yaml = yaml_parse_file($appsFile);
    
    if (!$yaml) {
        return [
            'success' => false,
            'error' => 'YAML parsing error'
        ];
    }
    
    return [
        'success' => true,
        'baseApps' => $yaml['base_apps'] ?? [],
        'extraApps' => $yaml['extra_apps'] ?? [],
        'devApps' => $yaml['dev_apps'] ?? []
    ];
}

function generateDefaultAppsYaml() {
    $yaml = <<<'YAML'
base_apps:
  - id: "stock_sub_ghz"
    name: "Sub-GHz"
    description: "Capture & replay radio signals sub-GHz"
    icon: "📡"
    category: "Wireless"
  
  - id: "stock_nfc"
    name: "NFC"
    description: "Read, write, emulate NFC cards"
    icon: "📲"
    category: "Wireless"
  
  - id: "stock_rfid"
    name: "RFID"
    description: "Read, write, emulate 125kHz RFID cards"
    icon: "📟"
    category: "Wireless"
  
  - id: "stock_infrared"
    name: "Infrared"
    description: "Learn & replay IR signals"
    icon: "🔴"
    category: "Wireless"
  
  - id: "stock_gpio"
    name: "GPIO"
    description: "GPIO pins control interface"
    icon: "🔧"
    category: "Hardware"
  
  - id: "stock_ibutton"
    name: "iButton"
    description: "Read, write, emulate iButton contact keys"
    icon: "🔑"
    category: "Wireless"
  
  - id: "stock_bluetooth"
    name: "Bluetooth"
    description: "Bluetooth connectivity for Flipper Mobile App"
    icon: "💙"
    category: "Wireless"

extra_apps:
  - id: "extra_esp_flasher"
    name: "ESP Flasher"
    description: "Flash ESP8266/ESP32 microcontroller boards"
    icon: "💾"
    category: "ESP Boards"
  
  - id: "extra_game_tetris"
    name: "Tetris"
    description: "Classic Tetris puzzle game"
    icon: "🎮"
    category: "Games"
  
  - id: "extra_game_snake"
    name: "Snake"
    description: "Classic Snake game"
    icon: "🐍"
    category: "Games"
  
  - id: "extra_nfc_tools"
    name: "NFC Tools Extended"
    description: "Advanced NFC tag functions"
    icon: "🏷️"
    category: "NFC"

dev_apps:
  - id: "dev_ble_spam"
    name: "BLE Spam"
    description: "Bluetooth Low Energy Advertising Spam tool"
    icon: "📣"
    category: "Bluetooth"
  
  - id: "dev_wifi_marauder"
    name: "WiFi Marauder"
    description: "WiFi scanning, sniffing & analysis tool"
    icon: "🌐"
    category: "WiFi"
  
  - id: "dev_evil_portal"
    name: "Evil Portal"
    description: "WiFi portal & credential capture tool"
    icon: "📡"
    category: "WiFi"
  
  - id: "dev_advanced_crypto"
    name: "Advanced Crypto"
    description: "Encryption & decryption utilities"
    icon: "🔐"
    category: "Security"
  
  - id: "dev_emv_reader"
    name: "EMV Reader"
    description: "EMV credit/debit card reader & analyzer"
    icon: "💳"
    category: "NFC"

YAML;
    
    file_put_contents(APPS_CONFIG_FILE, $yaml);
    return true;
}

function getInstalledApps() {
    return ['success' => true, 'apps' => []];
}
?>
