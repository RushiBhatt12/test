<?php
ob_start();
ini_set("display_errors", 0);
error_reporting(0);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    ob_end_clean(); echo json_encode(["success" => true]); exit;
}

function respond($d) { ob_end_clean(); echo json_encode($d); exit; }

if (!file_exists(__DIR__ . "/config.php"))
    respond(["success" => false, "error" => "config.php missing"]);
try { require __DIR__ . "/config.php"; }
catch (Throwable $e) { respond(["success" => false, "error" => $e->getMessage()]); }

$raw  = file_get_contents("php://input");
$data = json_decode($raw, true);
if (!$data) respond(["success" => false, "error" => "Invalid JSON"]);

$sid = (int)($data["seller_id"] ?? 0);
if (!$sid) respond(["success" => false, "error" => "seller_id required"]);

// Verify seller exists
try {
    $chk = db()->prepare("SELECT id FROM sellers WHERE id = ?");
    $chk->execute([$sid]);
    if (!$chk->fetch()) respond(["success" => false, "error" => "Seller #$sid not found"]);
} catch (Throwable $e) {
    respond(["success" => false, "error" => "DB: " . $e->getMessage()]);
}

// ── 1. Update sellers table ────────────────────────────────────
try {
    $stmt = db()->prepare("UPDATE sellers SET
        name     = ?,
        category = ?,
        city     = ?,
        state    = ?,
        website  = ?,
        contact  = ?
        WHERE id = ?");
    $stmt->execute([
        trim($data["name"]     ?? ""),
        trim($data["category"] ?? ""),
        trim($data["city"]     ?? ""),
        trim($data["state"]    ?? ""),
        trim($data["website"]  ?? ""),
        trim($data["contact"]  ?? ""),   // ✅ Fixed: was $data["phone"]
        $sid
    ]);
} catch (Throwable $e) {
    respond(["success" => false, "error" => "sellers update failed: " . $e->getMessage()]);
}

// ── 2. Upsert seller_details ───────────────────────────────────
$detailFields = [
    trim($data["gst_number"]       ?? ""),
    trim($data["business_type"]    ?? ""),
    trim($data["year_established"] ?? ""),
    trim($data["employees"]        ?? ""),
    trim($data["annual_turnover"]  ?? ""),
    trim($data["products_offered"] ?? ""),
    trim($data["business_desc"]    ?? ""),
    trim($data["address"]          ?? ""),
    trim($data["pincode"]          ?? ""),
    trim($data["certifications"]   ?? ""),
    trim($data["facebook_url"]     ?? ""),
    trim($data["instagram_url"]    ?? ""),
    trim($data["whatsapp"]         ?? ""),
    trim($data["working_hours"]    ?? ""),
    trim($data["delivery_radius"]  ?? ""),
];

try {
    $exists = db()->prepare("SELECT id FROM seller_details WHERE seller_id = ?");
    $exists->execute([$sid]);
    $hasRow = $exists->fetch();

    if ($hasRow) {
        $stmt = db()->prepare("UPDATE seller_details SET
            gst_number       = ?,
            business_type    = ?,
            year_established = ?,
            employees        = ?,
            annual_turnover  = ?,
            products_offered = ?,
            business_desc    = ?,
            address          = ?,
            pincode          = ?,
            certifications   = ?,
            facebook_url     = ?,
            instagram_url    = ?,
            whatsapp         = ?,
            working_hours    = ?,
            delivery_radius  = ?,
            updated_at       = NOW()
            WHERE seller_id  = ?");
        $stmt->execute(array_merge($detailFields, [$sid]));
    } else {
        $stmt = db()->prepare("INSERT INTO seller_details
            (seller_id, gst_number, business_type, year_established,
             employees, annual_turnover, products_offered, business_desc,
             address, pincode, certifications, facebook_url, instagram_url,
             whatsapp, working_hours, delivery_radius)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute(array_merge([$sid], $detailFields));
    }
} catch (Throwable $e) {
    respond(["success" => false, "error" => "details save failed: " . $e->getMessage()]);
}

// ── 3. Fetch updated data from DB to return ────────────────────
try {
    $fetch = db()->prepare("
        SELECT
            s.id, s.name, s.category, s.city, s.state,
            s.website, s.contact, s.email,
            COALESCE(d.gst_number,       '') AS gst_number,
            COALESCE(d.business_type,    '') AS business_type,
            COALESCE(d.year_established, '') AS year_established,
            COALESCE(d.employees,        '') AS employees,
            COALESCE(d.annual_turnover,  '') AS annual_turnover,
            COALESCE(d.products_offered, '') AS products_offered,
            COALESCE(d.business_desc,    '') AS business_desc,
            COALESCE(d.address,          '') AS address,
            COALESCE(d.pincode,          '') AS pincode,
            COALESCE(d.certifications,   '') AS certifications,
            COALESCE(d.facebook_url,     '') AS facebook_url,
            COALESCE(d.instagram_url,    '') AS instagram_url,
            COALESCE(d.whatsapp,         '') AS whatsapp,
            COALESCE(d.working_hours,    '') AS working_hours,
            COALESCE(d.delivery_radius,  '') AS delivery_radius
        FROM sellers s
        LEFT JOIN seller_details d ON d.seller_id = s.id
        WHERE s.id = ?");
    $fetch->execute([$sid]);
    $row = $fetch->fetch();

    $freshSeller = [
        "id"       => $row["id"],
        "name"     => $row["name"],
        "category" => $row["category"],
        "city"     => $row["city"],
        "state"    => $row["state"],
        "website"  => $row["website"],
        "contact"  => $row["contact"],
        "email"    => $row["email"],
    ];

    $freshDetails = [
        "seller_id"        => $row["id"],
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
    ];
} catch (Throwable $e) {
    // Save was OK, just return what was sent
    $freshSeller  = $data;
    $freshDetails = $data;
}

// ── 4. Discord notification ────────────────────────────────────
try {
    if (defined("DISCORD_WEBHOOK") && DISCORD_WEBHOOK &&
        strpos(DISCORD_WEBHOOK, "YOUR_WEBHOOK") === false) {
        $embed = [
            "title"  => "📋 Seller Profile Updated",
            "color"  => 0x9B59B6,
            "fields" => [
                ["name" => "Business",  "value" => $freshSeller["name"],                    "inline" => true],
                ["name" => "Category",  "value" => $freshSeller["category"],                "inline" => true],
                ["name" => "Location",  "value" => $freshSeller["city"] . ", " . $freshSeller["state"], "inline" => true],
                ["name" => "Products",  "value" => $freshDetails["products_offered"] ?: "—","inline" => false],
                ["name" => "GST",       "value" => $freshDetails["gst_number"]       ?: "—","inline" => true],
                ["name" => "Turnover",  "value" => $freshDetails["annual_turnover"]  ?: "—","inline" => true],
            ],
            "footer" => ["text" => "BizBoost · " . date("d M Y, h:i A")]
        ];
        $ch = curl_init(DISCORD_WEBHOOK);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
            CURLOPT_POSTFIELDS     => json_encode(["embeds" => [$embed]])
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
} catch (Throwable $e) {}

respond([
    "success" => true,
    "seller"  => $freshSeller,
    "details" => $freshDetails,
    "message" => "Profile saved successfully"
]);
?>