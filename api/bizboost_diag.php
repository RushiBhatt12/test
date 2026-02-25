<?php
/**
 * BizBoost Diagnostic — bizboost_diag.php
 *
 * USAGE: Upload this to the same folder as seller_research.php
 * then open: https://yoursite.com/api/bizboost_diag.php
 *
 * DELETE THIS FILE after debugging — it exposes config info.
 */
header("Content-Type: text/html; charset=UTF-8");
?><!DOCTYPE html>
<html>
<head>
<title>BizBoost Diagnostic</title>
<style>
body{font-family:monospace;background:#0a0a0b;color:#f5f5f7;padding:30px;max-width:900px}
h1{color:#2997ff;margin-bottom:8px}
h2{color:#bf5af2;margin:24px 0 8px;font-size:14px;letter-spacing:0.1em;text-transform:uppercase}
.ok{color:#30d158}.fail{color:#ff453a}.warn{color:#ff9f0a}
pre{background:#1a1a1c;border:1px solid rgba(255,255,255,0.1);padding:14px;border-radius:8px;overflow-x:auto;font-size:13px;white-space:pre-wrap}
.row{display:flex;gap:16px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.05);font-size:13px}
.label{color:rgba(245,245,247,0.5);min-width:200px}
</style>
</head>
<body>
<h1>BizBoost Diagnostic</h1>
<p style="color:rgba(245,245,247,0.5);font-size:13px">Delete this file after debugging!</p>

<h2>PHP Environment</h2>
<div class="row"><span class="label">PHP Version</span><span class="<?php echo version_compare(PHP_VERSION,'7.4','>=')?"ok":"fail" ?>"><?php echo PHP_VERSION ?></span></div>
<div class="row"><span class="label">error_reporting</span><span><?php echo error_reporting() ?></span></div>
<div class="row"><span class="label">display_errors</span><span><?php echo ini_get('display_errors') ?: 'off' ?></span></div>
<div class="row"><span class="label">memory_limit</span><span><?php echo ini_get('memory_limit') ?></span></div>
<div class="row"><span class="label">max_execution_time</span><span><?php echo ini_get('max_execution_time') ?>s</span></div>
<div class="row"><span class="label">error_log path</span><span><?php echo ini_get('error_log') ?: '(not set — check php.ini)' ?></span></div>
<div class="row"><span class="label">__DIR__</span><span><?php echo __DIR__ ?></span></div>

<h2>Required Extensions</h2>
<?php
$exts = ['curl','json','pdo','pdo_mysql','mbstring'];
foreach($exts as $e){
    $ok = extension_loaded($e);
    echo "<div class='row'><span class='label'>$e</span><span class='".($ok?'ok':'fail')."'>".($ok?'✓ loaded':'✗ MISSING')."</span></div>";
}
?>

<h2>curl Version</h2>
<?php if(function_exists('curl_version')): $cv=curl_version(); ?>
<div class="row"><span class="label">curl version</span><span class="ok"><?php echo $cv['version'] ?></span></div>
<div class="row"><span class="label">SSL version</span><span class="ok"><?php echo $cv['ssl_version'] ?></span></div>
<?php else: ?>
<div class="row"><span class="fail">curl not available</span></div>
<?php endif; ?>

<h2>File Checks</h2>
<?php
$files = ['config.php', 'seller_research.php'];
foreach($files as $f){
    $exists = file_exists(__DIR__."/$f");
    $readable = $exists && is_readable(__DIR__."/$f");
    echo "<div class='row'><span class='label'>$f</span><span class='".($readable?'ok':'fail')."'>".
        ($readable?"✓ exists & readable":($exists?"exists but NOT readable":"✗ NOT FOUND in ".__DIR__))."</span></div>";
}
?>

<h2>config.php Checks</h2>
<?php
if(file_exists(__DIR__."/config.php")){
    try {
        require_once __DIR__."/config.php";
        echo "<div class='row'><span class='label'>require config.php</span><span class='ok'>✓ loaded without error</span></div>";
        $keys = ['GROQ_KEY','SERPER_KEY','DB_HOST','DB_NAME','DB_USER'];
        foreach($keys as $k){
            $val = defined($k) ? constant($k) : null;
            $set = !empty($val);
            $masked = $set ? substr($val,0,6)."..." : "(not set)";
            echo "<div class='row'><span class='label'>$k</span><span class='".($set?"ok":"fail")."'>".htmlspecialchars($masked)."</span></div>";
        }
        // Test db()
        if(function_exists('db')){
            try {
                $pdo = db();
                $pdo->query("SELECT 1");
                echo "<div class='row'><span class='label'>Database connection</span><span class='ok'>✓ connected</span></div>";
                // Check tables
                foreach(['sellers','seller_details','seo_reports'] as $tbl){
                    try {
                        $pdo->query("SELECT 1 FROM $tbl LIMIT 1");
                        echo "<div class='row'><span class='label'>Table: $tbl</span><span class='ok'>✓ exists</span></div>";
                    } catch(Exception $e){
                        echo "<div class='row'><span class='label'>Table: $tbl</span><span class='fail'>✗ ".$e->getMessage()."</span></div>";
                    }
                }
            } catch(Exception $e){
                echo "<div class='row'><span class='label'>Database connection</span><span class='fail'>✗ ".$e->getMessage()."</span></div>";
            }
        } else {
            echo "<div class='row'><span class='label'>db() function</span><span class='fail'>✗ not defined in config.php</span></div>";
        }
    } catch(Throwable $e){
        echo "<div class='row'><span class='label'>config.php load</span><span class='fail'>✗ Error: ".htmlspecialchars($e->getMessage())."</span></div>";
    }
} else {
    echo "<div class='row'><span class='fail'>config.php not found in ".__DIR__."</span></div>";
}
?>

<h2>Groq API Test</h2>
<?php
if(defined('GROQ_KEY') && GROQ_KEY){
    $ch = curl_init("https://api.groq.com/openai/v1/chat/completions");
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_TIMEOUT=>15,
        CURLOPT_SSL_VERIFYPEER=>false,
        CURLOPT_HTTPHEADER=>["Authorization: Bearer ".GROQ_KEY,"Content-Type: application/json"],
        CURLOPT_POSTFIELDS=>json_encode(["model"=>"llama-3.1-8b-instant","max_tokens"=>10,"messages"=>[["role"=>"user","content"=>"Say OK"]]])
    ]);
    $r = curl_exec($ch); $http = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    $d = json_decode($r,true);
    if($http===200 && isset($d['choices'][0])){
        echo "<div class='row'><span class='label'>Groq API call</span><span class='ok'>✓ HTTP 200 — model responded</span></div>";
    } elseif($http===429){
        echo "<div class='row'><span class='label'>Groq API call</span><span class='warn'>⚠ Rate limited (429) — try again in 30s</span></div>";
    } elseif($http===401){
        echo "<div class='row'><span class='label'>Groq API call</span><span class='fail'>✗ Invalid API key (401)</span></div>";
    } elseif($err){
        echo "<div class='row'><span class='label'>Groq API call</span><span class='fail'>✗ curl error: ".htmlspecialchars($err)."</span></div>";
    } else {
        echo "<div class='row'><span class='label'>Groq API call</span><span class='fail'>✗ HTTP $http: ".htmlspecialchars(substr($r,0,100))."</span></div>";
    }
} else {
    echo "<div class='row'><span class='label'>Groq API call</span><span class='warn'>⚠ Skipped — GROQ_KEY not set</span></div>";
}
?>

<h2>Serper API Test</h2>
<?php
if(defined('SERPER_KEY') && SERPER_KEY){
    $ch = curl_init("https://google.serper.dev/search");
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_TIMEOUT=>10,
        CURLOPT_SSL_VERIFYPEER=>false,
        CURLOPT_HTTPHEADER=>["X-API-KEY: ".SERPER_KEY,"Content-Type: application/json"],
        CURLOPT_POSTFIELDS=>json_encode(["q"=>"test","gl"=>"in","num"=>1])
    ]);
    $r = curl_exec($ch); $http = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    $d = json_decode($r,true);
    if($http===200 && isset($d['organic'])){
        echo "<div class='row'><span class='label'>Serper API call</span><span class='ok'>✓ HTTP 200</span></div>";
    } elseif($http===401 || $http===403){
        echo "<div class='row'><span class='label'>Serper API call</span><span class='fail'>✗ Auth failed ($http) — check SERPER_KEY</span></div>";
    } else {
        echo "<div class='row'><span class='label'>Serper API call</span><span class='fail'>✗ HTTP $http — ".htmlspecialchars(substr($r,0,80))."</span></div>";
    }
} else {
    echo "<div class='row'><span class='label'>Serper API call</span><span class='warn'>⚠ Skipped — SERPER_KEY not set</span></div>";
}
?>

<h2>PHP Error Log (last 30 lines)</h2>
<?php
$logFile = ini_get('error_log');
if($logFile && file_exists($logFile)){
    $lines = file($logFile);
    $last = array_slice($lines, -30);
    echo "<pre>".htmlspecialchars(implode("",$last))."</pre>";
} else {
    echo "<pre class='warn'>Error log not found at: ".htmlspecialchars($logFile?:"(path not set in php.ini)")."\n\nTry checking:\n  /var/log/apache2/error.log\n  /var/log/nginx/error.log\n  /var/log/php_errors.log</pre>";
}
?>

<p style="margin-top:30px;font-size:12px;color:rgba(245,245,247,0.3)">DELETE THIS FILE after debugging.</p>
</body>
</html>