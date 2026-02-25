<?php
ob_start();
ini_set("display_errors", 0);
error_reporting(0);
set_time_limit(120);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    ob_end_clean(); echo json_encode(["success"=>true]); exit;
}

function respond($data) {
    ob_end_clean();
    echo json_encode($data);
    exit;
}

if (!file_exists(__DIR__."/config.php")) respond(["success"=>false,"error"=>"config.php missing"]);
try { require __DIR__."/config.php"; } catch(Throwable $e) { respond(["success"=>false,"error"=>$e->getMessage()]); }

$raw  = file_get_contents("php://input");
$body = json_decode($raw, true);

$product  = trim($body["product"]  ?? "");
$city     = trim($body["city"]     ?? "");
$state    = trim($body["state"]    ?? "");
$quantity = trim($body["quantity"] ?? "");

if (!$product) respond(["success"=>false,"error"=>"Product is required"]);
if (!$city)    respond(["success"=>false,"error"=>"City is required"]);

// ── Extract Indian phone from any text ─────────────────────────
function extractPhone($text) {
    if (!$text) return "";
    // +91 format
    if (preg_match('/\+91[\s\-]?([6-9][0-9]{9})/', $text, $m)) return "+91".$m[1];
    // 091 format
    if (preg_match('/0([6-9][0-9]{9})/',            $text, $m)) return $m[1];
    // Raw 10-digit Indian mobile
    if (preg_match('/\b([6-9][0-9]{9})\b/',         $text, $m)) return $m[1];
    return "";
}

// ── Detect platform from URL ───────────────────────────────────
function detectPlatform($url) {
    $url = strtolower($url);
    $map = [
        "indiamart"      => "IndiaMart",
        "justdial"       => "JustDial",
        "tradeindia"     => "TradeIndia",
        "sulekha"        => "Sulekha",
        "udaan"          => "Udaan",
        "exportersindia" => "ExportersIndia",
        "yellowpages"    => "YellowPages",
        "alibaba"        => "Alibaba",
        "shopclues"      => "ShopClues",
        "flipkart"       => "Flipkart",
        "amazon"         => "Amazon",
    ];
    foreach ($map as $domain => $name) {
        if (strpos($url, $domain) !== false) return $name;
    }
    return "Web";
}

// ── Is this a category page? ───────────────────────────────────
function isCategoryPage($title, $url) {
    $junk = ["top ","best ","list of","directory","all ","find ","search ",
             "near me","dealers in","suppliers in","manufacturers in",
             "wholesalers in","companies in","exporters in",
             "/search","/listing","/category","/browse","?q=","?search"];
    $t = strtolower($title);
    $u = strtolower($url);
    foreach ($junk as $j) {
        if (strpos($t,$j)!==false || strpos($u,$j)!==false) return true;
    }
    return false;
}

// ── Serper Maps ────────────────────────────────────────────────
function serperMaps($q) {
    if (!defined("SERPER_KEY") || !SERPER_KEY) return [];
    $ch = curl_init("https://google.serper.dev/maps");
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>false,
        CURLOPT_HTTPHEADER=>["X-API-KEY: ".SERPER_KEY,"Content-Type: application/json"],
        CURLOPT_POSTFIELDS=>json_encode(["q"=>$q,"gl"=>"in","hl"=>"en"])
    ]);
    $res = curl_exec($ch); curl_close($ch);
    if (!$res) return [];
    $d = json_decode($res,true);
    return $d["places"] ?? [];
}

// ── Serper Search ──────────────────────────────────────────────
function serperSearch($q) {
    if (!defined("SERPER_KEY") || !SERPER_KEY) return [];
    $ch = curl_init("https://google.serper.dev/search");
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>false,
        CURLOPT_HTTPHEADER=>["X-API-KEY: ".SERPER_KEY,"Content-Type: application/json"],
        CURLOPT_POSTFIELDS=>json_encode(["q"=>$q,"gl"=>"in","hl"=>"en","num"=>10])
    ]);
    $res = curl_exec($ch); curl_close($ch);
    if (!$res) return [];
    $d = json_decode($res,true);
    return $d["organic"] ?? [];
}

// ══════════════════════════════════════════════════════════════
// 1. SEARCH OUR OWN DATABASE FIRST
// ══════════════════════════════════════════════════════════════
$dbSellers = [];
try {
    // Exact city + product match
    $stmt = db()->prepare("
        SELECT s.id, s.name, s.category, s.city, s.state,
               s.website, s.contact,
               s.is_featured, s.is_verified, s.featured_order,
               d.products_offered, d.business_type,
               d.address, d.whatsapp, d.working_hours
        FROM sellers s
        LEFT JOIN seller_details d ON d.seller_id = s.id
        WHERE LOWER(s.city) = LOWER(?)
        AND (
            s.category  LIKE ?
            OR s.name   LIKE ?
            OR d.products_offered LIKE ?
        )
        ORDER BY s.is_featured DESC, s.featured_order ASC, s.name ASC
    ");
    $stmt->execute([$city, "%$product%", "%$product%", "%$product%"]);
    $dbSellers = $stmt->fetchAll();

    // Expand to state if less than 3
    if (count($dbSellers) < 3 && $state) {
        $stmt2 = db()->prepare("
            SELECT s.id, s.name, s.category, s.city, s.state,
                   s.website, s.contact,
                   s.is_featured, s.is_verified, s.featured_order,
                   d.products_offered, d.business_type,
                   d.address, d.whatsapp, d.working_hours
            FROM sellers s
            LEFT JOIN seller_details d ON d.seller_id = s.id
            WHERE LOWER(s.state) = LOWER(?)
            AND LOWER(s.city) != LOWER(?)
            AND (
                s.category LIKE ?
                OR s.name  LIKE ?
                OR d.products_offered LIKE ?
            )
            ORDER BY s.is_featured DESC, s.featured_order ASC, s.name ASC
        ");
        $stmt2->execute([$state, $city, "%$product%", "%$product%", "%$product%"]);
        $extra = $stmt2->fetchAll();
        $dbSellers = array_merge($dbSellers, $extra);
    }
} catch(Throwable $e) {
    $dbSellers = [];
}

// ══════════════════════════════════════════════════════════════
// 2. GOOGLE MAPS — Primary source for real phone numbers
// ══════════════════════════════════════════════════════════════
$mapsResults = [];
$seenNames   = [];

$mapQueries = [
    "$product in $city",
    "$product dealer $city",
    "$product supplier $city",
    "$product wholesaler $city",
];

foreach ($mapQueries as $q) {
    $places = serperMaps($q);
    foreach ($places as $p) {
        $name = trim($p["title"] ?? "");
        if (!$name) continue;
        $key  = strtolower(preg_replace('/[^a-z0-9]/i','',$name));
        if (isset($seenNames[$key])) {
            // Already seen — just add phone if missing
            foreach ($mapsResults as &$ex) {
                $exKey = strtolower(preg_replace('/[^a-z0-9]/i','',$ex["name"]));
                if ($exKey === $key && !$ex["contact"] && !empty($p["phoneNumber"])) {
                    $ex["contact"]  = $p["phoneNumber"];
                    $ex["verified"] = true;
                }
            }
            unset($ex);
            continue;
        }
        $seenNames[$key] = true;

        $phone = $p["phoneNumber"] ?? "";
        // Also try extracting from address snippet
        if (!$phone) $phone = extractPhone($p["address"] ?? "");

        $mapsResults[] = [
            "name"      => $name,
            "contact"   => $phone,
            "address"   => $p["address"]    ?? $city,
            "website"   => $p["website"]    ?? "",
            "url"       => $p["website"]    ?? "",
            "rating"    => $p["rating"]     ?? null,
            "reviews"   => (int)($p["ratingCount"] ?? 0),
            "source"    => "Google Maps",
            "verified"  => !empty($phone),
            "platforms" => ["Google Maps"],
        ];
    }
}

// ══════════════════════════════════════════════════════════════
// 3. PLATFORM SEARCHES — Extract phones from snippets
// ══════════════════════════════════════════════════════════════
$platformSearches = [
    "$product seller in $city site:indiamart.com",
    "$product $city site:tradeindia.com",
    "$product $city site:sulekha.com",
    "$product supplier $city site:exportersindia.com",
    "$product $city site:yellowpages.in",
    "$product $city site:justdial.com",
    "$product supplier $city $state",
];

$platformResults = [];
$seenUrls        = [];

foreach ($platformSearches as $query) {
    $organic = serperSearch($query);
    foreach ($organic as $item) {
        $title = trim($item["title"]   ?? "");
        $url   = trim($item["link"]    ?? "");
        $snip  = trim($item["snippet"] ?? "");

        if (!$url || !$title)           continue;
        if (isset($seenUrls[$url]))     continue;
        if (isCategoryPage($title,$url)) continue;

        $seenUrls[$url] = true;
        $platform = detectPlatform($url);

        // Extract phone from snippet
        $phone = extractPhone($snip);

        // Cross-reference with Maps to get phone
        if (!$phone) {
            $titleClean = strtolower(preg_replace('/[^a-z0-9]/i','',$title));
            foreach ($mapsResults as $mr) {
                $mrClean = strtolower(preg_replace('/[^a-z0-9]/i','',$mr["name"]));
                similar_text($titleClean, $mrClean, $pct);
                if ($pct > 68 && $mr["contact"]) {
                    $phone = $mr["contact"];
                    break;
                }
            }
        }

        $platformResults[] = [
            "name"      => $title,
            "contact"   => $phone,
            "address"   => $snip,
            "url"       => $url,
            "source"    => $platform,
            "verified"  => !empty($phone),
            "platforms" => [$platform],
            "rating"    => null,
            "reviews"   => 0,
        ];
    }
}

// ══════════════════════════════════════════════════════════════
// 4. MERGE Maps + Platform results (deduplicate by name)
// ══════════════════════════════════════════════════════════════
$merged       = [];
$usedPlatIdx  = [];

foreach ($mapsResults as $mr) {
    $entry   = $mr;
    $mrClean = strtolower(preg_replace('/[^a-z0-9]/i','',$mr["name"]));

    foreach ($platformResults as $pi => $pr) {
        if (isset($usedPlatIdx[$pi])) continue;
        $prClean = strtolower(preg_replace('/[^a-z0-9]/i','',$pr["name"]));
        similar_text($mrClean, $prClean, $pct);

        if ($pct > 65) {
            // Same business — merge
            $entry["platforms"][] = $pr["source"];
            $entry["url_".$pr["source"]] = $pr["url"];
            if (!$entry["contact"] && $pr["contact"]) {
                $entry["contact"]  = $pr["contact"];
                $entry["verified"] = true;
            }
            $usedPlatIdx[$pi] = true;
        }
    }

    // Trust score
    $entry["trust_score"] = count(array_unique($entry["platforms"]));
    if ($entry["contact"])      $entry["trust_score"] += 4;
    if ($entry["rating"])       $entry["trust_score"] += 2;
    if ($entry["reviews"] > 10) $entry["trust_score"] += 2;
    if ($entry["website"])      $entry["trust_score"] += 1;

    $merged[] = $entry;
}

// Add unmatched platform results
foreach ($platformResults as $pi => $pr) {
    if (isset($usedPlatIdx[$pi])) continue;
    $pr["trust_score"] = count($pr["platforms"]);
    if ($pr["contact"]) $pr["trust_score"] += 4;
    $merged[] = $pr;
}

// Sort by trust score
usort($merged, fn($a,$b) => ($b["trust_score"]??0) - ($a["trust_score"]??0));
$merged = array_slice($merged, 0, 25);

// ── Platform grouping for pills ────────────────────────────────
$grouped = [];
foreach ($merged as $item) {
    $primary = $item["platforms"][0] ?? $item["source"] ?? "Web";
    if (!isset($grouped[$primary])) $grouped[$primary] = [];
    $grouped[$primary][] = $item;
}

// ── Cache to DB ────────────────────────────────────────────────
foreach ($merged as $item) {
    try {
        $ins = db()->prepare("INSERT INTO external_listings
            (platform,category,city,business_name,contact,url) VALUES (?,?,?,?,?,?)");
        $ins->execute([
            implode(",",$item["platforms"]??[$item["source"]??"Web"]),
            $product, $city,
            $item["name"],
            $item["contact"] ?? "",
            $item["url"]     ?? ""
        ]);
    } catch(Throwable $e){}
}

// Cast featured/verified fields to correct types
foreach ($dbSellers as &$s) {
    $s["is_featured"]    = (bool)($s["is_featured"]    ?? false);
    $s["is_verified"]    = (bool)($s["is_verified"]    ?? false);
    $s["featured_order"] = (int)($s["featured_order"]  ?? 0);
}
unset($s);

respond([
    "success"    => true,
    "db_sellers" => $dbSellers,
    "external"   => $merged,
    "grouped"    => $grouped,
    "counts"     => [
        "local"      => count($dbSellers),
        "external"   => count($merged),
        "with_phone" => count(array_filter($merged, fn($e) => !empty($e["contact"]))),
        "google_maps"=> count($mapsResults),
        "platforms"  => array_keys($grouped),
    ]
]);
?>