<?php
require "config.php";
require "discord.php";

$raw  = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!$data || empty($data["email"]) || empty($data["password"])) {
    sendJSON(["success" => false, "error" => "Email and password are required"]);
}

$hash = password_hash($data["password"], PASSWORD_DEFAULT);
try {
    $stmt = db()->prepare("INSERT INTO buyers
        (name, requirement, city, state, budget_min, budget_max, contact, email, password)
        VALUES (?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $data["name"]        ?? "",
        $data["requirement"] ?? "",
        $data["city"]        ?? "",
        $data["state"]       ?? "",
        $data["budget_min"]  ?? 0,
        $data["budget_max"]  ?? 0,
        $data["contact"]     ?? "",
        $data["email"],
        $hash
    ]);
    $id = (int)db()->lastInsertId();

    sendDiscord("🛒 New Buyer Registered", 0x34C759, [
        ["Name",        $data["name"]     ?? "—"],
        ["Looking For", $data["requirement"] ?? "—"],
        ["Location",    ($data["city"] ?? "—") . ", " . ($data["state"] ?? "—")],
        ["Budget",      "₹" . ($data["budget_min"] ?? 0) . " – ₹" . ($data["budget_max"] ?? 0)],
        ["Phone",       $data["contact"]  ?? "—"],
        ["Email",       $data["email"],  false],
    ]);

    sendJSON(["success" => true, "buyer_id" => $id]);
} catch (Exception $e) {
    sendJSON(["success" => false, "error" => $e->getMessage()]);
}
?>
