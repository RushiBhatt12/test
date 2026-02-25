<?php
/**
 * buyer_login.php
 * POST { email, password }
 * Returns { success, buyer:{id,name,email,city,phone}, message? }
 */
ob_start();
ini_set("display_errors", 0);
ini_set("display_startup_errors", 0);
error_reporting(0);
set_time_limit(30);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    ob_end_clean();
    echo json_encode(["success" => true]);
    exit;
}

function respond($d) {
    ob_end_clean();
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Config ─────────────────────────────────────────────────────────
if (!file_exists(__DIR__ . "/config.php"))
    respond(["success" => false, "error" => "config.php not found"]);

try { require_once __DIR__ . "/config.php"; }
catch (Exception $e) { respond(["success" => false, "error" => "Config error"]); }

if (!function_exists("db"))
    respond(["success" => false, "error" => "db() missing from config"]);

if ($_SERVER["REQUEST_METHOD"] !== "POST")
    respond(["success" => false, "error" => "POST required"]);

// ── Parse body ─────────────────────────────────────────────────────
$body     = json_decode(file_get_contents("php://input"), true) ?: [];
$email    = trim($body["email"]    ?? "");
$password = trim($body["password"] ?? "");

if (!$email)    respond(["success" => false, "error" => "Email is required."]);
if (!$password) respond(["success" => false, "error" => "Password is required."]);

if (!filter_var($email, FILTER_VALIDATE_EMAIL))
    respond(["success" => false, "error" => "Please enter a valid email address."]);

// ── Look up buyer ──────────────────────────────────────────────────
try {
    $stmt = db()->prepare("
        SELECT id, name, email, password_hash, city, phone, product_interest
        FROM buyers
        WHERE email = ?
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    respond(["success" => false, "error" => "Database error. Please try again."]);
}

if (!$row)
    respond(["success" => false, "error" => "No buyer account found with this email. Please register first."]);

// ── Verify password ────────────────────────────────────────────────
$hashInDb = $row["password_hash"] ?? "";
$valid    = false;

if (strlen($hashInDb) >= 60 && $hashInDb[0] === '$') {
    $valid = password_verify($password, $hashInDb);
} elseif (strlen($hashInDb) === 32) {
    $valid = (md5($password) === $hashInDb);
    if ($valid) {
        try {
            db()->prepare("UPDATE buyers SET password_hash = ? WHERE id = ?")
               ->execute([password_hash($password, PASSWORD_DEFAULT), $row["id"]]);
        } catch (Exception $e) {}
    }
} else {
    $valid = ($password === $hashInDb);
    if ($valid) {
        try {
            db()->prepare("UPDATE buyers SET password_hash = ? WHERE id = ?")
               ->execute([password_hash($password, PASSWORD_DEFAULT), $row["id"]]);
        } catch (Exception $e) {}
    }
}

if (!$valid)
    respond(["success" => false, "error" => "Incorrect password. Please try again."]);

// ── Update last_login ──────────────────────────────────────────────
try {
    db()->prepare("UPDATE buyers SET last_login = NOW() WHERE id = ?")
       ->execute([$row["id"]]);
} catch (Exception $e) {}

// ── Respond ────────────────────────────────────────────────────────
respond([
    "success" => true,
    "buyer"   => [
        "id"               => (int)$row["id"],
        "name"             => $row["name"]             ?? "",
        "email"            => $row["email"]            ?? "",
        "city"             => $row["city"]             ?? "",
        "phone"            => $row["phone"]            ?? "",
        "product_interest" => $row["product_interest"] ?? "",
    ],
]);