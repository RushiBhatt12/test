<?php
ob_start();
ini_set("display_errors", 0);
error_reporting(0);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    ob_end_clean(); echo json_encode(["success" => true]); exit;
}

function respond($d) { ob_end_clean(); echo json_encode($d); exit; }

if (!file_exists(__DIR__ . "/config.php"))
    respond(["success" => false, "error" => "config.php missing"]);
try { require __DIR__ . "/config.php"; }
catch (Throwable $e) { respond(["success" => false, "error" => $e->getMessage()]); }

// Accept seller_id from GET param (?seller_id=5) or POST JSON body
$sid = 0;
if (!empty($_GET["seller_id"])) {
    $sid = (int)$_GET["seller_id"];
} else {
    $raw  = file_get_contents("php://input");
    $data = json_decode($raw, true);
    $sid  = (int)($data["seller_id"] ?? 0);
}

if (!$sid) respond(["success" => false, "error" => "seller_id required"]);

try {
    $stmt = $pdo = db()->prepare("
        SELECT
            s.id,
            s.name,
            s.category,
            s.city,
            s.state,
            s.website,
            s.contact,
            s.email,
            COALESCE(d.gst_number,        '') AS gst_number,
            COALESCE(d.business_type,     '') AS business_type,
            COALESCE(d.year_established,  '') AS year_established,
            COALESCE(d.employees,         '') AS employees,
            COALESCE(d.annual_turnover,   '') AS annual_turnover,
            COALESCE(d.products_offered,  '') AS products_offered,
            COALESCE(d.business_desc,     '') AS business_desc,
            COALESCE(d.address,           '') AS address,
            COALESCE(d.pincode,           '') AS pincode,
            COALESCE(d.certifications,    '') AS certifications,
            COALESCE(d.facebook_url,      '') AS facebook_url,
            COALESCE(d.instagram_url,     '') AS instagram_url,
            COALESCE(d.whatsapp,          '') AS whatsapp,
            COALESCE(d.working_hours,     '') AS working_hours,
            COALESCE(d.delivery_radius,   '') AS delivery_radius
        FROM sellers s
        LEFT JOIN seller_details d ON d.seller_id = s.id
        WHERE s.id = ?
    ");
    $stmt->execute([$sid]);
    $row = $stmt->fetch();

    if (!$row) respond(["success" => false, "error" => "Seller #$sid not found"]);

    respond([
        "success" => true,
        "seller"  => [
            "id"       => (int)$row["id"],
            "name"     => $row["name"],
            "category" => $row["category"],
            "city"     => $row["city"],
            "state"    => $row["state"],
            "website"  => $row["website"],
            "contact"  => $row["contact"],
            "email"    => $row["email"],
        ],
        "details" => [
            "seller_id"        => (int)$row["id"],
            "gst_number"       => $row["gst_number"],
            "business_type"    => $row["business_type"],
            "year_established" => $row["year_established"],
            "employees"        => $row["employees"],
            "annual_turnover"  => $row["annual_turnover"],
            "products_offered" => $row["products_offered"],
            "business_desc"    => $row["business_desc"],
            "address"          => $row["address"],
            "pincode"          => $row["pincode"],
            "certifications"   => $row["certifications"],
            "facebook_url"     => $row["facebook_url"],
            "instagram_url"    => $row["instagram_url"],
            "whatsapp"         => $row["whatsapp"],
            "working_hours"    => $row["working_hours"],
            "delivery_radius"  => $row["delivery_radius"],
        ]
    ]);

} catch (Throwable $e) {
    respond(["success" => false, "error" => "DB error: " . $e->getMessage()]);
}
?>