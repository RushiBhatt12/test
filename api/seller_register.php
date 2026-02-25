<?php
ob_start();
ini_set("display_errors", 0);
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

require __DIR__ . "/config.php";
require __DIR__ . "/discord.php";

$raw  = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!$data || empty($data["email"]) || empty($data["password"])) {
    sendJSON(["success" => false, "error" => "Email and password are required"]);
}

$hash = password_hash($data["password"], PASSWORD_DEFAULT);

try {
    $stmt = db()->prepare("INSERT INTO sellers
        (name, category, city, state, website, contact, email, password)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $data["name"]     ?? "",
        $data["category"] ?? "",
        $data["city"]     ?? "",
        $data["state"]    ?? "",
        $data["website"]  ?? "",
        $data["contact"]  ?? "",
        $data["email"],
        $hash
    ]);
    $id = (int) db()->lastInsertId();

    // Discord notification
    sendDiscord("🏪 New Seller Registered", 0x0071E3, [
        ["Business", $data["name"]     ?? "—"],
        ["Category", $data["category"] ?? "—"],
        ["Location", ($data["city"] ?? "—") . ", " . ($data["state"] ?? "—")],
        ["Email",    $data["email"]],
        ["Phone",    $data["contact"]  ?? "—"],
        ["Website",  $data["website"]  ?? "None", false],
    ]);

    sendJSON(["success" => true, "seller_id" => $id]);

} catch (Exception $e) {
    sendJSON(["success" => false, "error" => $e->getMessage()]);
}
?>