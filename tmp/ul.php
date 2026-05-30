<?php
// upload.php
// Einfaches, sicheres Upload-Formular: Datei + Firmware-Name (stock/rm/unleashed) + Version-String.
// Nach Upload wird die Datei als data/firmware/<name>/firmware.bin abgelegt und .state.json aktualisiert.

declare(strict_types=1);
session_start();
error_reporting(E_ALL);
ini_set('display_errors','1');

define('DATA_DIR', __DIR__ . '/data');
define('FIRMWARE_DIR', DATA_DIR . '/firmware');
@mkdir(FIRMWARE_DIR, 0755, true);

$ALLOWED = ['stock','rm','unleashed'];
$MAX_BYTES = 200 * 1024 * 1024; // 200 MB limit (anpassen)

// Hilfsfunktionen
function safe_name(string $s): string {
    return preg_replace('/[^a-z0-9_\-\.]/i', '_', $s);
}
function write_state(string $dir, array $data): void {
    @file_put_contents($dir . '/.state.json', json_encode($data, JSON_UNESCAPED_UNICODE));
}
function debug_log(string $dir, string $msg): void {
    @file_put_contents($dir . '/.debug.log', date('c') . ' ' . $msg . PHP_EOL, FILE_APPEND);
}

$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fw = $_POST['firmware'] ?? '';
    $version = trim((string)($_POST['version'] ?? ''));
    if (!in_array($fw, $ALLOWED, true)) $errors[] = 'Ungültige Firmware-Auswahl.';
    if (empty($version)) $errors[] = 'Version erforderlich.';
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) $errors[] = 'Keine Datei hochgeladen oder Upload-Fehler.';
    if (empty($errors)) {
        $file = $_FILES['file'];
        if ($file['size'] <= 0 || $file['size'] > $MAX_BYTES) $errors[] = 'Dateigröße ungültig oder zu groß.';
        // optional: MIME check (nicht vollständig zuverlässig)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        // erlaubte MIME-Typen (erweitern falls nötig)
        $allowedMimes = ['application/octet-stream','application/zip','application/x-zip-compressed','application/x-binary','application/x-elf','application/x-msdos-program'];
        // akzeptiere auch unknown/other to be flexible; nur warnen
        if (!in_array($mime, $allowedMimes, true)) {
            // nur warnen, nicht blockieren
            $warnings[] = "Ungewöhnlicher MIME-Typ: $mime";
        }

        $fwDir = FIRMWARE_DIR . '/' . safe_name($fw);
        @mkdir($fwDir, 0755, true);
        $dest = $fwDir . '/firmware.bin';
        // move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $errors[] = 'Fehler beim Verschieben der Datei.';
        } else {
            chmod($dest, 0644);
            // write state and debug
            $state = [
                'started' => time(),
                'progress' => 100,
                'status' => 'available',
                'version' => $version,
                'uploaded_by' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'size' => filesize($dest)
            ];
            write_state($fwDir, $state);
            debug_log($fwDir, "Uploaded version={$version} size={$state['size']}");
            @unlink($fwDir . '/.error');
            $success = "Firmware für {$fw} hochgeladen (Version: " . htmlspecialchars($version, ENT_QUOTES) . ").";
        }
    }
}
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Flipper Manager — Upload</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;background:#f4f6f8;color:#222;padding:18px}
.container{max-width:700px;margin:0 auto}
.card{background:#fff;padding:14px;border-radius:8px;border:1px solid #e6e9ee}
label{display:block;margin-top:8px;font-weight:700}
input[type="text"],select{width:100%;padding:8px;margin-top:6px;border:1px solid #ddd;border-radius:6px}
input[type="file"]{margin-top:8px}
button{margin-top:12px;padding:10px 14px;border-radius:6px;border:0;background:#0b74de;color:#fff;cursor:pointer}
.notice{color:#666;margin-top:8px}
.error{color:#b71c1c}
.success{color:#1b5e20}
</style>
</head>
<body>
<div class="container">
  <div class="card">
    <h2>Firmware Upload</h2>
    <?php if (!empty($errors)): ?>
      <div class="error"><?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?></div>
    <?php elseif ($success): ?>
      <div class="success"><?php echo htmlspecialchars($success, ENT_QUOTES); ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" novalidate>
      <label for="firmware">Firmware</label>
      <select name="firmware" id="firmware" required>
        <option value="">-- wählen --</option>
        <?php foreach ($ALLOWED as $a): ?>
          <option value="<?php echo htmlspecialchars($a, ENT_QUOTES); ?>"><?php echo htmlspecialchars(strtoupper($a), ENT_QUOTES); ?></option>
        <?php endforeach; ?>
      </select>

      <label for="version">Version (z. B. 1.4.3)</label>
      <input type="text" name="version" id="version" required>

      <label for="file">Datei (firmware.bin oder Archiv)</label>
      <input type="file" name="file" id="file" accept="*/*" required>

      <div class="notice">Maximale Dateigröße: <?php echo ($MAX_BYTES / 1024 / 1024); ?> MB. Die Datei wird als <code>data/firmware/&lt;name&gt;/firmware.bin</code> gespeichert.</div>

      <button type="submit">Hochladen</button>
    </form>
  </div>
</div>
</body>
</html>
