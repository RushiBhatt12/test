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

require __DIR__ . "/config.php";

// ── Params ─────────────────────────────────────────────────────
$query    = trim($_GET["q"]        ?? "");
$city     = trim($_GET["city"]     ?? "");
$category = trim($_GET["category"] ?? "");
$limit    = min((int)($_GET["limit"] ?? 20), 50);
$offset   = max((int)($_GET["offset"] ?? 0), 0);

// ── Build WHERE ────────────────────────────────────────────────
$where  = ["1=1"];
$params = [];

if ($query) {
    $where[]  = "(s.name LIKE ? OR d.products_offered LIKE ? OR s.category LIKE ?)";
    $like     = "%$query%";
    $params   = array_merge($params, [$like, $like, $like]);
}
if ($city) {
    $where[]  = "s.city LIKE ?";
    $params[] = "%$city%";
}
if ($category) {
    $where[]  = "s.category = ?";
    $params[] = $category;
}

$whereSQL = implode(" AND ", $where);

try {
    // ── Main query — featured first, then by name ──────────────
    $sql = "
        SELECT
            s.id,
            s.name,
            s.category,
            s.city,
            s.state,
            s.website,
            s.contact,
            s.email,
            s.is_featured,
            s.is_verified,
            s.featured_order,
            COALESCE(d.products_offered, '') AS products_offered,
            COALESCE(d.business_desc,    '') AS business_desc,
            COALESCE(d.whatsapp,         '') AS whatsapp,
            COALESCE(d.working_hours,    '') AS working_hours,
            COALESCE(d.delivery_radius,  '') AS delivery_radius,
            COALESCE(d.business_type,    '') AS business_type,
            COALESCE(d.annual_turnover,  '') AS annual_turnover
        FROM sellers s
        LEFT JOIN seller_details d ON d.seller_id = s.id
        WHERE $whereSQL
        ORDER BY
            s.is_featured DESC,
            s.featured_order ASC,
            s.name ASC
        LIMIT ? OFFSET ?
    ";

    $params[] = $limit;
    $params[] = $offset;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $sellers = $stmt->fetchAll();

    // ── Count total for pagination ─────────────────────────────
    $countParams = array_slice($params, 0, -2); // remove limit+offset
    $countStmt = db()->prepare("SELECT COUNT(*) AS total FROM sellers s LEFT JOIN seller_details d ON d.seller_id = s.id WHERE $whereSQL");
    $countStmt->execute($countParams);
    $total = (int)$countStmt->fetch()["total"];

    // ── Cast types ────────────────────────────────────────────
    foreach ($sellers as &$s) {
        $s["id"]             = (int)$s["id"];
        $s["is_featured"]    = (bool)$s["is_featured"];
        $s["is_verified"]    = (bool)$s["is_verified"];
        $s["featured_order"] = (int)$s["featured_order"];
    }

    respond([
        "success" => true,
        "total"   => $total,
        "count"   => count($sellers),
        "sellers" => $sellers,
    ]);

} catch (Throwable $e) {
    respond(["success" => false, "error" => $e->getMessage()]);
}
?>