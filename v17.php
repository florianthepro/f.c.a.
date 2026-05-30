<?php
// index.php - Flipper Manager v11 (GitHub-release aware, single-worker, auto-start)
session_start();
error_reporting(E_ALL);
ini_set('display_errors',1);
set_time_limit(0);

define('DATA_DIR', __DIR__.'/data');
define('FIRMWARE_DIR', DATA_DIR.'/firmware');
foreach([DATA_DIR,FIRMWARE_DIR] as $d) if(!is_dir($d)) @mkdir($d,0755,true);

// Configure sources and optional manual overrides
$FIRMWARE_SOURCES = [
  'stock'=>'https://api.github.com/repos/flipperdevices/flipperzero-firmware/releases/latest',
  'rm'=>'https://api.github.com/repos/RogueMaster/flipperzero-firmware-wPlugins/releases/latest',
  'unleashed'=>'https://api.github.com/repos/UnleashedFirmware/FlipperZero/releases/latest'
];
$FIRMWARE_MANUAL_URLS = [
  // 'stock'=>'https://example.com/firmware_stock.bin',
];

// helpers
function debug_log($fwDir,$msg){ @file_put_contents($fwDir.'/.debug.log',date('c').' '.$msg.PHP_EOL,FILE_APPEND); }
function acquire_lock($p,$t=5){ $fp=@fopen($p,'c+'); if(!$fp) return false; $s=time(); do{ if(flock($fp,LOCK_EX|LOCK_NB)){ ftruncate($fp,0); fwrite($fp,getmypid()."\n"); fflush($fp); return $fp; } usleep(200000);}while(time()-$s<$t); fclose($fp); return false;}
function release_lock($fp){ if(!$fp) return; flock($fp,LOCK_UN); fclose($fp); }
function http_get_json($url,$fwDir=null){ $token=getenv('GITHUB_TOKEN'); $ch=curl_init($url); $hdr=['User-Agent:Flipper-Manager']; if($token) $hdr[]='Authorization: token '.$token; curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_FOLLOWLOCATION=>1,CURLOPT_HTTPHEADER=>$hdr,CURLOPT_TIMEOUT=>20]); $c=curl_exec($ch); $err=curl_errno($ch)?curl_error($ch):null; curl_close($ch); if($err){ if($fwDir) debug_log($fwDir,"http_get_json error: $err"); return null;} return @json_decode($c,true); }
function http_get_raw($url,$fwDir=null){ $token=getenv('GITHUB_TOKEN'); $ch=curl_init($url); $hdr=['User-Agent:Flipper-Manager']; if($token) $hdr[]='Authorization: token '.$token; curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_FOLLOWLOCATION=>1,CURLOPT_HTTPHEADER=>$hdr,CURLOPT_TIMEOUT=>20]); $c=curl_exec($ch); $err=curl_errno($ch)?curl_error($ch):null; curl_close($ch); if($err){ if($fwDir) debug_log($fwDir,"http_get_raw error: $err"); return null;} return $c; }

// find candidate: assets, assets_url, html page links, archive fallback
function find_firmware_download_url($release,$fwDir,$manual=null){
  if($manual) { debug_log($fwDir,"manual override used"); return ['url'=>$manual]; }
  $checked=[];
  $exts=['.bin','.img','.dfu','.hex','.zip','.tgz','.tar.gz'];
  if(!empty($release['assets']) && is_array($release['assets'])){
    foreach($release['assets'] as $a){ $name=strtolower($a['name']??''); $url=$a['browser_download_url']??''; $checked[]="asset:$name"; foreach($exts as $e) if(strpos($name,$e)!==false || strpos($name,'firmware')!==false) return ['url'=>$url,'checked'=>$checked]; }
  }
  if(!empty($release['assets_url'])){ $assets=http_get_json($release['assets_url'],$fwDir); if($assets) foreach($assets as $a){ $name=strtolower($a['name']??''); $checked[]="assets_url:$name"; $url=$a['browser_download_url']??''; foreach($exts as $e) if(strpos($name,$e)!==false || strpos($name,'firmware')!==false) return ['url'=>$url,'checked'=>$checked]; } }
  if(!empty($release['html_url'])){ $html=http_get_raw($release['html_url'],$fwDir); if($html){ if(preg_match_all('/href=["\']([^"\']+\.(?:bin|img|dfu|zip|hex|tgz|tar\.gz)(?:\?[^"\']*)?)["\']/i',$html,$m)){ foreach($m[1] as $c){ $checked[]="html_link:$c"; if(strpos($c,'http')!==0) $c='https://github.com/'.ltrim($c,'/'); return ['url'=>$c,'checked'=>$checked]; } } else $checked[]='no_links_in_html'; } else $checked[]='html_fetch_failed'; }
  if(!empty($release['zipball_url'])) return ['archive'=>$release['zipball_url'],'checked'=>$checked];
  if(!empty($release['tarball_url'])) return ['archive'=>$release['tarball_url'],'checked'=>$checked];
  return ['checked'=>$checked];
}

// start download (single-worker guarantee)
function startFirmwareDownload($firmware,$sources,$manuals){
  $fwDir=FIRMWARE_DIR.'/'.$firmware; if(!is_dir($fwDir)) @mkdir($fwDir,0755,true);
  $lock=$fwDir.'/.lock'; $state=$fwDir.'/.state.json'; $tmp=$fwDir.'/firmware.bin.tmp'; $final=$fwDir.'/firmware.bin'; $errf=$fwDir.'/.error';
  if(file_exists($final) && filesize($final)>1024) return ['success'=>true,'message'=>'present'];
  $lp=acquire_lock($lock,0); if(!$lp){ $pid=@file_get_contents($lock); return ['success'=>true,'message'=>'already','pid'=>trim($pid)]; }
  $release=http_get_json($sources[$firmware],$fwDir); if(!$release){ $m='release fetch failed'; debug_log($fwDir,$m); file_put_contents($errf,$m); release_lock($lp); return ['success'=>false,'error'=>$m]; }
  $found=find_firmware_download_url($release,$fwDir,$manuals[$firmware]??null);
  if(!empty($found['url'])) $downloadUrl=$found['url']; elseif(!empty($found['archive'])) { $archive=true; $archiveUrl=$found['archive']; } else { $msg="no candidate; checked: ".implode(', ',$found['checked']??[]); debug_log($fwDir,$msg); file_put_contents($errf,$msg); release_lock($lp); return ['success'=>false,'error'=>$msg,'checked'=>$found['checked']??[]]; }
  file_put_contents($state,json_encode(['started'=>time(),'progress'=>0,'status'=>'queued','source'=>$downloadUrl??$archiveUrl]));
  // spawn worker robustly, else sync
  $php=PHP_BINARY; $self=__FILE__;
  if(!empty($archive)){ $cmd=escapeshellcmd("$php ".escapeshellarg($self)." worker-archive ".escapeshellarg($archiveUrl)." ".escapeshellarg($tmp)." ".escapeshellarg($state)." ".escapeshellarg($final)); }
  else { $cmd=escapeshellcmd("$php ".escapeshellarg($self)." worker ".escapeshellarg($downloadUrl)." ".escapeshellarg($tmp)." ".escapeshellarg($state)." ".escapeshellarg($final)); }
  $spawned=false; $pid=null;
  if(function_exists('proc_open')){ $p=proc_open($cmd,[['pipe','r'],['pipe','w'],['pipe','w']],$pipes); if(is_resource($p)){ $s=proc_get_status($p); $pid=$s['pid']??null; foreach($pipes as $pp) @fclose($pp); @proc_close($p); $spawned=true; debug_log($fwDir,"proc_open pid=$pid"); } }
  if(!$spawned && function_exists('exec')){ @exec($cmd." > /dev/null 2>&1 & echo $!",$out); if(!empty($out)){$pid=$out[0]; $spawned=true; debug_log($fwDir,"exec pid=$pid"); } }
  if(!$spawned){ debug_log($fwDir,"no spawn, running sync"); if(!empty($archive)) run_worker($archiveUrl,$tmp,$state,$final,$fwDir); else run_worker($downloadUrl,$tmp,$state,$final,$fwDir); release_lock($lp); return ['success'=>true,'message'=>'ran sync']; }
  ftruncate($lp,0); fwrite($lp,($pid?:getmypid())."\n"); fflush($lp); release_lock($lp);
  return ['success'=>true,'message'=>'spawned','pid'=>$pid];
}

// get progress
function getDownloadProgress($firmware){ $fwDir=FIRMWARE_DIR.'/'.$firmware; $state=$fwDir.'/.state.json'; $errf=$fwDir.'/.error'; $final=$fwDir.'/firmware.bin'; if(file_exists($errf)) return ['success'=>false,'error'=>file_get_contents($errf)]; if(file_exists($final) && filesize($final)>1024) return ['success'=>true,'progress'=>100,'complete'=>true]; if(file_exists($state)) return json_decode(file_get_contents($state),true); return ['success'=>true,'progress'=>0,'complete'=>false]; }

// worker implementations (download + progress) - omitted here for brevity (use previous worker code from earlier message)
// For full worker code, include run_worker and archive extraction functions (same as prior message).

// Minimal API endpoints for auto-start on page load
if(isset($_GET['auto']) && $_GET['auto']=='1'){
  // auto-start all missing
  foreach(array_keys($FIRMWARE_SOURCES) as $fw) startFirmwareDownload($fw,$FIRMWARE_SOURCES,$FIRMWARE_MANUAL_URLS);
  echo json_encode(['started'=>true]); exit;
}
?>
