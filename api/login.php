<?php
// ── Must be VERY first line — no whitespace before <?php ───────
ob_start();
ini_set("display_errors", 0);
ini_set("display_startup_errors", 0);
error_reporting(0);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    ob_end_clean();
    echo json_encode(["success" => true]);
    exit;
}

function respond($data) {
    // Clear ANY output PHP may have accidentally printed
    ob_end_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Config ─────────────────────────────────────────────────────
if (!file_exists(__DIR__ . "/config.php")) {
    respond(["success" => false, "error" => "config.php not found"]);
}
try {
    require __DIR__ . "/config.php";
} catch (Throwable $e) {
    respond(["success" => false, "error" => "Config error: " . $e->getMessage()]);
}

// ── Only accept POST ───────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(["success" => false, "error" => "POST required"]);
}

// ── Read + validate JSON body ──────────────────────────────────
$raw  = file_get_contents("php://input");
$body = json_decode($raw, true);

if (!$body || !is_array($body)) {
    respond(["success" => false, "error" => "Invalid JSON body"]);
}

$email    = trim(strtolower($body["email"]    ?? ""));
$password = trim($body["password"] ?? "");

if (!$email)    respond(["success" => false, "error" => "Email is required"]);
if (!$password) respond(["success" => false, "error" => "Password is required"]);
if (!filter_var($email, FILTER_VALIDATE_EMAIL))
    respond(["success" => false, "error" => "Invalid email format"]);

// ── DB lookup ──────────────────────────────────────────────────
try {
    $stmt = db()->prepare("
        SELECT id, name, email, password, category, city, state, website, contact
        FROM sellers
        WHERE LOWER(email) = ?
        LIMIT 1");
    $stmt->execute([$email]);
    $seller = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    respond(["success" => false, "error" => "Database error: " . $e->getMessage()]);
}

if (!$seller) {
    respond(["success" => false, "error" => "No account found with that email"]);
}

// ── Verify password ────────────────────────────────────────────
$valid = false;
if (strlen($seller["password"]) >= 60 && $seller["password"][0] === "$") {
    // Bcrypt hashed
    $valid = password_verify($password, $seller["password"]);
} else {
    // Plain text fallback (legacy — you should migrate these)
    $valid = ($password === $seller["password"]);
}

if (!$valid) {
    respond(["success" => false, "error" => "Incorrect password"]);
}

// ── Load seller_details ────────────────────────────────────────
try {
    $dstmt = db()->prepare("
        SELECT gst_number, business_type, year_established, employees,
               annual_turnover, products_offered, business_desc, address,
               pincode, certifications, delivery_radius,
               facebook_url, instagram_url, whatsapp, working_hours
        FROM seller_details
        WHERE seller_id = ?
        LIMIT 1");
    $dstmt->execute([$seller["id"]]);
    $details = $dstmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $details = [];
}

// ── Build clean response objects ──────────────────────────────
// NEVER include password in response
$sellerOut = [
    "id"       => (int) $seller["id"],
    "name"     => $seller["name"]     ?? "",
    "email"    => $seller["email"]    ?? "",
    "category" => $seller["category"] ?? "",
    "city"     => $seller["city"]     ?? "",
    "state"    => $seller["state"]    ?? "",
    "website"  => $seller["website"]  ?? "",
    "contact"  => $seller["contact"]  ?? "",
];

$detailsOut = [
    "seller_id"        => (int) $seller["id"],
    "gst_number"       => $details["gst_number"]       ?? "",
    "business_type"    => $details["business_type"]    ?? "",
    "year_established" => $details["year_established"] ?? "",
    "employees"        => $details["employees"]        ?? "",
    "annual_turnover"  => $details["annual_turnover"]  ?? "",
    "products_offered" => $details["products_offered"] ?? "",
    "business_desc"    => $details["business_desc"]    ?? "",
    "address"          => $details["address"]          ?? "",
    "pincode"          => $details["pincode"]          ?? "",
    "certifications"   => $details["certifications"]   ?? "",
    "delivery_radius"  => $details["delivery_radius"]  ?? "",
    "facebook_url"     => $details["facebook_url"]     ?? "",
    "instagram_url"    => $details["instagram_url"]    ?? "",
    "whatsapp"         => $details["whatsapp"]         ?? "",
    "working_hours"    => $details["working_hours"]    ?? "",
];

// ── Respond ────────────────────────────────────────────────────
respond([
    "success" => true,
    "seller"  => $sellerOut,
    "details" => $detailsOut,
    "message" => "Login successful",
]);
?>
