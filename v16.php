<?php
/**
 * index.php - Flipper Zero Manager v11 (clean, auto, external repos aware)
 *
 * Features:
 * - No user download buttons: automatic background start on page load.
 * - Merges external app sources (configurable).
 * - Robust firmware download detection:
 *   * checks release assets, assets_url, parses release HTML for .bin links,
 *     falls back to zipball/tarball and extracts .bin if present.
 * - Single-worker guarantee per firmware via flock lock (.lock).
 * - Worker spawn via proc_open/exec/popen; if spawning disabled, runs synchronously.
 * - Writes .state.json, .debug.log and .error for each firmware folder.
 * - Optional GitHub token via environment variable GITHUB_TOKEN to avoid rate limits.
 *
 * Deployment notes:
 * - Ensure webserver user can write to data/ and subfolders.
 * - Ensure PHP-CLI is available (recommended) or synchronous fallback will be used.
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

define('DATA_DIR', __DIR__ . '/data');
define('FIRMWARE_DIR', DATA_DIR . '/firmware');
define('APPS_CONFIG_FILE', DATA_DIR . '/apps.yaml');

// External app sources (YAML or JSON). Add or remove entries as needed.
$REMOTE_APPS_SOURCES = [
    'rogue_master' => 'https://raw.githubusercontent.com/RogueMaster/FlipperZero/master/apps/apps.yaml',
    // 'custom_repo' => 'https://raw.githubusercontent.com/your/repo/branch/apps.yaml',
];

// Firmware release endpoints
$FIRMWARE_SOURCES = [
    'stock'     => 'https://api.github.com/repos/flipperdevices/flipperzero-firmware/releases/latest',
    'rm'        => 'https://api.github.com/repos/RogueMaster/flipperzero-firmware-wPlugins/releases/latest',
    'unleashed' => 'https://api.github.com/repos/UnleashedFirmware/FlipperZero/releases/latest'
];

// Ensure directories exist
foreach ([DATA_DIR, FIRMWARE_DIR, DATA_DIR . '/apps'] as $d) {
    if (!is_dir($d)) @mkdir($d, 0755, true);
}

/* ---------------------------
   CLI Worker Entrypoints
   --------------------------- */
/* Worker: download single file with progress updates
   Usage: php index.php worker <downloadUrl> <tmpFile> <stateFile> <finalFile>
*/
if (php_sapi_name() === 'cli' && isset($argv[1]) && $argv[1] === 'worker') {
    if ($argc < 6) exit(1);
    $downloadUrl = $argv[2];
    $tmpFile = $argv[3];
    $stateFile = $argv[4];
    $finalFile = $argv[5];
    run_worker($downloadUrl, $tmpFile, $stateFile, $finalFile, dirname($stateFile));
    exit(0);
}

/* Worker for archive fallback
   Usage: php index.php worker-archive <archiveUrl> <tmpFile> <stateFile> <finalFile>
*/
if (php_sapi_name() === 'cli' && isset($argv[1]) && $argv[1] === 'worker-archive') {
    if ($argc < 6) exit(1);
    $archiveUrl = $argv[2];
    $tmpFile = $argv[3];
    $stateFile = $argv[4];
    $finalFile = $argv[5];
    run_worker($archiveUrl, $tmpFile, $stateFile, $tmpFile . '.downloaded', dirname($stateFile));
    // extraction logic
    $fwDir = dirname($stateFile);
    $tmpDir = $fwDir . '/extract_' . uniqid();
    @mkdir($tmpDir, 0755, true);
    $extractedBin = null;
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmpFile) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (stripos($name, '.bin') !== false) {
                    $zip->extractTo($tmpDir, $name);
                    $extractedBin = $tmpDir . '/' . $name;
                    break;
                }
            }
            $zip->close();
        }
    }
    if (!$extractedBin && class_exists('PharData')) {
        try {
            $phar = new PharData($tmpFile);
            foreach (new RecursiveIteratorIterator($phar) as $file) {
                $fn = (string)$file;
                if (stripos($fn, '.bin') !== false) {
                    $dest = $tmpDir . '/' . basename($fn);
                    copy($fn, $dest);
                    $extractedBin = $dest;
                    break;
                }
            }
        } catch (Exception $e) {
            debug_log($fwDir, "PharData extraction failed: " . $e->getMessage());
        }
    }
    if ($extractedBin && file_exists($extractedBin) && filesize($extractedBin) > 1024) {
        rename($extractedBin, $finalFile);
        $s = @json_decode(@file_get_contents($stateFile), true) ?: [];
        $s['status'] = 'done';
        $s['progress'] = 100;
        $s['downloaded'] = filesize($finalFile);
        $s['updated'] = time();
        @file_put_contents($stateFile, json_encode($s));
        @unlink($tmpFile);
        debug_log($fwDir, "archive worker done, final=" . basename($finalFile));
        exit(0);
    } else {
        $s = @json_decode(@file_get_contents($stateFile), true) ?: [];
        $s['status'] = 'error';
        $s['error'] = 'No .bin found inside archive';
        @file_put_contents($stateFile, json_encode($s));
        @file_put_contents($fwDir . '/.error', $s['error']);
        debug_log($fwDir, "archive worker error: no .bin inside archive");
        exit(1);
    }
}

/* ---------------------------
   Web/API Routes
   --------------------------- */
$action = $_GET['action'] ?? null;
if ($action === 'api') {
    header('Content-Type: application/json; charset=utf-8');
    $endpoint = $_GET['endpoint'] ?? null;
    try {
        switch ($endpoint) {
            case 'apps':
                echo json_encode(getAppsData($REMOTE_APPS_SOURCES), JSON_UNESCAPED_UNICODE);
                break;
            case 'firmware-status':
                echo json_encode(checkFirmwareStatus($FIRMWARE_SOURCES), JSON_UNESCAPED_UNICODE);
                break;
            case 'start-firmware-download':
                $firmware = $_GET['firmware'] ?? null;
                echo json_encode(startFirmwareDownload($firmware, $FIRMWARE_SOURCES), JSON_UNESCAPED_UNICODE);
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

/* ---------------------------
   Frontend (no buttons, auto-start)
   --------------------------- */
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Flipper Zero Manager v11</title>
<style>
body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f4f6f8;color:#222;padding:18px}
.container{max-width:1100px;margin:0 auto}
.header{background:#fff;padding:16px;border-radius:10px;border:1px solid #e6e9ee;margin-bottom:14px}
#firmwareOverlay{position:fixed;inset:0;background:#000;color:#fff;display:none;align-items:center;justify-content:center;z-index:9999;flex-direction:column}
#firmwareOverlay.show{display:flex}
#firmwareOverlay .bar{width:70%;height:18px;background:#222;border-radius:6px;overflow:hidden;margin-top:12px}
#firmwareOverlay .fill{height:100%;background:#0b74de;width:0%}
.status{color:#666;margin-top:10px}
</style>
</head>
<body>
<div class="container">
  <div class="header"><h1>Flipper Zero Manager v11</h1><div style="color:#666">Automatische Firmware‑Nachladung (externen Repos angepasst)</div></div>
  <div class="status" id="status">Initialisiere…</div>
</div>

<div id="firmwareOverlay">
  <div style="text-align:center">
    <div id="fwTitle" style="font-size:1.2rem">Firmware wird vorbereitet…</div>
    <div class="bar"><div class="fill" id="fwFill"></div></div>
    <div id="fwSub" style="color:#bbb;margin-top:8px"></div>
  </div>
</div>

<script>
window.addEventListener('load', () => { autoStart(); });

async function autoStart(){
  document.getElementById('status').textContent = 'Prüfe Firmware-Status…';
  try {
    const res = await fetch('?action=api&endpoint=firmware-status');
    const data = await res.json();
    const firmwares = data.firmwares || {};
    let anyMissing = false;
    for (const fw of ['stock','rm','unleashed']) {
      const s = firmwares[fw] || {};
      if (!s.available && !s.downloading) {
        await fetch('?action=api&endpoint=start-firmware-download&firmware=' + encodeURIComponent(fw));
      }
      if (!s.available || s.downloading) anyMissing = true;
    }
    if (anyMissing) {
      document.getElementById('firmwareOverlay').classList.add('show');
      pollProgress();
    } else {
      document.getElementById('status').textContent = 'Alle Firmwares vorhanden';
    }
  } catch (e) {
    document.getElementById('status').textContent = 'Fehler: ' + e.message;
  }
}

let pollTimer = null;
async function pollProgress(){
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = setInterval(async ()=>{
    try {
      let best = {fw:null,progress:0,downloaded:0,size:null,status:'',message:''};
      for (const fw of ['stock','rm','unleashed']) {
        const r = await fetch('?action=api&endpoint=firmware-progress&firmware=' + encodeURIComponent(fw));
        const d = await r.json();
        if (d.success) {
          if ((d.progress||0) > best.progress) best = {fw:fw,progress:d.progress||0,downloaded:d.downloaded||0,size:d.size||null,status:d.status||'',message:d.message||''};
        }
      }
      if (best.fw) {
        document.getElementById('fwTitle').textContent = 'Herunterladen: ' + best.fw.toUpperCase() + ' — ' + (best.progress||0) + '%';
        document.getElementById('fwFill').style.width = (best.progress||0) + '%';
        document.getElementById('fwSub').textContent = best.size ? (best.downloaded + ' / ' + best.size + ' bytes') : best.message || '';
      }
      const statusRes = await fetch('?action=api&endpoint=firmware-status');
      const statusData = await statusRes.json();
      const firmwares = statusData.firmwares || {};
      let anyMissing = false;
      for (const fw of ['stock','rm','unleashed']) {
        const s = firmwares[fw] || {};
        if (!s.available) anyMissing = true;
      }
      if (!anyMissing) {
        document.getElementById('firmwareOverlay').classList.remove('show');
        clearInterval(pollTimer);
        pollTimer = null;
        document.getElementById('status').textContent = 'Downloads abgeschlossen';
      }
    } catch (e) {
      console.log(e);
    }
  }, 1500);
}
</script>
</body>
</html>
<?php
/* ---------------------------
   Backend helper functions
   --------------------------- */

function debug_log($fwDir, $msg) {
    @file_put_contents($fwDir . '/.debug.log', date('c') . ' ' . $msg . PHP_EOL, FILE_APPEND);
}

function acquire_lock($lockPath, $timeout = 5) {
    $fp = @fopen($lockPath, 'c+');
    if (!$fp) return false;
    $start = time();
    do {
        if (flock($fp, LOCK_EX | LOCK_NB)) {
            ftruncate($fp, 0);
            fwrite($fp, getmypid() . "\n");
            fflush($fp);
            return $fp;
        }
        usleep(200000);
    } while (time() - $start < $timeout);
    fclose($fp);
    return false;
}
function release_lock($fp) {
    if (!$fp) return;
    flock($fp, LOCK_UN);
    fclose($fp);
}
function read_lock_pid($lockPath) {
    if (!file_exists($lockPath)) return null;
    $c = @file_get_contents($lockPath);
    if (!$c) return null;
    $lines = explode("\n", trim($c));
    return $lines[0] ?? null;
}

/* Merge local apps.yaml and remote sources */
function getAppsData($remoteSources = []) {
    if (!file_exists(APPS_CONFIG_FILE)) createDefaultAppsYaml();
    $yaml = parseYaml(@file_get_contents(APPS_CONFIG_FILE));
    $base = $yaml['base_apps'] ?? [];
    $extra = $yaml['extra_apps'] ?? [];

    // Merge remote sources
    foreach ($remoteSources as $name => $url) {
        $content = http_get_raw($url);
        if (!$content) continue;
        $parsed = parseYaml($content);
        if (!empty($parsed['base_apps'])) $base = array_merge($base, $parsed['base_apps']);
        if (!empty($parsed['extra_apps'])) $extra = array_merge($extra, $parsed['extra_apps']);
        $json = @json_decode($content, true);
        if ($json) {
            if (!empty($json['base_apps'])) $base = array_merge($base, $json['base_apps']);
            if (!empty($json['extra_apps'])) $extra = array_merge($extra, $json['extra_apps']);
        }
    }

    // Ensure app files exist (server-side auto-download for app files)
    foreach (array_merge($base, $extra) as $app) {
        if (empty($app['id']) || empty($app['files']) || !is_array($app['files'])) continue;
        foreach ($app['files'] as $file) {
            $appDir = DATA_DIR . '/apps/' . $app['id'];
            if (!is_dir($appDir)) @mkdir($appDir, 0755, true);
            $relPath = $file['path'] ?? basename($file['url']);
            $localPath = $appDir . '/' . $relPath;
            $okFile = $localPath . '.ok';
            $errFile = $localPath . '.error';
            $stateFile = $localPath . '.downloading';
            if (file_exists($localPath) && filesize($localPath) > 0) {
                if (!empty($file['sha256'])) {
                    $sha = @hash_file('sha256', $localPath);
                    if ($sha === $file['sha256']) {
                        @unlink($errFile);
                        @file_put_contents($okFile, json_encode(['verified' => true, 'sha256' => $sha]));
                        continue;
                    } else {
                        @unlink($localPath);
                        @unlink($okFile);
                        @file_put_contents($errFile, "Hash mismatch (found: $sha)");
                    }
                } else {
                    @file_put_contents($okFile, json_encode(['verified' => false]));
                    continue;
                }
            }
            if (file_exists($stateFile)) continue;
            @file_put_contents($stateFile, json_encode(['started' => time(), 'progress' => 0]));
            $res = downloadFileToPath($file['url'], $localPath, $stateFile);
            if ($res['success']) {
                if (!empty($file['sha256'])) {
                    $sha = @hash_file('sha256', $localPath);
                    if ($sha !== $file['sha256']) {
                        file_put_contents($errFile, "Hash mismatch after download (found: $sha)");
                        @unlink($localPath);
                    } else {
                        file_put_contents($localPath . '.ok', json_encode(['verified' => true, 'sha256' => $sha]));
                    }
                } else {
                    file_put_contents($localPath . '.ok', json_encode(['verified' => false]));
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

/* Firmware status */
function checkFirmwareStatus($sources) {
    $firmwares = [];
    foreach (array_keys($sources) as $fw) {
        $fwDir = FIRMWARE_DIR . '/' . $fw;
        $fwFile = $fwDir . '/firmware.bin';
        $stateFile = $fwDir . '/.state.json';
        $downloading = false;
        if (file_exists($stateFile)) {
            $s = @json_decode(@file_get_contents($stateFile), true);
            if (!empty($s['status']) && in_array($s['status'], ['running','queued'])) $downloading = true;
        }
        $firmwares[$fw] = [
            'available' => file_exists($fwFile) && filesize($fwFile) > 1024,
            'downloading' => $downloading,
            'lock_pid' => read_lock_pid($fwDir . '/.lock')
        ];
    }
    return ['success' => true, 'firmwares' => $firmwares];
}

/* Start firmware download with robust URL detection and spawn fallback */
function startFirmwareDownload($firmware, $sources) {
    if (!$firmware || !isset($sources[$firmware])) return ['success'=>false,'error'=>'Invalid firmware'];
    $fwDir = FIRMWARE_DIR . '/' . $firmware;
    if (!is_dir($fwDir)) @mkdir($fwDir, 0755, true);
    $lockPath = $fwDir . '/.lock';
    $stateFile = $fwDir . '/.state.json';
    $tmpFile = $fwDir . '/firmware.bin.tmp';
    $finalFile = $fwDir . '/firmware.bin';
    $errorFile = $fwDir . '/.error';

    if (file_exists($finalFile) && filesize($finalFile) > 1024) {
        return ['success'=>true,'message'=>'Already present'];
    }

    $lockFp = acquire_lock($lockPath, 0);
    if (!$lockFp) {
        $pid = read_lock_pid($lockPath);
        return ['success'=>true,'message'=>'Already downloading','pid'=>$pid];
    }

    $apiUrl = $sources[$firmware];
    $release = http_get_json($apiUrl, $fwDir);
    if (!$release) {
        $msg = 'Failed to fetch release JSON';
        debug_log($fwDir, $msg);
        file_put_contents($errorFile, $msg);
        release_lock($lockFp);
        return ['success'=>false,'error'=>$msg];
    }

    // Robust URL detection (assets, assets_url, html_url parsing, archive fallback)
    $downloadUrl = null;

    // 1) assets
    if (!empty($release['assets']) && is_array($release['assets'])) {
        foreach ($release['assets'] as $asset) {
            if (!empty($asset['browser_download_url']) && stripos($asset['name'], '.bin') !== false) {
                $downloadUrl = $asset['browser_download_url'];
                break;
            }
        }
    }

    // 2) assets_url
    if (!$downloadUrl && !empty($release['assets_url'])) {
        $assets = http_get_json($release['assets_url'], $fwDir);
        if ($assets && is_array($assets)) {
            foreach ($assets as $asset) {
                if (!empty($asset['browser_download_url']) && stripos($asset['name'], '.bin') !== false) {
                    $downloadUrl = $asset['browser_download_url'];
                    break;
                }
            }
        }
    }

    // 3) html_url parsing for .bin links
    if (!$downloadUrl && !empty($release['html_url'])) {
        $html = http_get_raw($release['html_url'], $fwDir);
        if ($html) {
            if (preg_match_all('/href=["\']([^"\']+\.bin(?:\?[^"\']*)?)["\']/i', $html, $m)) {
                foreach ($m[1] as $candidate) {
                    if (strpos($candidate, 'http') !== 0) {
                        $candidate = rtrim('https://github.com', '/') . '/' . ltrim($candidate, '/');
                    }
                    $downloadUrl = $candidate;
                    break;
                }
            }
        }
    }

    // 4) archive fallback
    $archiveFallback = false;
    $archiveUrl = null;
    if (!$downloadUrl) {
        if (!empty($release['zipball_url'])) { $archiveFallback = true; $archiveUrl = $release['zipball_url']; }
        elseif (!empty($release['tarball_url'])) { $archiveFallback = true; $archiveUrl = $release['tarball_url']; }
    }

    if (!$downloadUrl && !$archiveFallback) {
        $msg = 'No binary asset, no assets_url .bin, and no archive fallback; tried assets, assets_url, html_url';
        debug_log($fwDir, $msg);
        file_put_contents($errorFile, $msg);
        release_lock($lockFp);
        return ['success'=>false,'error'=>$msg];
    }

    $initial = ['started'=>time(),'progress'=>0,'downloaded'=>0,'size'=>null,'status'=>'queued','pid'=>null,'error'=>null,'source'=>($downloadUrl?:$archiveUrl)];
    @file_put_contents($stateFile, json_encode($initial));

    $php = PHP_BINARY;
    $self = __FILE__;
    if ($archiveFallback) {
        $workerCmd = escapeshellcmd("$php " . escapeshellarg($self) . " worker-archive " . escapeshellarg($archiveUrl) . " " . escapeshellarg($tmpFile) . " " . escapeshellarg($stateFile) . " " . escapeshellarg($finalFile));
    } else {
        $workerCmd = escapeshellcmd("$php " . escapeshellarg($self) . " worker " . escapeshellarg($downloadUrl) . " " . escapeshellarg($tmpFile) . " " . escapeshellarg($stateFile) . " " . escapeshellarg($finalFile));
    }

    $pid = null;
    $spawned = false;

    if (function_exists('proc_open')) {
        $descriptors = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
        $process = @proc_open($workerCmd, $descriptors, $pipes);
        if (is_resource($process)) {
            $status = proc_get_status($process);
            $pid = $status['pid'] ?? null;
            foreach ($pipes as $p) @fclose($p);
            @proc_close($process);
            $spawned = true;
            debug_log($fwDir, "proc_open spawned pid={$pid}");
        }
    }

    if (!$spawned && function_exists('exec')) {
        @exec($workerCmd . " > /dev/null 2>&1 & echo $!", $out, $ret);
        if (!empty($out) && preg_match('/\d+/', implode("\n",$out), $m)) {
            $pid = $m[0];
            $spawned = true;
            debug_log($fwDir, "exec spawned pid={$pid}");
        }
    }

    if (!$spawned && function_exists('popen')) {
        $p = @popen($workerCmd . " > /dev/null 2>&1 &", 'r');
        if ($p !== false) { @pclose($p); $spawned = true; debug_log($fwDir, "popen used"); }
    }

    if (!$spawned) {
        debug_log($fwDir, "No spawn available, running worker synchronously");
        if ($archiveFallback) {
            run_worker_archive($archiveUrl, $tmpFile, $stateFile, $finalFile, $fwDir);
        } else {
            run_worker($downloadUrl, $tmpFile, $stateFile, $finalFile, $fwDir);
        }
        release_lock($lockFp);
        return ['success'=>true,'message'=>'Worker executed synchronously (spawn disabled)'];
    }

    ftruncate($lockFp, 0);
    fwrite($lockFp, ($pid ?: getmypid()) . "\n");
    fflush($lockFp);
    release_lock($lockFp);

    return ['success'=>true,'message'=>'Worker started','pid'=>$pid];
}

/* Read progress */
function getDownloadProgress($firmware) {
    if (!$firmware) return ['success'=>false,'error'=>'Firmware not specified'];
    $fwDir = FIRMWARE_DIR . '/' . $firmware;
    $stateFile = $fwDir . '/.state.json';
    $errorFile = $fwDir . '/.error';
    $final = $fwDir . '/firmware.bin';
    if (file_exists($errorFile)) {
        $err = file_get_contents($errorFile);
        return ['success'=>false,'error'=>$err,'complete'=>false];
    }
    if (file_exists($final) && filesize($final) > 1024) {
        return ['success'=>true,'progress'=>100,'complete'=>true,'message'=>'Download abgeschlossen'];
    }
    if (file_exists($stateFile)) {
        $s = @json_decode(@file_get_contents($stateFile), true);
        if (!$s) return ['success'=>true,'progress'=>0,'complete'=>false,'message'=>'Initialisierung...'];
        return [
            'success'=>true,
            'progress'=> $s['progress'] ?? 0,
            'downloaded'=> $s['downloaded'] ?? 0,
            'size'=> $s['size'] ?? null,
            'status'=> $s['status'] ?? 'running',
            'pid'=> $s['pid'] ?? null,
            'message'=> $s['error'] ?? ''
        ];
    }
    return ['success'=>true,'progress'=>0,'complete'=>false,'message'=>'Nicht gestartet'];
}

/* HTTP helpers */
function http_get_json($url, $fwDir = null) {
    $token = getenv('GITHUB_TOKEN') ?: null;
    $ch = curl_init($url);
    $headers = ['User-Agent: Flipper-Manager'];
    if ($token) $headers[] = 'Authorization: token ' . $token;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20
    ]);
    $content = curl_exec($ch);
    $err = curl_errno($ch) ? curl_error($ch) : null;
    curl_close($ch);
    if ($err) {
        if ($fwDir) debug_log($fwDir, "http_get_json error: $err");
        return null;
    }
    $json = @json_decode($content, true);
    if ($fwDir) debug_log($fwDir, "http_get_json fetched " . ($json ? 'OK' : 'INVALID JSON'));
    return $json ?: null;
}
function http_get_raw($url, $fwDir = null) {
    $token = getenv('GITHUB_TOKEN') ?: null;
    $ch = curl_init($url);
    $headers = ['User-Agent: Flipper-Manager'];
    if ($token) $headers[] = 'Authorization: token ' . $token;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20
    ]);
    $content = curl_exec($ch);
    $err = curl_errno($ch) ? curl_error($ch) : null;
    curl_close($ch);
    if ($err) {
        if ($fwDir) debug_log($fwDir, "http_get_raw error: $err");
        return null;
    }
    if ($fwDir) debug_log($fwDir, "http_get_raw fetched " . (strlen($content) > 0 ? 'OK' : 'EMPTY'));
    return $content;
}

/* Simple file downloader used for app files (synchronous) */
function downloadFileToPath($url, $localPath, $stateFile = null) {
    $tmp = $localPath . '.tmp';
    if (!is_dir(dirname($tmp))) @mkdir(dirname($tmp), 0755, true);
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
    if (!is_dir(dirname($localPath))) @mkdir(dirname($localPath), 0755, true);
    rename($tmp, $localPath);
    return ['success' => true];
}

/* Worker implementation */
function run_worker($downloadUrl, $tmpFile, $stateFile, $finalFile, $fwDir) {
    if (!is_dir(dirname($tmpFile))) @mkdir(dirname($tmpFile), 0755, true);

    // HEAD probe
    $remoteSize = null;
    $ch = curl_init($downloadUrl);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Flipper-Manager-Worker',
        CURLOPT_TIMEOUT => 20
    ]);
    curl_exec($ch);
    if (!curl_errno($ch)) {
        $remoteSize = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        if ($remoteSize === 0) $remoteSize = null;
    }
    curl_close($ch);

    $state = ['started'=>time(),'progress'=>0,'downloaded'=>0,'size'=>$remoteSize,'status'=>'running','pid'=>getmypid(),'error'=>null];
    @file_put_contents($stateFile, json_encode($state));
    debug_log($fwDir, "worker start url={$downloadUrl} size=" . ($remoteSize?:'unknown'));

    $fp = fopen($tmpFile, 'w');
    if ($fp === false) {
        $state['status'] = 'error';
        $state['error'] = 'Cannot open tmp file for writing';
        @file_put_contents($stateFile, json_encode($state));
        debug_log($fwDir, "Cannot open tmp file for writing");
        return;
    }

    $ch = curl_init($downloadUrl);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Flipper-Manager-Worker',
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => function($resource, $dl_total, $dl_now, $ul_total, $ul_now) use ($stateFile, $fwDir) {
            $s = @json_decode(@file_get_contents($stateFile), true) ?: [];
            $s['downloaded'] = $dl_now;
            $s['size'] = $dl_total > 0 ? $dl_total : ($s['size'] ?? null);
            $s['progress'] = ($dl_total > 0) ? round(($dl_now / $dl_total) * 100) : (isset($s['progress']) ? $s['progress'] : 0);
            $s['updated'] = time();
            @file_put_contents($stateFile, json_encode($s));
            return 0;
        },
        CURLOPT_TIMEOUT => 0
    ]);
    curl_exec($ch);
    $err = curl_errno($ch) ? curl_error($ch) : null;
    curl_close($ch);
    fclose($fp);

    if ($err) {
        $s = @json_decode(@file_get_contents($stateFile), true) ?: [];
        $s['status'] = 'error';
        $s['error'] = $err;
        $s['updated'] = time();
        @file_put_contents($stateFile, json_encode($s));
        @file_put_contents($fwDir . '/.error', $err);
        debug_log($fwDir, "worker error: $err");
        @unlink($tmpFile);
        return;
    }

    if (file_exists($tmpFile) && filesize($tmpFile) > 1024) {
        @rename($tmpFile, $finalFile);
        $s = @json_decode(@file_get_contents($stateFile), true) ?: [];
        $s['status'] = 'done';
        $s['progress'] = 100;
        $s['downloaded'] = filesize($finalFile);
        $s['updated'] = time();
        @file_put_contents($stateFile, json_encode($s));
        debug_log($fwDir, "worker done, final=" . basename($finalFile));
        return;
    } else {
        $s = @json_decode(@file_get_contents($stateFile), true) ?: [];
        $s['status'] = 'error';
        $s['error'] = 'Downloaded file too small or missing';
        $s['updated'] = time();
        @file_put_contents($stateFile, json_encode($s));
        @file_put_contents($fwDir . '/.error', $s['error']);
        debug_log($fwDir, "worker error: downloaded file too small");
        @unlink($tmpFile);
        return;
    }
}

/* run_worker_archive wrapper for synchronous fallback */
function run_worker_archive($archiveUrl, $tmpFile, $stateFile, $finalFile, $fwDir) {
    run_worker($archiveUrl, $tmpFile, $stateFile, $tmpFile . '.downloaded', $fwDir);
    // extraction handled in CLI entrypoint above
}

/* Simple YAML parser for expected structure */
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

/* Default apps.yaml */
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
YAML;
    file_put_contents(APPS_CONFIG_FILE, $yaml);
}

/* End of file */
?>
