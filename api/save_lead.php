<?php
require "config.php";
require "discord.php";

$raw  = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!$data || empty($data["product"]) || empty($data["city"])) {
    sendJSON(["success" => false, "error" => "Product and city required"]);
}

try {
    $check = db()->prepare("SELECT id FROM leads WHERE buyer_id=? AND product=? AND city=?");
    $check->execute([$data["buyer_id"], $data["product"], $data["city"]]);
    $existing = $check->fetch();

    if ($existing) {
        $stmt = db()->prepare("UPDATE leads SET
            quantity=?,unit=?,budget_min=?,budget_max=?,state=?,status='active',created_at=NOW()
            WHERE buyer_id=? AND product=? AND city=?");
        $stmt->execute([
            $data["quantity"]   ?? "",
            $data["unit"]       ?? "",
            $data["budget_min"] ?? 0,
            $data["budget_max"] ?? 0,
            $data["state"]      ?? "",
            $data["buyer_id"], $data["product"], $data["city"]
        ]);
        sendJSON(["success" => true, "lead_id" => $existing["id"], "action" => "updated"]);
    } else {
        $stmt = db()->prepare("INSERT INTO leads
            (buyer_id,buyer_name,buyer_phone,product,city,state,quantity,unit,budget_min,budget_max)
            VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $data["buyer_id"]    ?? 0,
            $data["buyer_name"]  ?? "",
            $data["buyer_phone"] ?? "",
            $data["product"],
            $data["city"],
            $data["state"]       ?? "",
            $data["quantity"]    ?? "",
            $data["unit"]        ?? "",
            $data["budget_min"]  ?? 0,
            $data["budget_max"]  ?? 0
        ]);
        $lid = (int)db()->lastInsertId();

        sendDiscord("📦 New Buyer Lead", 0xF59E0B, [
            ["Buyer",     ($data["buyer_name"] ?? "—") . " · " . ($data["buyer_phone"] ?? "No phone")],
            ["Product",   $data["product"]],
            ["Location",  ($data["city"] ?? "—") . ", " . ($data["state"] ?? "—")],
            ["Quantity",  ($data["quantity"] ?? "—") . " " . ($data["unit"] ?? "")],
            ["Budget",    "₹" . ($data["budget_min"] ?? 0) . " – ₹" . ($data["budget_max"] ?? 0), false],
        ]);

        sendJSON(["success" => true, "lead_id" => $lid, "action" => "created"]);
    }
} catch (Exception $e) {
    sendJSON(["success" => false, "error" => $e->getMessage()]);
}
?>
