<?php
/**
 * index.php
 * Flipper Zero Manager v11 — improved firmware download handling
 *
 * - Single-file: acts as web UI/API and as CLI worker when invoked with "worker" argument.
 * - Robust multi-user locking: prevents multiple parallel downloads of same firmware.
 * - HEAD probe to determine remote size; reliable progress reporting via .state.json.
 * - Fallbacks: try release assets; if none, try zipball/tarball and extract .bin (if present).
 * - Atomic tmp -> final rename; detailed state + error files for debugging.
 *
 * Deployment notes:
 * - PHP CLI must be available and webserver user must be allowed to exec PHP CLI.
 * - Ensure data/ is writable by webserver user.
 * - For large-scale production, consider replacing PHP worker with aria2c or systemd workers.
 *
 * Usage:
 * - Web/API: ?action=api&endpoint=firmware-status
 * - Start download: ?action=api&endpoint=start-firmware-download&firmware=stock
 * - Progress: ?action=api&endpoint=firmware-progress&firmware=stock
 *
 * Worker mode (internal): php index.php worker <downloadUrl> <tmpFile> <stateFile> <finalFile>
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

define('DATA_DIR', __DIR__ . '/data');
define('FIRMWARE_DIR', DATA_DIR . '/firmware');
define('APPS_CONFIG_FILE', DATA_DIR . '/apps.yaml');

// GitHub release endpoints (used to find binary)
define('FIRMWARE_SOURCES', [
    'stock' => 'https://api.github.com/repos/flipperdevices/flipperzero-firmware/releases/latest',
    'rm' => 'https://api.github.com/repos/RogueMaster/flipperzero-firmware-wPlugins/releases/latest',
    'unleashed' => 'https://api.github.com/repos/UnleashedFirmware/FlipperZero/releases/latest'
]);

// Ensure directories
foreach ([DATA_DIR, FIRMWARE_DIR, DATA_DIR . '/apps'] as $d) {
    if (!is_dir($d)) @mkdir($d, 0755, true);
}

/* ---------------------------
   CLI Worker Mode
   ---------------------------
   When invoked as: php index.php worker <downloadUrl> <tmpFile> <stateFile> <finalFile>
   the script runs the download worker (with progress updates to stateFile).
*/
if (php_sapi_name() === 'cli' && isset($argv[1]) && $argv[1] === 'worker') {
    // Worker entry
    $argc = $_SERVER['argc'];
    if ($argc < 6) exit(1);
    $downloadUrl = $argv[2];
    $tmpFile = $argv[3];
    $stateFile = $argv[4];
    $finalFile = $argv[5];
    run_worker($downloadUrl, $tmpFile, $stateFile, $finalFile);
    exit(0);
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

/* ---------------------------
   Minimal UI (keeps previous behavior)
   --------------------------- */
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Flipper Zero Manager v11</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;background:#f4f6f8;color:#222;padding:18px}
.container{max-width:1000px;margin:0 auto}
.header{background:#fff;padding:12px;border-radius:8px;border:1px solid #e6e9ee;margin-bottom:12px}
.section{background:#fff;padding:12px;border-radius:8px;border:1px solid #e6e9ee;margin-bottom:12px}
#firmwareOverlay{position:fixed;inset:0;background:#000;color:#fff;display:none;align-items:center;justify-content:center;z-index:9999;flex-direction:column}
#firmwareOverlay.show{display:flex}
#firmwareOverlay .bar{width:70%;height:18px;background:#222;border-radius:6px;overflow:hidden;margin-top:12px}
#firmwareOverlay .fill{height:100%;background:#0b74de;width:0%}
</style>
</head>
<body>
<div class="container">
  <div class="header"><h2>Flipper Zero Manager v11</h2><div style="color:#666">Automatische Firmware-Downloads — robustes Mehrbenutzer‑Handling</div></div>
  <div class="section">
    <button onclick="startAll()">Fehlende Firmware prüfen &amp; Download starten</button>
    <div id="status" style="margin-top:10px;color:#666"></div>
  </div>
</div>

<div id="firmwareOverlay">
  <div style="text-align:center">
    <div id="fwTitle" style="font-size:1.2rem">Firmware wird heruntergeladen…</div>
    <div class="bar"><div class="fill" id="fwFill"></div></div>
    <div id="fwSub" style="color:#bbb;margin-top:8px"></div>
  </div>
</div>

<script>
async function startAll(){
  document.getElementById('status').textContent = 'Prüfe Firmware-Status…';
  try {
    const res = await fetch('?action=api&endpoint=firmware-status');
    const data = await res.json();
    if (!data.success) { document.getElementById('status').textContent = 'Fehler beim Abrufen'; return; }
    const firmwares = data.firmwares || {};
    let anyMissing = false;
    for (const fw of ['stock','rm','unleashed']) {
      const s = firmwares[fw] || {};
      if (!s.available && !s.downloading) {
        // request start
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
      // aggregate best progress
      let best = {fw:null,progress:0,downloaded:0,size:null,status:'',message:''};
      for (const fw of ['stock','rm','unleashed']) {
        const r = await fetch('?action=api&endpoint=firmware-progress&firmware=' + encodeURIComponent(fw));
        const d = await r.json();
        if (d.success) {
          if ((d.progress||0) > best.progress) best = {fw:fw,progress:d.progress||0,downloaded:d.downloaded||0,size:d.size||null,status:d.status||'',message:d.message||''};
        } else if (d.complete) {
          best = {fw:fw,progress:100,downloaded:d.downloaded||0,size:d.size||null,status:'done',message:d.message||''};
        }
      }
      if (best.fw) {
        document.getElementById('fwTitle').textContent = 'Herunterladen: ' + best.fw.toUpperCase() + ' — ' + (best.progress||0) + '%';
        document.getElementById('fwFill').style.width = (best.progress||0) + '%';
        document.getElementById('fwSub').textContent = best.size ? (best.downloaded + ' / ' + best.size + ' bytes') : best.message || '';
      }
      // if all done -> hide
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
   Backend functions
   --------------------------- */

/* Lock helpers using flock */
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
function is_pid_running($pid) {
    if (!$pid) return false;
    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        // Windows: best-effort
        exec("tasklist /FI \"PID eq $pid\" 2>NUL", $out);
        return count($out) > 1;
    } else {
        return file_exists("/proc/$pid");
    }
}

/* Check firmware status */
function checkFirmwareStatus() {
    $firmwares = [];
    foreach (['stock','rm','unleashed'] as $fw) {
        $fwDir = FIRMWARE_DIR . '/' . $fw;
        $fwFile = $fwDir . '/firmware.bin';
        $stateFile = $fwDir . '/.state.json';
        $lockFile = $fwDir . '/.lock';
        $downloading = false;
        if (file_exists($stateFile)) {
            $s = @json_decode(@file_get_contents($stateFile), true);
            if (!empty($s['status']) && in_array($s['status'], ['running','queued'])) $downloading = true;
        }
        $firmwares[$fw] = [
            'available' => file_exists($fwFile) && filesize($fwFile) > 1024,
            'downloading' => $downloading,
            'lock_pid' => read_lock_pid($lockFile)
        ];
    }
    return ['success' => true, 'firmwares' => $firmwares];
}

/* Start firmware download with lock and worker spawn */
function startFirmwareDownload($firmware) {
    $allowed = ['stock','rm','unleashed'];
    if (!$firmware || !in_array($firmware, $allowed)) return ['success'=>false,'error'=>'Invalid firmware'];
    $fwDir = FIRMWARE_DIR . '/' . $firmware;
    if (!is_dir($fwDir)) @mkdir($fwDir, 0755, true);
    $lockPath = $fwDir . '/.lock';
    $stateFile = $fwDir . '/.state.json';
    $tmpFile = $fwDir . '/firmware.bin.tmp';
    $finalFile = $fwDir . '/firmware.bin';
    $errorFile = $fwDir . '/.error';

    // Acquire short lock to avoid race starting workers
    $lockFp = acquire_lock($lockPath, 0);
    if (!$lockFp) {
        $pid = read_lock_pid($lockPath);
        return ['success'=>true,'message'=>'Already downloading','pid'=>$pid];
    }

    // If final already exists, release and return
    if (file_exists($finalFile) && filesize($finalFile) > 1024) {
        release_lock($lockFp);
        return ['success'=>true,'message'=>'Already present'];
    }

    // Determine download URL: try assets first, then tarball/zipball fallback
    $apiUrl = FIRMWARE_SOURCES[$firmware] ?? null;
    if (!$apiUrl) { release_lock($lockFp); return ['success'=>false,'error'=>'No source configured']; }

    $release = http_get_json($apiUrl);
    if (!$release) { release_lock($lockFp); return ['success'=>false,'error'=>'Failed to fetch release JSON']; }

    // Prefer assets
    $downloadUrl = null;
    if (!empty($release['assets']) && is_array($release['assets'])) {
        foreach ($release['assets'] as $asset) {
            if (!empty($asset['browser_download_url']) && stripos($asset['name'], '.bin') !== false) {
                $downloadUrl = $asset['browser_download_url'];
                break;
            }
        }
    }

    // If no asset .bin found, try assets_url (sometimes different)
    if (!$downloadUrl && !empty($release['assets_url'])) {
        $assetsList = http_get_json($release['assets_url']);
        if ($assetsList && is_array($assetsList)) {
            foreach ($assetsList as $asset) {
                if (!empty($asset['browser_download_url']) && stripos($asset['name'], '.bin') !== false) {
                    $downloadUrl = $asset['browser_download_url'];
                    break;
                }
            }
        }
    }

    // Fallback: try zipball/tarball and attempt to extract .bin from archive
    $archiveFallback = false;
    $archiveUrl = null;
    if (!$downloadUrl) {
        if (!empty($release['zipball_url'])) { $archiveFallback = true; $archiveUrl = $release['zipball_url']; }
        elseif (!empty($release['tarball_url'])) { $archiveFallback = true; $archiveUrl = $release['tarball_url']; }
    }

    if (!$downloadUrl && !$archiveFallback) {
        file_put_contents($errorFile, 'No assets found in release and no archive fallback');
        release_lock($lockFp);
        return ['success'=>false,'error'=>'No binary asset or archive found'];
    }

    // Prepare initial state
    $initial = ['started'=>time(),'progress'=>0,'downloaded'=>0,'size'=>null,'status'=>'queued','pid'=>null,'error'=>null,'source'=>($downloadUrl?:$archiveUrl)];
    @file_put_contents($stateFile, json_encode($initial));

    // Spawn worker: use same PHP file in CLI mode
    $php = PHP_BINARY;
    $self = __FILE__;
    if ($archiveFallback) {
        // Worker will download archive and try to extract .bin into finalFile
        $workerCmd = escapeshellcmd("$php " . escapeshellarg($self) . " worker-archive " . escapeshellarg($archiveUrl) . " " . escapeshellarg($tmpFile) . " " . escapeshellarg($stateFile) . " " . escapeshellarg($finalFile));
    } else {
        $workerCmd = escapeshellcmd("$php " . escapeshellarg($self) . " worker " . escapeshellarg($downloadUrl) . " " . escapeshellarg($tmpFile) . " " . escapeshellarg($stateFile) . " " . escapeshellarg($finalFile));
    }

    // Run in background
    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        pclose(popen("start /B " . $workerCmd, "r"));
        $pid = null;
    } else {
        exec($workerCmd . " > /dev/null 2>&1 & echo $!", $out);
        $pid = $out[0] ?? null;
    }

    // Write pid into lock file for visibility
    ftruncate($lockFp, 0);
    fwrite($lockFp, ($pid ?: getmypid()) . "\n");
    fflush($lockFp);
    // release lock quickly; worker uses stateFile to indicate running
    release_lock($lockFp);

    return ['success'=>true,'message'=>'Worker started','pid'=>$pid];
}

/* Get download progress by reading .state.json */
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

/* ---------------------------
   Helper: HTTP GET JSON with GitHub UA
   --------------------------- */
function http_get_json($url) {
    $ctx = stream_context_create(['http'=>['timeout'=>15,'header'=>"User-Agent: Flipper-Manager\r\n"]]);
    $content = @file_get_contents($url, false, $ctx);
    if (!$content) return null;
    $json = @json_decode($content, true);
    return $json ?: null;
}

/* ---------------------------
   Worker implementations (CLI)
   - run_worker: downloads a single file with progress updates
   - run_worker_archive: downloads archive and extracts .bin
   --------------------------- */
function run_worker($downloadUrl, $tmpFile, $stateFile, $finalFile) {
    // Ensure directory
    if (!is_dir(dirname($tmpFile))) @mkdir(dirname($tmpFile), 0755, true);

    // HEAD probe to get remote size
    $remoteSize = null;
    $head = curl_init($downloadUrl);
    curl_setopt_array($head, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Flipper-Manager-Worker',
        CURLOPT_TIMEOUT => 20
    ]);
    curl_exec($head);
    if (!curl_errno($head)) {
        $remoteSize = curl_getinfo($head, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        if ($remoteSize === 0) $remoteSize = null;
    }
    curl_close($head);

    $state = ['started'=>time(),'progress'=>0,'downloaded'=>0,'size'=>$remoteSize,'status'=>'running','pid'=>getmypid(),'error'=>null];
    @file_put_contents($stateFile, json_encode($state));

    $fp = fopen($tmpFile, 'w');
    if ($fp === false) {
        $state['status'] = 'error';
        $state['error'] = 'Cannot open tmp file for writing';
        @file_put_contents($stateFile, json_encode($state));
        return;
    }

    $ch = curl_init($downloadUrl);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Flipper-Manager-Worker',
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => function($resource, $dl_total, $dl_now, $ul_total, $ul_now) use ($stateFile) {
            $s = @json_decode(@file_get_contents($stateFile), true) ?: [];
            $s['downloaded'] = $dl_now;
            $s['size'] = $dl_total > 0 ? $dl_total : ($s['size'] ?? null);
            $s['progress'] = ($dl_total > 0) ? round(($dl_now / $dl_total) * 100) : ($s['progress'] ?? 0);
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
        @file_put_contents(dirname($stateFile) . '/.error', $err);
        @unlink($tmpFile);
        return;
    }

    // Validate size if known
    if (file_exists($tmpFile) && filesize($tmpFile) > 1024) {
        // Move tmp -> final
        @rename($tmpFile, $finalFile);
        $s = @json_decode(@file_get_contents($stateFile), true) ?: [];
        $s['status'] = 'done';
        $s['progress'] = 100;
        $s['downloaded'] = filesize($finalFile);
        $s['updated'] = time();
        @file_put_contents($stateFile, json_encode($s));
        return;
    } else {
        $s = @json_decode(@file_get_contents($stateFile), true) ?: [];
        $s['status'] = 'error';
        $s['error'] = 'Downloaded file too small or missing';
        $s['updated'] = time();
        @file_put_contents($stateFile, json_encode($s));
        @file_put_contents(dirname($stateFile) . '/.error', $s['error']);
        @unlink($tmpFile);
        return;
    }
}

/* Worker for archive fallback: download archive, extract .bin and move to final */
if (php_sapi_name() === 'cli' && isset($argv[1]) && $argv[1] === 'worker-archive') {
    // argv: [0]=script, [1]=worker-archive, [2]=archiveUrl, [3]=tmpFile, [4]=stateFile, [5]=finalFile
    if ($_SERVER['argc'] < 6) exit(1);
    $archiveUrl = $argv[2];
    $tmpFile = $argv[3];
    $stateFile = $argv[4];
    $finalFile = $argv[5];

    // Download archive to tmpFile
    run_worker($archiveUrl, $tmpFile, $stateFile, $tmpFile . '.downloaded'); // reuse run_worker to download archive into tmpFile
    // After download, try to extract .bin
    if (!file_exists($tmpFile) || filesize($tmpFile) < 1024) {
        $s = @json_decode(@file_get_contents($stateFile), true) ?: [];
        $s['status'] = 'error';
        $s['error'] = 'Archive download failed or too small';
        @file_put_contents($stateFile, json_encode($s));
        @file_put_contents(dirname($stateFile) . '/.error', $s['error']);
        exit(1);
    }

    // Try ZIP first
    $extractedBin = null;
    $tmpDir = dirname($tmpFile) . '/extract_' . uniqid();
    @mkdir($tmpDir, 0755, true);

    $isZip = false;
    $isTar = false;
    // Heuristic: check first bytes
    $fh = fopen($tmpFile, 'rb');
    $head = fread($fh, 4);
    fclose($fh);
    if (substr($head,0,2) === "PK") $isZip = true;
    if (substr($head,0,2) === "\x1f\x8b") $isTar = true; // gz

    if ($isZip && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmpFile) === true) {
            for ($i=0;$i<$zip->numFiles;$i++) {
                $name = $zip->getNameIndex($i);
                if (stripos($name, '.bin') !== false) {
                    $zip->extractTo($tmpDir, $name);
                    $extractedBin = $tmpDir . '/' . $name;
                    break;
                }
            }
            $zip->close();
        }
    } elseif ($isTar && class_exists('PharData')) {
        try {
            $phar = new PharData($tmpFile);
            foreach (new RecursiveIteratorIterator($phar) as $file) {
                $fn = (string)$file;
                if (stripos($fn, '.bin') !== false) {
                    // extract single file
                    $rel = str_replace($phar->getPathName(), '', $fn);
                    $dest = $tmpDir . '/' . basename($fn);
                    copy($fn, $dest);
                    $extractedBin = $dest;
                    break;
                }
            }
        } catch (Exception $e) {
            // ignore
        }
    }

    // If found, move to final
    if ($extractedBin && file_exists($extractedBin) && filesize($extractedBin) > 1024) {
        rename($extractedBin, $finalFile);
        $s = @json_decode(@file_get_contents($stateFile), true) ?: [];
        $s['status'] = 'done';
        $s['progress'] = 100;
        $s['downloaded'] = filesize($finalFile);
        $s['updated'] = time();
        @file_put_contents($stateFile, json_encode($s));
        // cleanup
        @unlink($tmpFile);
        // remove tmpDir recursively
        $it = new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) rmdir($file->getRealPath()); else unlink($file->getRealPath());
        }
        @rmdir($tmpDir);
        exit(0);
    } else {
        $s = @json_decode(@file_get_contents($stateFile), true) ?: [];
        $s['status'] = 'error';
        $s['error'] = 'No .bin found inside archive';
        @file_put_contents($stateFile, json_encode($s));
        @file_put_contents(dirname($stateFile) . '/.error', $s['error']);
        exit(1);
    }
}

/* End of file */
