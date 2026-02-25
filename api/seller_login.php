<?php
/**
 * seller_login.php  — fixed version
 *
 * Common causes of "Server error" fixed here:
 *  1. PHP notices/warnings leaking before JSON  → ob_start at very top + ob_end_clean before every echo
 *  2. Wrong password column name (password vs password_hash)  → tries both
 *  3. Missing seller_details table  → graceful fallback
 *  4. Any uncaught exception  → caught and returned as JSON
 */
ob_start();                          // Capture EVERYTHING from line 1
ini_set('display_errors',  '0');
ini_set('display_startup_errors', '0');
error_reporting(0);
set_time_limit(30);

// Headers must come before any output
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    echo json_encode(['success' => true]);
    exit;
}

// Central respond — always clears buffer first
function respond(array $d): void {
    ob_end_clean();
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Wrap everything in a try so even fatal-ish errors return JSON
try {

// ── Config ─────────────────────────────────────────────────────────
if (!file_exists(__DIR__ . '/config.php'))
    respond(['success' => false, 'error' => 'config.php not found in ' . __DIR__]);

require_once __DIR__ . '/config.php';

if (!function_exists('db'))
    respond(['success' => false, 'error' => 'db() function missing — check config.php']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST')
    respond(['success' => false, 'error' => 'POST required']);

// ── Parse input ────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$body = json_decode($raw ?: '{}', true) ?: [];

$email    = trim($body['email']    ?? '');
$password = trim($body['password'] ?? '');

if (!$email)    respond(['success' => false, 'error' => 'Email is required.']);
if (!$password) respond(['success' => false, 'error' => 'Password is required.']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL))
    respond(['success' => false, 'error' => 'Please enter a valid email address.']);

// ── Discover password column name ──────────────────────────────────
// Different setups use 'password', 'password_hash', or 'pwd'
$pdo    = db();
$pwdCol = 'password'; // default — most seller_register.php files use this

try {
    $cols = $pdo->query("SHOW COLUMNS FROM sellers")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('password_hash', $cols))       $pwdCol = 'password_hash';
    elseif (in_array('password', $cols))        $pwdCol = 'password';
    elseif (in_array('pwd', $cols))             $pwdCol = 'pwd';
    elseif (in_array('seller_password', $cols)) $pwdCol = 'seller_password';
} catch (Exception $e) {
    // If SHOW COLUMNS fails, keep the default
}

// ── Fetch seller ───────────────────────────────────────────────────
$sql  = "SELECT id, name, email, city, state, category, website, contact,
                `$pwdCol` AS password_hash
         FROM sellers
         WHERE email = ?
         LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute([$email]);
$row  = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row)
    respond(['success' => false, 'error' => 'No account found with this email. Please register first.']);

// ── Verify password ────────────────────────────────────────────────
$stored = (string)($row['password_hash'] ?? '');
$valid  = false;

if ($stored === '') {
    respond(['success' => false, 'error' => 'Account has no password set. Please contact support.']);
}

// 1. Modern bcrypt / argon2
if (!$valid && strlen($stored) >= 60 && $stored[0] === '$') {
    $valid = password_verify($password, $stored);
}

// 2. MD5 legacy
if (!$valid && strlen($stored) === 32 && ctype_xdigit($stored)) {
    $valid = hash_equals(md5($password), $stored);
    if ($valid) {
        // Silently upgrade to bcrypt
        try {
            $pdo->prepare("UPDATE sellers SET `$pwdCol` = ? WHERE id = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
        } catch (Exception $e) {}
    }
}

// 3. SHA1 legacy (some older setups)
if (!$valid && strlen($stored) === 40 && ctype_xdigit($stored)) {
    $valid = hash_equals(sha1($password), $stored);
    if ($valid) {
        try {
            $pdo->prepare("UPDATE sellers SET `$pwdCol` = ? WHERE id = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
        } catch (Exception $e) {}
    }
}

// 4. Plain-text fallback (dev / test only)
if (!$valid) {
    $valid = hash_equals($stored, $password);
    if ($valid) {
        try {
            $pdo->prepare("UPDATE sellers SET `$pwdCol` = ? WHERE id = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
        } catch (Exception $e) {}
    }
}

if (!$valid)
    respond(['success' => false, 'error' => 'Incorrect password. Please try again.']);

// ── Load seller_details ─────────────────────────────────────────────
$details = [];
try {
    $ds = $pdo->prepare("
        SELECT business_type, business_desc, products_offered,
               gst_number, employees, annual_turnover,
               address, pincode, certifications, delivery_radius
        FROM seller_details
        WHERE seller_id = ?
        LIMIT 1
    ");
    $ds->execute([$row['id']]);
    $details = $ds->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $details = []; // table may not exist yet
}

// ── Update last_login (ignore if column missing) ────────────────────
try {
    $pdo->prepare("UPDATE sellers SET last_login = NOW() WHERE id = ?")
        ->execute([$row['id']]);
} catch (Exception $e) {}

// ── Respond ────────────────────────────────────────────────────────
respond([
    'success' => true,
    'seller'  => [
        'id'        => (int)$row['id'],
        'seller_id' => (int)$row['id'],
        'name'      => $row['name']     ?? '',
        'email'     => $row['email']    ?? '',
        'city'      => $row['city']     ?? '',
        'state'     => $row['state']    ?? '',
        'category'  => $row['category'] ?? '',
        'website'   => $row['website']  ?? '',
        'contact'   => $row['contact']  ?? '',
    ],
    'details' => $details,
]);

} catch (Throwable $e) {
    // Catch absolutely everything — return JSON not a PHP error page
    respond([
        'success' => false,
        'error'   => 'Server error: ' . $e->getMessage(),
        // Remove the line below in production:
        '_debug'  => $e->getFile() . ':' . $e->getLine(),
    ]);
}