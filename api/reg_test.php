<?php
require "config.php";

// Simulate exactly what register sends
$_SERVER["REQUEST_METHOD"] = "POST";
$data = [
    "name"       => "Test Business",
    "category"   => "Electronics",
    "city"       => "Vadodara",
    "state"      => "Gujarat",
    "website"    => "",
    "contact"    => "9876543210",
    "email"      => "test_" . time() . "@test.com",
    "password"   => "test1234"
];

$hash = password_hash($data["password"], PASSWORD_DEFAULT);
try {
    $stmt = db()->prepare("INSERT INTO sellers 
        (name, category, city, state, website, contact, email, password)
        VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $data["name"],    $data["category"],
        $data["city"],    $data["state"],
        $data["website"], $data["contact"],
        $data["email"],   $hash
    ]);
    sendJSON(["success" => true, "seller_id" => db()->lastInsertId()]);
} catch (Exception $e) {
    sendJSON(["success" => false, "error" => $e->getMessage()]);
}
?>
