<?php
/**
 * competitor_intel.php  ·  BizBoost companion file
 * ──────────────────────────────────────────────────────────────────
 *
 * PURPOSE
 *   Provides the extra data that keywords.html needs but that
 *   seller_reserch_.php was never designed to return:
 *     - SWOT analysis (named competitors, real weaknesses)
 *     - Action Priority matrix (impact vs effort)
 *     - Outreach templates (email, WhatsApp, GBP description)
 *     - Pricing intelligence (scraped ₹ prices + undercut strategy)
 *     - Per-competitor price map
 *     - Seasonal demand chart
 *
 * HOW IT FITS IN
 *   seller_reserch_.php  ──► saves strategy to seo_reports
 *                        ──► returns { strategy, competitors, ... }
 *   Frontend             ──► saves full response to localStorage as "lastReport"
 *   This file            ──► receives { seller_id, competitors[] } from frontend
 *                        ──► does price scraping + one Groq call
 *                        ──► returns enriched intel
 *                        ──► frontend saves to localStorage as "lastIntel"
 *
 * seller_reserch_.php is NEVER modified, included, or called.
 *
 * CACHE TABLE  (create once — safe to run multiple times)
 *   CREATE TABLE IF NOT EXISTS competitor_intel (
 *     id           INT AUTO_INCREMENT PRIMARY KEY,
 *     seller_id    INT         NOT NULL,
 *     intel_json   MEDIUMTEXT,
 *     generated_at DATETIME    DEFAULT CURRENT_TIMESTAMP,
 *     INDEX idx_ci (seller_id, generated_at)
 *   );
 *
 * REQUEST  POST { "seller_id": 123, "competitors": [...], "force_refresh": false }
 *          competitors[] comes from localStorage.lastReport.competitors
 *
 * RESPONSE  { success, swot, opportunity_score, action_priority,
 *             outreach, pricing_intel, pricing_data,
 *             competitor_prices, competitor_price_map, ... }
 */

ob_start();
ini_set("display_errors", 0);
ini_set("display_startup_errors", 0);
error_reporting(0);
set_time_limit(180);

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

// ── Config (same config.php seller_reserch_.php uses) ────────────
if (!file_exists(__DIR__ . "/config.php"))
    respond(["success" => false, "error" => "config.php not found"]);

try { require_once __DIR__ . "/config.php"; }
catch (Exception $e) { respond(["success" => false, "error" => "Config: " . $e->getMessage()]); }

function cfg($k) {
    if (defined($k)) return constant($k);
    $v = strtolower($k); global $$v; if (!empty($$v)) return $$v;
    $v = strtoupper($k); global $$v; if (!empty($$v)) return $$v;
    return "";
}

$GROQ_KEY   = cfg("GROQ_KEY")   ?: "";
$SERPER_KEY = cfg("SERPER_KEY") ?: "";

if (!$GROQ_KEY)             respond(["success" => false, "error" => "GROQ_KEY not set"]);
if (!$SERPER_KEY)           respond(["success" => false, "error" => "SERPER_KEY not set"]);
if (!function_exists("db")) respond(["success" => false, "error" => "db() missing"]);
if ($_SERVER["REQUEST_METHOD"] !== "POST")
    respond(["success" => false, "error" => "POST required"]);

// ── Parse request ─────────────────────────────────────────────────
$body         = json_decode(file_get_contents("php://input"), true) ?: [];
$sid          = isset($body["seller_id"]) ? (int)$body["seller_id"] : 0;
$forceRefresh = !empty($body["force_refresh"]);

// Competitors passed from frontend (from localStorage.lastReport.competitors)
// These are ALREADY filtered/scored by seller_reserch_.php — we trust them fully
$competitors  = isset($body["competitors"]) && is_array($body["competitors"])
                ? $body["competitors"] : [];

if (!$sid) respond(["success" => false, "error" => "seller_id required"]);

// ── Load seller from DB (same query pattern as seller_reserch_.php) ─
try {
    $stmt = db()->prepare("
        SELECT s.id, s.name, s.email, s.category, s.city, s.state,
               s.website, s.contact,
               d.gst_number, d.business_type, d.employees,
               d.annual_turnover, d.products_offered,
               d.business_desc, d.address AS biz_address,
               d.pincode, d.certifications, d.delivery_radius
        FROM sellers s
        LEFT JOIN seller_details d ON d.seller_id = s.id
        WHERE s.id = ? LIMIT 1");
    $stmt->execute([$sid]);
    $seller = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$seller) respond(["success" => false, "error" => "Seller #$sid not found"]);
} catch (Exception $e) {
    respond(["success" => false, "error" => "DB: " . $e->getMessage()]);
}

// ── 24-hour cache check ───────────────────────────────────────────
if (!$forceRefresh) {
    try {
        $cs = db()->prepare(
            "SELECT intel_json, generated_at
             FROM competitor_intel
             WHERE seller_id = ?
             ORDER BY generated_at DESC LIMIT 1");
        $cs->execute([$sid]);
        $row = $cs->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $ageSeconds = time() - strtotime($row["generated_at"]);
            if ($ageSeconds < 86400) {
                $cached = json_decode($row["intel_json"], true);
                if (is_array($cached)) {
                    $cached["success"]        = true;
                    $cached["_cached"]        = true;
                    $cached["_cache_age_min"] = (int)round($ageSeconds / 60);
                    respond($cached);
                }
            }
        }
    } catch (Exception $e) {
        // Table may not exist yet — that's fine, we'll create it at save time
    }
}

// ══════════════════════════════════════════════════════════════════
//  CORE VARIABLES  (mirror the same logic as seller_reserch_.php
//  so variable names are consistent — no risk of drift)
// ══════════════════════════════════════════════════════════════════

$rawProducts = trim(
    !empty($seller["products_offered"]) ? $seller["products_offered"] :
    (!empty($seller["category"])        ? $seller["category"] : "")
);
$city       = trim($seller["city"]  ?: "");
$state      = trim($seller["state"] ?: "");
$sellerName = trim($seller["name"]  ?: "");

$productArr = array_values(array_filter(
    array_map("trim", preg_split('/[,;\/\|]+/', $rawProducts)),
    function($k) { return strlen($k) >= 2; }
));
$productArr = array_slice(array_unique($productArr), 0, 5);
if (empty($productArr)) $productArr = [$seller["category"] ?: "product"];

$kw1 = $productArr[0];
$kw2 = isset($productArr[1]) ? $productArr[1] : $kw1;

// Same type-detection logic as seller_reserch_.php
function detectTypeLocal($type, $name, $desc) {
    $t = strtolower("$type $name $desc");
    foreach (["wholesale","wholesaler","distributor","supplier","bulk","trader","stockist","b2b","importer","exporter"] as $k)
        if (strpos($t, $k) !== false) return "Wholesaler / Distributor";
    foreach (["manufacturer","manufacturing","factory","fabricator","industries","oem","producer","udyog","assembler"] as $k)
        if (strpos($t, $k) !== false) return "Manufacturer / Factory";
    foreach (["service","repair","installation","maintenance","contractor","installer","integrator"] as $k)
        if (strpos($t, $k) !== false) return "Service Provider / Installer";
    return "Retailer / Dealer / Shop";
}
$typeLabel = detectTypeLocal(
    $seller["business_type"] ?: "",
    $sellerName,
    $seller["business_desc"]  ?: ""
);

$compCount     = count($competitors);
$topCompetitor = $competitors[0] ?? null;
$topCompName   = $topCompetitor ? ($topCompetitor["name"]    ?? "")    : "";
$topCompRating = $topCompetitor ? ($topCompetitor["rating"]  ?? "N/A") : "N/A";
$topCompReviews= $topCompetitor ? (int)($topCompetitor["reviews"] ?? 0): 0;

// ══════════════════════════════════════════════════════════════════
//  API HELPERS
// ══════════════════════════════════════════════════════════════════

function doSearch($q, $key, $num = 10) {
    $ch = curl_init("https://google.serper.dev/search");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 14, CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ["X-API-KEY: $key", "Content-Type: application/json"],
        CURLOPT_POSTFIELDS => json_encode([
            "q" => $q, "gl" => "in", "hl" => "en", "num" => $num
        ]),
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    if (!$r) return [];
    $d = json_decode($r, true);
    return $d["organic"] ?? [];
}

function doShopping($q, $key) {
    $ch = curl_init("https://google.serper.dev/shopping");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 12, CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ["X-API-KEY: $key", "Content-Type: application/json"],
        CURLOPT_POSTFIELDS => json_encode([
            "q" => $q, "gl" => "in", "hl" => "en", "num" => 10
        ]),
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    if (!$r) return [];
    $d = json_decode($r, true);
    return $d["shopping"] ?? [];
}

function doGroqLocal($prompt, $key, $maxTok = 3000, $temp = 0.25) {
    $ch = curl_init("https://api.groq.com/openai/v1/chat/completions");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $key",
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => json_encode([
            "model"       => "llama-3.3-70b-versatile",
            "temperature" => $temp,
            "max_tokens"  => $maxTok,
            "messages"    => [
                [
                    "role"    => "system",
                    "content" => "Return only valid JSON. No markdown. No explanation. Be specific — always name the actual competitor, city, product. Never use placeholder text."
                ],
                [
                    "role"    => "user",
                    "content" => $prompt
                ],
            ],
        ]),
    ]);
    $r = curl_exec($ch);
    $e = curl_error($ch);
    $h = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ["raw" => $r, "err" => $e, "http" => (int)$h];
}

function parseGroqJson($gr) {
    if (!empty($gr["err"]) || $gr["http"] !== 200) return null;
    $d       = json_decode($gr["raw"], true);
    $content = $d["choices"][0]["message"]["content"] ?? "";
    if (!$content) return null;
    $content = preg_replace('/```(?:json)?\s*/i', '', $content);
    $content = trim(preg_replace('/```/', '', $content));
    $po = strpos($content, '{');
    $pa = strpos($content, '[');
    if ($po === false && $pa === false) return null;
    $s = ($po === false) ? $pa : (($pa === false) ? $po : min($po, $pa));
    return json_decode(substr($content, $s), true);
}

// ── Price extraction ──────────────────────────────────────────────
function extractPricesINR($text) {
    $prices = [];
    // Pattern 1: ₹ / Rs. / INR prefix
    if (preg_match_all('/(?:Rs\.?\s*|₹\s*|INR\s*)(\d[\d,]*)/i', $text, $m))
        foreach ($m[1] as $raw) {
            $v = (int)str_replace(",", "", $raw);
            if ($v >= 5 && $v <= 50000000) $prices[] = $v;
        }
    // Pattern 2: number/unit  e.g. 1500/piece, 200 per kg
    if (preg_match_all('/\b(\d[\d,]+)\s*\/?\s*(?:per\s+)?(?:piece|pcs|pc|unit|kg|litre|ltr|liter|mtr|meter|sqft|set|box|pair|nos|dozen)\b/i', $text, $m2))
        foreach ($m2[1] as $raw) {
            $v = (int)str_replace(",", "", $raw);
            if ($v >= 5 && $v <= 50000000) $prices[] = $v;
        }
    return $prices;
}

function buildPriceSummary($prices) {
    if (empty($prices)) return [];
    sort($prices);
    $c    = count($prices);
    $trim = max(1, (int)floor($c * 0.1));
    $t    = ($c > 2 * $trim) ? array_slice($prices, $trim, $c - 2 * $trim) : $prices;
    if (empty($t)) $t = $prices;
    $mid  = (int)floor(count($t) / 2);
    return [
        "min"    => (int)min($t),
        "max"    => (int)max($t),
        "median" => (int)$t[$mid],
        "mean"   => (int)round(array_sum($t) / count($t)),
        "count"  => $c,
    ];
}

// ══════════════════════════════════════════════════════════════════
//  STEP A: SCRAPE MARKET PRICES
//  4 sources: IndiaMART, JustDial, TradeIndia, Google Shopping
//  + generic rate-list search
// ══════════════════════════════════════════════════════════════════

$allPrices       = [];
$platformListings = [];   // array of { source, name, price_min, price_max, url }
$sourceCount      = ["indiamart" => 0, "justdial" => 0, "tradeindia" => 0, "shopping" => 0, "generic" => 0];

// IndiaMART
foreach (doSearch("$kw1 price site:indiamart.com", $SERPER_KEY, 10) as $r) {
    $blob   = ($r["snippet"] ?? "") . " " . ($r["title"] ?? "");
    $found  = extractPricesINR($blob);
    $allPrices = array_merge($allPrices, $found);
    $sourceCount["indiamart"] += count($found);
    if (!empty($found))
        $platformListings[] = [
            "source"    => "IndiaMART",
            "name"      => substr($r["title"] ?? "", 0, 80),
            "price_min" => min($found),
            "price_max" => max($found),
            "url"       => $r["link"] ?? "",
        ];
}

// IndiaMART — wholesale bulk search
foreach (doSearch("$kw1 wholesale price per unit $city site:indiamart.com", $SERPER_KEY, 8) as $r) {
    $found = extractPricesINR(($r["snippet"] ?? "") . " " . ($r["title"] ?? ""));
    $allPrices = array_merge($allPrices, $found);
    $sourceCount["indiamart"] += count($found);
}

// JustDial
foreach (doSearch("$kw1 price $city site:justdial.com", $SERPER_KEY, 8) as $r) {
    $blob  = ($r["snippet"] ?? "") . " " . ($r["title"] ?? "");
    $found = extractPricesINR($blob);
    $allPrices = array_merge($allPrices, $found);
    $sourceCount["justdial"] += count($found);
    if (!empty($found))
        $platformListings[] = [
            "source"    => "JustDial",
            "name"      => substr($r["title"] ?? "", 0, 80),
            "price_min" => min($found),
            "price_max" => max($found),
            "url"       => $r["link"] ?? "",
        ];
}

// TradeIndia
foreach (doSearch("$kw1 price per unit site:tradeindia.com", $SERPER_KEY, 6) as $r) {
    $blob  = ($r["snippet"] ?? "") . " " . ($r["title"] ?? "");
    $found = extractPricesINR($blob);
    $allPrices = array_merge($allPrices, $found);
    $sourceCount["tradeindia"] += count($found);
    if (!empty($found))
        $platformListings[] = [
            "source"    => "TradeIndia",
            "name"      => substr($r["title"] ?? "", 0, 80),
            "price_min" => min($found),
            "price_max" => max($found),
            "url"       => $r["link"] ?? "",
        ];
}

// Google Shopping
foreach (doShopping("$kw1 $city", $SERPER_KEY) as $sr) {
    $found = extractPricesINR(($sr["price"] ?? "") . " " . ($sr["title"] ?? ""));
    $allPrices = array_merge($allPrices, $found);
    $sourceCount["shopping"] += count($found);
    if (!empty($found))
        $platformListings[] = [
            "source"    => "Google Shopping",
            "name"      => substr($sr["title"] ?? "", 0, 80),
            "price_min" => min($found),
            "price_max" => max($found),
            "url"       => $sr["link"] ?? "",
        ];
}

// Generic rate-list searches
foreach (doSearch("$kw1 rate list $city $state 2025", $SERPER_KEY, 8) as $r)
    foreach (extractPricesINR(($r["snippet"] ?? "") . " " . ($r["title"] ?? "")) as $p) {
        $allPrices[] = $p;
        $sourceCount["generic"]++;
    }

foreach (doSearch("$kw1 $kw2 price per piece kg $state", $SERPER_KEY, 6) as $r)
    foreach (extractPricesINR(($r["snippet"] ?? "") . " " . ($r["title"] ?? "")) as $p)
        $allPrices[] = $p;

$priceSummary = buildPriceSummary($allPrices);

// ══════════════════════════════════════════════════════════════════
//  STEP B: PER-COMPETITOR PRICE SCRAPING
//  For each of the top 5 competitors that seller_reserch_.php found,
//  run a targeted search to find their specific pricing.
// ══════════════════════════════════════════════════════════════════

$competitorPriceMap = [];   // "Sharma Traders" => { min, max, median, count }

foreach (array_slice($competitors, 0, 5) as $comp) {
    $cName = trim($comp["name"] ?? "");
    if (!$cName) continue;

    $cPrices = [];

    // Search 1: their name + product + price
    foreach (doSearch("\"$cName\" $kw1 price", $SERPER_KEY, 5) as $r)
        foreach (extractPricesINR(($r["snippet"] ?? "") . " " . ($r["title"] ?? "")) as $p) {
            $cPrices[]  = $p;
            $allPrices[] = $p;  // also add to global pool
        }

    // Search 2: their website if available
    if (!empty($comp["website"])) {
        $domain = parse_url($comp["website"], PHP_URL_HOST) ?: "";
        if ($domain)
            foreach (doSearch("$kw1 price site:$domain", $SERPER_KEY, 5) as $r)
                foreach (extractPricesINR(($r["snippet"] ?? "") . " " . ($r["title"] ?? "")) as $p) {
                    $cPrices[]  = $p;
                    $allPrices[] = $p;
                }
    }

    if (!empty($cPrices)) {
        sort($cPrices);
        $mid = (int)floor(count($cPrices) / 2);
        $competitorPriceMap[$cName] = [
            "min"    => (int)min($cPrices),
            "max"    => (int)max($cPrices),
            "median" => (int)$cPrices[$mid],
            "count"  => count($cPrices),
        ];
    }
}

// Rebuild price summary now that per-competitor prices are included
$priceSummary = buildPriceSummary($allPrices);

// ══════════════════════════════════════════════════════════════════
//  STEP C: BUILD GROQ CONTEXT
//  Everything is named specifically — no generic placeholders
// ══════════════════════════════════════════════════════════════════

// Competitor list string — named explicitly for Groq
$compListStr = empty($competitors)
    ? "No verified direct competitors found in $city for $rawProducts."
    : implode("\n", array_map(function($c) {
        $hasWeb  = !empty($c["website"]) ? "HAS WEBSITE" : "NO WEBSITE";
        $hasPh   = !empty($c["phone"])   ? "HAS PHONE"   : "no phone listed";
        return "- " . ($c["name"] ?? "")
            . " | " . ($c["rating"] ? "★" . $c["rating"] : "no rating")
            . " (" . ($c["reviews"] ?? 0) . " reviews)"
            . " | $hasWeb | $hasPh"
            . " | " . substr($c["address"] ?? "", 0, 55);
    }, array_slice($competitors, 0, 15)));

// Which competitors have no website = easy to beat online
$noWebNames = implode(", ", array_map(
    function($c) { return $c["name"] ?? ""; },
    array_filter(array_slice($competitors, 0, 8), function($c) { return empty($c["website"]); })
));

// Price context string
if (!empty($priceSummary)) {
    $priceCtx = "SCRAPED MARKET PRICES for \"$rawProducts\":\n"
        . "  Lowest:  ₹" . number_format($priceSummary["min"])    . "\n"
        . "  Highest: ₹" . number_format($priceSummary["max"])    . "\n"
        . "  Median:  ₹" . number_format($priceSummary["median"]) . "\n"
        . "  Samples: "  . $priceSummary["count"]                 . " data points";
} else {
    $priceCtx = "PRICE DATA: None found from search. Estimate realistic prices for $rawProducts in $city.";
}

$compPriceCtx = empty($competitorPriceMap)
    ? "Per-competitor prices: none found."
    : "PER-COMPETITOR SCRAPED PRICES:\n" . implode("\n", array_map(
        function($name, $p) {
            return "  $name: ₹" . number_format($p["min"])
                . " – ₹" . number_format($p["max"])
                . " (median ₹" . number_format($p["median"]) . ", "
                . $p["count"] . " samples)";
        },
        array_keys($competitorPriceMap),
        array_values($competitorPriceMap)
    ));

// Top competitor's specific price (if scraped)
$topCompPriceNote = "";
if ($topCompName && isset($competitorPriceMap[$topCompName])) {
    $tp = $competitorPriceMap[$topCompName];
    $topCompPriceNote = " — scraped price ₹" . number_format($tp["min"])
        . "–₹" . number_format($tp["max"])
        . " (median ₹" . number_format($tp["median"]) . ")";
}

// Seller profile gaps — named for Groq to reference
$hasSite = !empty($seller["website"])       ? "YES" : "NO";
$hasGst  = !empty($seller["gst_number"])    ? "YES (GST: " . $seller["gst_number"] . ")" : "NO";
$hasCert = !empty($seller["certifications"])? "YES: " . $seller["certifications"]  : "NONE";

$missingFields = [];
if (empty($seller["website"]))        $missingFields[] = "website";
if (empty($seller["gst_number"]))     $missingFields[] = "GST number";
if (empty($seller["certifications"])) $missingFields[] = "certifications";
if (empty($seller["business_desc"]))  $missingFields[] = "business description";
$missingStr = empty($missingFields) ? "none" : implode(", ", $missingFields);

// ══════════════════════════════════════════════════════════════════
//  STEP D: SINGLE GROQ CALL
//  Returns everything needed for keywords.html's enriched tabs.
//  Strict rules: named competitors, real ₹ numbers, no generic text.
// ══════════════════════════════════════════════════════════════════

$groqPrompt = "You are a senior Indian SMB growth strategist. Write a PERSONALISED report for ONE specific business.

HARD RULES — violation = useless output:
1. NEVER say \"your competitors\" — always name them: \"$topCompName\", \"[next competitor name]\", etc.
2. NEVER say \"competitive pricing\" — always say \"₹1,080 (10% below $topCompName's ₹1,200)\"
3. NEVER use placeholder text like [product] or [city] — use the actual values
4. Every weakness must reference a REAL missing field from the seller's profile
5. Every threat must name a SPECIFIC competitor from the list below

SELLER PROFILE:
  Name:        $sellerName
  Type:        $typeLabel
  Products:    $rawProducts
  City:        $city, $state
  Website:     $hasSite
  GST:         $hasGst
  Certs:       $hasCert
  Employees:   " . ($seller["employees"]     ?: "unknown") . "
  Turnover:    " . ($seller["annual_turnover"] ?: "unknown") . "
  Missing:     $missingStr

VERIFIED COMPETITORS ($compCount found in $city — already filtered to same product + same type):
  Top: $topCompName (★$topCompRating, $topCompReviews reviews)$topCompPriceNote
  No website (easy to beat online): $noWebNames
  Full list:
$compListStr

$priceCtx

$compPriceCtx

Return ONLY valid JSON — all strings must be specific to $sellerName / $city / $rawProducts:
{
  \"swot\": {
    \"strengths\":      [\"strength 1 specific to $sellerName\", \"strength 2\", \"strength 3\"],
    \"weaknesses\":     [\"weakness referencing a missing field\", \"weakness 2\", \"weakness 3\"],
    \"opportunities\":  [\"opportunity in $city for $rawProducts\", \"opportunity 2\", \"opportunity 3\"],
    \"threats\":        [\"threat naming $topCompName specifically\", \"threat 2\", \"threat 3\"]
  },
  \"opportunity_score\":     72,
  \"opportunity_reasoning\": \"1-2 sentence specific to $sellerName in $city with real competitor names\",

  \"top_competitor_analysis\": {
    \"name\":            \"$topCompName\",
    \"why_winning\":     \"specific reason e.g. X reviews, ranked #1 Maps for [$rawProducts] in $city\",
    \"their_weaknesses\": [\"real weakness 1\", \"weakness 2\", \"weakness 3\"],
    \"how_to_beat_them\": [\"tactic naming $topCompName\", \"tactic 2\", \"tactic 3\", \"tactic 4\"]
  },

  \"action_priority\": [
    {\"action\": \"specific action for $sellerName\",  \"impact\": \"high\",   \"effort\": \"low\",    \"timeline\": \"2 days\"},
    {\"action\": \"specific action\",                  \"impact\": \"high\",   \"effort\": \"medium\", \"timeline\": \"1 week\"},
    {\"action\": \"specific action naming $topCompName\", \"impact\": \"high\", \"effort\": \"medium\", \"timeline\": \"2 weeks\"},
    {\"action\": \"specific action\",                  \"impact\": \"medium\", \"effort\": \"low\",    \"timeline\": \"3 days\"},
    {\"action\": \"specific action\",                  \"impact\": \"high\",   \"effort\": \"high\",   \"timeline\": \"1 month\"},
    {\"action\": \"specific action\",                  \"impact\": \"low\",    \"effort\": \"low\",    \"timeline\": \"this week\"}
  ],

  \"whatsapp_strategy\": [
    \"WhatsApp tactic 1 specific to $rawProducts in $city\",
    \"tactic 2\", \"tactic 3\", \"tactic 4\"
  ],

  \"outreach\": {
    \"cold_email_subject\":         \"specific subject line for $rawProducts buyers\",
    \"cold_email_body\":            \"Hi [Name],\n\nI'm [your name] from $sellerName, a $typeLabel based in $city...\n[write complete 150-200 word email]\n\nRegards,\n[Your Name]\n$sellerName\",
    \"whatsapp_message_template\":  \"Hi [Name]! 👋 I'm from $sellerName in $city...[write complete 60-word message]\",
    \"follow_up_message\":          \"Hi [Name], following up on my message about $rawProducts from $sellerName...[write it]\",
    \"google_business_description\": \"$sellerName — $typeLabel in $city specialising in [products]. [USP]. Call/WhatsApp [number]. [150 chars max]\",
    \"indiamart_catalog_tip\":      \"specific tip for listing $rawProducts on IndiaMART to rank above $topCompName\"
  },

  \"pricing_intel\": {
    \"recommended_price\": {
      \"value\":     0,
      \"unit\":      \"per piece\",
      \"reasoning\": \"Price at ₹X, which is Y% below $topCompName's ₹Z — positions $sellerName as value leader\"
    },
    \"undercut_strategy\": {
      \"target_competitor\":   \"$topCompName\",
      \"their_price\":         0,
      \"your_suggested_price\": 0,
      \"undercut_percent\":    0,
      \"positioning\":         \"How $sellerName should communicate this price advantage in WhatsApp / IndiaMART\"
    },
    \"pricing_tiers\": [
      {\"tier\": \"Bulk\",    \"price\": 0, \"min_quantity\": \"100+ units\", \"note\": \"target B2B buyers in $city\"},
      {\"tier\": \"Standard\",\"price\": 0, \"min_quantity\": \"10+ units\",  \"note\": \"\"},
      {\"tier\": \"Premium\", \"price\": 0, \"min_quantity\": \"1+ unit\",    \"note\": \"urgent / branded packaging\"}
    ],
    \"margin_estimate\":          \"\",
    \"price_positioning_advice\": \"specific advice for $sellerName vs $topCompName in $city\",
    \"when_to_raise_price\":      \"\",
    \"seasonal_pricing\":         \"\",
    \"peak_months\":  [\"month1\", \"month2\", \"month3\"],
    \"slow_months\":  [\"month1\", \"month2\"],
    \"seasonal_demand\": [
      {\"month\":\"Jan\",\"demand\":\"low\",   \"price_tip\":\"\"},
      {\"month\":\"Feb\",\"demand\":\"medium\",\"price_tip\":\"\"},
      {\"month\":\"Mar\",\"demand\":\"high\",  \"price_tip\":\"\"},
      {\"month\":\"Apr\",\"demand\":\"high\",  \"price_tip\":\"\"},
      {\"month\":\"May\",\"demand\":\"medium\",\"price_tip\":\"\"},
      {\"month\":\"Jun\",\"demand\":\"low\",   \"price_tip\":\"\"},
      {\"month\":\"Jul\",\"demand\":\"low\",   \"price_tip\":\"\"},
      {\"month\":\"Aug\",\"demand\":\"medium\",\"price_tip\":\"\"},
      {\"month\":\"Sep\",\"demand\":\"high\",  \"price_tip\":\"\"},
      {\"month\":\"Oct\",\"demand\":\"high\",  \"price_tip\":\"\"},
      {\"month\":\"Nov\",\"demand\":\"high\",  \"price_tip\":\"\"},
      {\"month\":\"Dec\",\"demand\":\"medium\",\"price_tip\":\"\"}
    ],
    \"revenue_potential\":   \"High / Medium / Low — one sentence specific to $rawProducts in $city\",
    \"top_buying_channels\": [\"channel 1\", \"channel 2\", \"channel 3\"]
  }
}";

$gr       = doGroqLocal($groqPrompt, $GROQ_KEY, 3500, 0.25);
$groqData = parseGroqJson($gr);
$groqErr  = "";

if (!$groqData || !is_array($groqData)) {
    $groqData = [];
    $groqErr  = empty($gr["err"])
        ? "Parse failed — raw: " . substr($gr["raw"] ?? "", 0, 300)
        : "cURL: " . $gr["err"];
}

// ══════════════════════════════════════════════════════════════════
//  ASSEMBLE FINAL RESPONSE
// ══════════════════════════════════════════════════════════════════

$result = [
    "success"      => true,
    "_cached"      => false,
    "_generated"   => date("c"),

    // ── SWOT & opportunity ──────────────────────────────────────
    "swot"                    => $groqData["swot"]                    ?? [],
    "opportunity_score"       => $groqData["opportunity_score"]       ?? 60,
    "opportunity_reasoning"   => $groqData["opportunity_reasoning"]   ?? "",
    "top_competitor_analysis" => $groqData["top_competitor_analysis"] ?? [],

    // ── Action plan ─────────────────────────────────────────────
    "action_priority"   => $groqData["action_priority"]   ?? [],
    "whatsapp_strategy" => $groqData["whatsapp_strategy"]  ?? [],

    // ── Outreach templates ───────────────────────────────────────
    "outreach" => $groqData["outreach"] ?? [],

    // ── Pricing ──────────────────────────────────────────────────
    "pricing_intel"        => $groqData["pricing_intel"]  ?? [],
    "pricing_data"         => $priceSummary,              // raw scraped numbers
    "competitor_prices"    => array_slice($platformListings, 0, 20),
    "competitor_price_map" => $competitorPriceMap,

    // ── Competitors echo-back (for UI reference) ─────────────────
    // These are the SAME competitors seller_reserch_.php found —
    // we echo them back so the UI has a single source of truth.
    "competitors_summary" => array_map(function($c) {
        return [
            "name"    => $c["name"]    ?? "",
            "rating"  => $c["rating"]  ?? null,
            "reviews" => (int)($c["reviews"] ?? 0),
            "website" => $c["website"] ?? "",
            "phone"   => $c["phone"]   ?? "",
            "address" => $c["address"] ?? "",
            "source"  => $c["source"]  ?? "",
        ];
    }, array_slice($competitors, 0, 25)),

    // ── Debug ────────────────────────────────────────────────────
    "debug" => [
        "competitors_received" => $compCount,
        "top_competitor"       => $topCompName,
        "prices_found"         => count($allPrices),
        "comp_prices_mapped"   => count($competitorPriceMap),
        "source_counts"        => $sourceCount,
        "groq_http"            => $gr["http"] ?? 0,
        "groq_error"           => $groqErr,
        "price_summary"        => $priceSummary,
    ],
];

// ── Save to cache ─────────────────────────────────────────────────
try {
    db()->prepare(
        "INSERT INTO competitor_intel (seller_id, intel_json) VALUES (?, ?)"
    )->execute([$sid, json_encode($result)]);
} catch (Exception $e) {
    // Table may not exist yet — create it then retry
    try {
        db()->exec("
            CREATE TABLE IF NOT EXISTS competitor_intel (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                seller_id    INT         NOT NULL,
                intel_json   MEDIUMTEXT,
                generated_at DATETIME    DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ci (seller_id, generated_at)
            )
        ");
        db()->prepare(
            "INSERT INTO competitor_intel (seller_id, intel_json) VALUES (?, ?)"
        )->execute([$sid, json_encode($result)]);
    } catch (Exception $e2) { /* ignore — cache is optional */ }
}

respond($result);
?>