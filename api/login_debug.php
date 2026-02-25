<?php
/**
 * login_debug.php  — TEMPORARY diagnostic tool
 *
 * Upload to /api/login_debug.php
 * Visit in browser: https://yoursite.com/api/login_debug.php
 *
 * DELETE THIS FILE after fixing the login issue.
 */
header('Content-Type: text/plain; charset=UTF-8');
$out = [];

$out[] = "=== PHP ===";
$out[] = "Version: " . PHP_VERSION;
$out[] = "display_errors: " . ini_get('display_errors');

$out[] = "\n=== config.php ===";
$configPath = __DIR__ . '/config.php';
$out[] = "Path: $configPath";
$out[] = "Exists: " . (file_exists($configPath) ? 'YES' : 'NO — THIS IS THE PROBLEM');

if (file_exists($configPath)) {
    try {
        ob_start();
        require_once $configPath;
        $cfgOutput = ob_get_clean();
        if ($cfgOutput) $out[] = "WARNING: config.php printed output: " . substr($cfgOutput, 0, 200);
        $out[] = "Loaded: OK";
        $out[] = "db() exists: " . (function_exists('db') ? 'YES' : 'NO — THIS IS THE PROBLEM');
    } catch (Throwable $e) {
        ob_end_clean();
        $out[] = "ERROR loading config: " . $e->getMessage();
    }
}

if (function_exists('db')) {
    $out[] = "\n=== Database ===";
    try {
        $pdo = db();
        $out[] = "Connection: OK";

        // Check sellers table
        $cols = $pdo->query("SHOW COLUMNS FROM sellers")->fetchAll(PDO::FETCH_COLUMN);
        $out[] = "sellers columns: " . implode(', ', $cols);

        $pwdCols = array_filter($cols, fn($c) => in_array(strtolower($c), ['password','password_hash','pwd','seller_password']));
        $out[] = "Password column(s) found: " . (implode(', ', $pwdCols) ?: 'NONE — THIS IS THE PROBLEM');

        // Check seller_details table
        try {
            $dc = $pdo->query("SHOW COLUMNS FROM seller_details")->fetchAll(PDO::FETCH_COLUMN);
            $out[] = "\nseller_details columns: " . implode(', ', $dc);
        } catch (Exception $e) {
            $out[] = "\nseller_details: NOT FOUND (will use empty details)";
        }

        // Count sellers
        $count = $pdo->query("SELECT COUNT(*) FROM sellers")->fetchColumn();
        $out[] = "\nTotal sellers in DB: $count";

    } catch (Throwable $e) {
        $out[] = "DB ERROR: " . $e->getMessage();
    }
}

$out[] = "\n=== DONE — delete this file after fixing ===";
echo implode("\n", $out);