<?php
// index.php
// Zeigt Status der Firmware-Ordner an. Keine automatischen Downloads.
// Legt Overlay an, wenn mindestens eine Firmware fehlt.

declare(strict_types=1);
session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');

define('DATA_DIR', __DIR__ . '/data');
define('FIRMWARE_DIR', DATA_DIR . '/firmware');
@mkdir(FIRMWARE_DIR, 0755, true);

// Liste der erwarteten Firmware-Namen (anpassen falls andere Namen gewünscht)
$EXPECTED = ['stock', 'rm', 'unleashed'];

/**
 * Liefert Status für alle erwarteten Firmwares.
 * status: available|missing|error
 * details: optional message
 */
function get_all_firmware_status(array $expected): array {
    $out = [];
    foreach ($expected as $fw) {
        $dir = FIRMWARE_DIR . '/' . $fw;
        $bin = $dir . '/firmware.bin';
        $err = $dir . '/.error';
        $state = $dir . '/.state.json';
        if (file_exists($err)) {
            $out[$fw] = ['status' => 'error', 'details' => trim(@file_get_contents($err))];
            continue;
        }
        if (file_exists($bin) && filesize($bin) > 1024) {
            $out[$fw] = ['status' => 'available', 'details' => 'OK'];
            continue;
        }
        if (file_exists($state)) {
            $s = @json_decode(@file_get_contents($state), true);
            if (is_array($s) && !empty($s['status'])) {
                $out[$fw] = ['status' => $s['status'], 'details' => $s['message'] ?? ''];
                continue;
            }
        }
        $out[$fw] = ['status' => 'missing', 'details' => 'Nicht vorhanden'];
    }
    return $out;
}

$statuses = get_all_firmware_status($EXPECTED);

// Wenn API-Request (z.B. JS Polling)
if (isset($_GET['api']) && $_GET['api'] === 'status') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'firmwares' => $statuses], JSON_UNESCAPED_UNICODE);
    exit;
}
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Flipper Manager — Status</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;background:#f4f6f8;color:#222;padding:18px}
.container{max-width:980px;margin:0 auto}
.header{background:#fff;padding:14px;border-radius:8px;border:1px solid #e6e9ee;margin-bottom:12px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
.card{background:#fff;padding:12px;border-radius:8px;border:1px solid #e6e9ee}
.badge{display:inline-block;padding:6px 10px;border-radius:6px;font-weight:700}
.badge.ok{background:#e8f5e9;color:#1b5e20}
.badge.missing{background:#fff3f3;color:#b71c1c;border:1px solid #f5c6c6}
.badge.error{background:#fff7e6;color:#a65b00;border:1px solid #f0c36d}
#overlay{position:fixed;inset:0;background:#000;color:#fff;display:none;align-items:center;justify-content:center;z-index:9999;flex-direction:column}
#overlay.show{display:flex}
.progress{width:70%;height:14px;background:#222;border-radius:8px;overflow:hidden;margin-top:12px}
.fill{height:100%;background:#0b74de;width:0%}
.small{color:#bbb;margin-top:8px}
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h2>Flipper Manager — Firmware Status</h2>
    <div style="color:#666">Die Seite prüft, ob Firmware-Dateien vorhanden sind. Firmware wird manuell via upload.php hochgeladen.</div>
  </div>

  <div class="grid" id="fwGrid">
    <?php foreach ($statuses as $name => $s): ?>
      <div class="card" id="card_<?php echo htmlspecialchars($name, ENT_QUOTES); ?>">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <div style="font-weight:800"><?php echo htmlspecialchars(strtoupper($name), ENT_QUOTES); ?></div>
          <?php if ($s['status'] === 'available'): ?>
            <div class="badge ok">Vorhanden</div>
          <?php elseif ($s['status'] === 'missing'): ?>
            <div class="badge missing">Fehlt</div>
          <?php else: ?>
            <div class="badge error">Fehler</div>
          <?php endif; ?>
        </div>
        <div style="margin-top:8px;color:#666"><?php echo htmlspecialchars($s['details'] ?? '', ENT_QUOTES); ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <div style="margin-top:14px;color:#666;font-size:0.9em">
    Firmware-Upload erfolgt über <strong>upload.php</strong>. Du lädst die Datei hoch und gibst die Version/Quelle an.
  </div>
</div>

<div id="overlay">
  <div style="text-align:center">
    <div id="ovTitle" style="font-size:1.2rem">Firmware fehlt — bitte hochladen</div>
    <div class="progress"><div class="fill" id="ovFill"></div></div>
    <div class="small" id="ovSub">Warte auf manuelles Upload</div>
  </div>
</div>

<script>
async function refresh() {
  try {
    const r = await fetch('?api=status');
    const j = await r.json();
    if (!j.success) return;
    const firmwares = j.firmwares || {};
    let anyMissing = false;
    let anyDownloading = false;
    for (const [name, s] of Object.entries(firmwares)) {
      const card = document.getElementById('card_' + name);
      if (!card) continue;
      // update badge and details
      card.querySelector('div[style]').nextElementSibling; // noop to keep structure
      const badge = card.querySelector('.badge');
      const details = card.querySelector('div[style] + div') || card.querySelector('div:nth-child(2)');
      if (s.status === 'available') {
        if (badge) badge.className = 'badge ok';
        if (details) details.textContent = s.details || 'OK';
      } else if (s.status === 'missing') {
        if (badge) badge.className = 'badge missing';
        if (details) details.textContent = s.details || 'Nicht vorhanden';
        anyMissing = true;
      } else {
        if (badge) badge.className = 'badge error';
        if (details) details.textContent = s.details || 'Fehler';
      }
      if (s.status === 'running' || s.status === 'queued') anyDownloading = true;
    }
    const overlay = document.getElementById('overlay');
    if (anyMissing || anyDownloading) {
      overlay.classList.add('show');
      // simple indeterminate animation
      document.getElementById('ovFill').style.width = anyDownloading ? '40%' : '0%';
      document.getElementById('ovSub').textContent = anyDownloading ? 'Download läuft' : 'Warte auf Upload';
    } else {
      overlay.classList.remove('show');
    }
  } catch (e) {
    console.log(e);
  }
}
refresh();
setInterval(refresh, 3000);
</script>
</body>
</html>
