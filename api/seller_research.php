<?php
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
    ob_end_clean(); echo json_encode(array("success"=>true)); exit;
}

function respond($d) {
    ob_end_clean();
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Config ─────────────────────────────────────────────────────
if (!file_exists(__DIR__ . "/config.php"))
    respond(array("success"=>false,"error"=>"config.php not found"));
try { require_once __DIR__ . "/config.php"; }
catch (Exception $e) { respond(array("success"=>false,"error"=>"Config: ".$e->getMessage())); }

function cfg($k) {
    if (defined($k)) return constant($k);
    $v = strtolower($k); global $$v; if (!empty($$v)) return $$v;
    $v = strtoupper($k); global $$v; if (!empty($$v)) return $$v;
    return "";
}
$GROQ_KEY   = cfg("GROQ_KEY")   ?: "";
$SERPER_KEY = cfg("SERPER_KEY") ?: "";
if (!$GROQ_KEY)   respond(array("success"=>false,"error"=>"GROQ_KEY not set"));
if (!$SERPER_KEY) respond(array("success"=>false,"error"=>"SERPER_KEY not set"));
if (!function_exists("db")) respond(array("success"=>false,"error"=>"db() missing"));
if ($_SERVER["REQUEST_METHOD"] !== "POST") respond(array("success"=>false,"error"=>"POST required"));

$raw  = file_get_contents("php://input");
$body = json_decode($raw, true);
$sid  = isset($body["seller_id"]) ? (int)$body["seller_id"] : 0;
if (!$sid) respond(array("success"=>false,"error"=>"seller_id required"));

// ── Load seller ────────────────────────────────────────────────
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
    $stmt->execute(array($sid));
    $seller = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$seller) respond(array("success"=>false,"error"=>"Seller #$sid not found"));
} catch (Exception $e) {
    respond(array("success"=>false,"error"=>"DB: ".$e->getMessage()));
}

// ══════════════════════════════════════════════════════════════
//  STEP 1: DETECT BUSINESS TYPE
// ══════════════════════════════════════════════════════════════
function detectBusinessType($type, $name, $desc) {
    $t = strtolower(($type?:"")." ".($name?:"")." ".($desc?:""));
    $w = array("wholesale","wholesaler","distributor","distribution","supplier",
               "supply","bulk","trader","trading","stockist","b2b","importer","exporter");
    $m = array("manufacturer","manufacturing","factory","fabricator",
               "fabrication","producer","industries","industry","udyog","oem","assembler");
    $s = array("service","repair","installation","maintenance","contractor","installer","integrator");
    foreach ($w as $k) if (strpos($t,$k)!==false) return "wholesale";
    foreach ($m as $k) if (strpos($t,$k)!==false) return "manufacturer";
    foreach ($s as $k) if (strpos($t,$k)!==false) return "service";
    return "retail";
}

$detectedType = detectBusinessType(
    $seller["business_type"] ?: "",
    $seller["name"]          ?: "",
    $seller["business_desc"] ?: ""
);

$typeLabels = array(
    "wholesale"    => "Wholesaler / Distributor",
    "manufacturer" => "Manufacturer / Factory",
    "service"      => "Service Provider / Installer",
    "retail"       => "Retailer / Dealer / Shop",
);
$typeLabel = $typeLabels[$detectedType];

// Query words per type
$typeQWords = array(
    "wholesale"    => array("wholesaler","distributor","supplier","bulk supplier","stockist","trader"),
    "manufacturer" => array("manufacturer","factory","producer","fabricator","industries","oem"),
    "service"      => array("service center","installer","repair center","contractor","service provider"),
    "retail"       => array("shop","dealer","store","outlet","showroom","retailer"),
);
$qWords = $typeQWords[$detectedType];

// Human description of what a competitor looks like
$typeCompDesc = array(
    "wholesale"    => "a wholesaler OR distributor OR bulk supplier OR stockist of the SAME products",
    "manufacturer" => "a manufacturer OR factory OR OEM producer of the SAME products",
    "service"      => "a service provider OR installer OR repair center for the SAME products",
    "retail"       => "a retail shop OR dealer OR store that PRIMARILY sells the SAME products",
);
$compDesc = $typeCompDesc[$detectedType];

// ══════════════════════════════════════════════════════════════
//  STEP 2: BLOCKERS
// ══════════════════════════════════════════════════════════════

// Block bad Maps API categories
function isCategoryBlocked($cat) {
    if (!$cat) return false;
    $c = strtolower(trim($cat));
    $blocked = array(
        "bus station","transit station","train station","railway station","metro station",
        "airport","ferry terminal","taxi service","truck stop",
        "tourist attraction","point of interest","monument","memorial","historical landmark",
        "sculpture","statue","artwork","scenic point",
        "hindu temple","mosque","church","gurudwara","jain temple","place of worship","shrine","dargah",
        "wholesale market","grain market","vegetable market","produce market",
        "fish market","flower market","cattle market","mandi","bazaar",
        "shopping mall","shopping center","shopping complex",
        "restaurant","fast food","cafe","bar","bakery","sweet shop","ice cream",
        "hotel","motel","lodge","hostel","guest house","resort","banquet hall",
        "hospital","clinic","doctor","dentist","pharmacy","diagnostic center","nursing home",
        "school","college","university","library","tutoring center","driving school",
        "gym","fitness center","yoga studio","spa","nail salon","hair salon","barbershop",
        "lawyer","law firm","accounting","insurance agency","real estate agency","travel agency",
        "gas station","petrol station","fuel station","car wash","parking",
        "post office","government office","police station","bank","atm",
        "grocery store","supermarket","convenience store","hypermarket",
        "park","garden","playground","stadium","swimming pool",
    );
    foreach ($blocked as $k) if (strpos($c,$k)!==false) return true;
    return false;
}

// Block national chains
function isChainStore($name) {
    $n = strtolower(trim($name));
    $chains = array(
        "croma","reliance digital","vijay sales","vijay electronic","ezone","next retail",
        "samsung smart","apple store","apple premium","apple authorised","sony centre","sony center",
        "lg best shop","poorvika","sangeetha","univercell","lot mobiles","mi store","oneplus",
        "xiaomi store","oppo store","vivo store","realme store",
        "pantaloons","shoppers stop","lifestyle store","max fashion","westside","zara","h&m",
        "manyavar","fabindia","biba store","reliance trends","v-mart","vmart",
        "bata store","bata shoe","liberty shoes","woodland store","metro shoes",
        "decathlon","nike store","adidas store","puma store","reebok store",
        "titan world","tanishq","malabar gold","kalyan jewellers","joyalukkas",
        "d-mart","dmart","big bazaar","more supermarket","reliance fresh","reliance smart",
        "hypercity","lulu hypermarket","spar hypermarket","metro cash","vishal mega mart",
        "dominos","domino's","mcdonalds","mcdonald's","burger king","kfc",
        "subway","pizza hut","starbucks","cafe coffee day","ccd","barista",
        "airtel store","jio point","jio store","vodafone","vi store",
        "apollo pharmacy","medplus","maruti suzuki","hyundai motor",
        "tata motors showroom","honda cars","toyota showroom","mahindra showroom",
        "mg motors","kia showroom","hero motocorp","bajaj showroom","tvs motor",
        "royal enfield showroom","ikea","pepperfry","lenskart",
    );
    foreach ($chains as $k) if (strpos($n,$k)!==false) return true;
    if (preg_match('/\b(mall|hypermarket|superstore|megastore)\b/i',$name)) return true;
    return false;
}

// Block geographic POIs by name pattern
function isNamePOI($name) {
    $n = strtolower(trim($name));
    $patterns = array(
        "bus station","bus stand","bus depot","bus terminal",
        "railway station","train station","metro station",
        "wholesale grain","grain market","wholesale mandi","anaj mandi","sabzi mandi",
        "vegetable market","wholesale vegetable","fish market","meat market",
        "flower market","general wholesale market",
        " temple"," mandir"," masjid"," mosque"," church"," gurudwara"," dargah",
        " statue"," monument"," memorial"," fort"," palace"," museum",
        " lake "," garden"," park "," stadium",
        " hospital"," clinic"," school"," college"," university",
        "petrol pump","fuel station","gas station","cng station",
    );
    foreach ($patterns as $p) if (strpos($n,trim($p))!==false) return true;
    // Pure location names: "Mangal Bazar", "Sardar Market"
    if (preg_match('/^[\w\s\.]+(bazar|bazaar|market|mandi|chowk|chawk|darwaza|darwaja|crossing|circle)\s*\.?$/i',trim($name))) return true;
    return false;
}

// Score how closely name/address matches product keywords
function productRelevanceScore($name, $address, $keywords) {
    $text  = strtolower($name." ".$address);
    $score = 0;
    foreach ($keywords as $kw) {
        if (stripos($name,$kw)    !==false) $score += 10;
        if (stripos($address,$kw) !==false) $score += 3;
        foreach (explode(" ",$kw) as $w)
            if (strlen($w)>=4 && stripos($text,$w)!==false) $score += 2;
    }
    return $score;
}

// City variants for address matching
function getCityVariants($city) {
    $city = strtolower(trim($city));
    $map = array(
        "vadodara"=>"vadodara,baroda","mumbai"=>"mumbai,bombay,navi mumbai",
        "bengaluru"=>"bengaluru,bangalore","kolkata"=>"kolkata,calcutta",
        "chennai"=>"chennai,madras","hyderabad"=>"hyderabad,secunderabad,cyberabad",
        "ahmedabad"=>"ahmedabad,ahmadabad,amdavad","pune"=>"pune,pimpri,chinchwad",
        "surat"=>"surat","jaipur"=>"jaipur","lucknow"=>"lucknow","nagpur"=>"nagpur",
        "indore"=>"indore","bhopal"=>"bhopal","visakhapatnam"=>"visakhapatnam,vizag",
        "coimbatore"=>"coimbatore,kovai","kochi"=>"kochi,cochin,ernakulam",
        "chandigarh"=>"chandigarh,mohali,panchkula","delhi"=>"delhi,new delhi",
        "noida"=>"noida,greater noida","gurugram"=>"gurugram,gurgaon",
        "guwahati"=>"guwahati,gauhati","mysuru"=>"mysuru,mysore",
        "varanasi"=>"varanasi,banaras,kashi","allahabad"=>"allahabad,prayagraj",
        "rajkot"=>"rajkot","nashik"=>"nashik,nasik","aurangabad"=>"aurangabad",
        "hubli"=>"hubli,dharwad,hubballi","mangaluru"=>"mangaluru,mangalore",
        "madurai"=>"madurai","vijayawada"=>"vijayawada","agra"=>"agra",
        "meerut"=>"meerut","ludhiana"=>"ludhiana","amritsar"=>"amritsar",
        "jalandhar"=>"jalandhar","dehradun"=>"dehradun","ranchi"=>"ranchi",
        "raipur"=>"raipur","bhubaneswar"=>"bhubaneswar,cuttack",
        "udaipur"=>"udaipur","jodhpur"=>"jodhpur","ajmer"=>"ajmer",
        "gwalior"=>"gwalior","jabalpur"=>"jabalpur","kota"=>"kota",
    );
    if (isset($map[$city])) return explode(",",$map[$city]);
    return array($city);
}

function isInCity($address, $city) {
    if (!$address || !$city) return true;
    $addr = strtolower($address);
    foreach (getCityVariants($city) as $v)
        if (strpos($addr,strtolower(trim($v)))!==false) return true;
    return false;
}

// ── API helpers ────────────────────────────────────────────────
function doSerperMaps($q,$key,$city,$state,$num=20) {
    if (!$key) return array();
    $ch = curl_init("https://google.serper.dev/maps");
    curl_setopt_array($ch,array(
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
        CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>false,
        CURLOPT_HTTPHEADER=>array("X-API-KEY: $key","Content-Type: application/json"),
        CURLOPT_POSTFIELDS=>json_encode(array(
            "q"=>$q,"location"=>"$city, $state, India","gl"=>"in","hl"=>"en","num"=>$num
        ))
    ));
    $r=curl_exec($ch);curl_close($ch);
    if(!$r) return array();
    $d=json_decode($r,true);
    return isset($d["places"])?$d["places"]:array();
}

function doSerperSearch($q,$key) {
    if (!$key) return array();
    $ch=curl_init("https://google.serper.dev/search");
    curl_setopt_array($ch,array(
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
        CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>false,
        CURLOPT_HTTPHEADER=>array("X-API-KEY: $key","Content-Type: application/json"),
        CURLOPT_POSTFIELDS=>json_encode(array("q"=>$q,"gl"=>"in","hl"=>"en","num"=>10))
    ));
    $r=curl_exec($ch);curl_close($ch);
    if(!$r) return array();
    $d=json_decode($r,true);
    return isset($d["organic"])?$d["organic"]:array();
}

function doGroq($prompt,$key,$maxTok,$temp) {
    $ch=curl_init("https://api.groq.com/openai/v1/chat/completions");
    curl_setopt_array($ch,array(
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
        CURLOPT_TIMEOUT=>55,CURLOPT_SSL_VERIFYPEER=>false,
        CURLOPT_HTTPHEADER=>array("Authorization: Bearer $key","Content-Type: application/json"),
        CURLOPT_POSTFIELDS=>json_encode(array(
            "model"=>"llama-3.3-70b-versatile","temperature"=>$temp,"max_tokens"=>$maxTok,
            "messages"=>array(
                array("role"=>"system","content"=>"Return only valid JSON. No markdown. No explanation."),
                array("role"=>"user","content"=>$prompt)
            )
        ))
    ));
    $r=curl_exec($ch);$e=curl_error($ch);$h=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array("raw"=>$r,"err"=>$e,"http"=>$h);
}

function extractJson($text) {
    $text=preg_replace('/```(?:json)?\s*/i','',$text);
    $text=trim(preg_replace('/```/','',$text));
    $po=strpos($text,'{');$pa=strpos($text,'[');
    if($po===false&&$pa===false) return null;
    if($po===false) $s=$pa; elseif($pa===false) $s=$po; else $s=min($po,$pa);
    return json_decode(substr($text,$s),true);
}

// ══════════════════════════════════════════════════════════════
//  MAIN
// ══════════════════════════════════════════════════════════════
$rawProducts = trim(
    !empty($seller["products_offered"]) ? $seller["products_offered"] :
    (!empty($seller["category"])        ? $seller["category"]         : "")
);
$city   = trim($seller["city"]  ?: "");
$state  = trim($seller["state"] ?: "");
$ownKey = strtolower(preg_replace('/[^a-z0-9]/i','',$seller["name"]));
$cat    = trim($seller["category"] ?: "");

$productArr = array_values(array_filter(
    array_map("trim", preg_split('/[,;\/\|]+/',$rawProducts)),
    function($k){ return strlen($k)>=2; }
));
$productArr = array_slice(array_unique($productArr),0,5);
if (empty($productArr)) $productArr = array($cat ?: "business");
$productKeywords = array_map("strtolower",$productArr);

$kw1 = isset($productArr[0]) ? $productArr[0] : "";
$kw2 = isset($productArr[1]) ? $productArr[1] : $kw1;

// ══════════════════════════════════════════════════════════════
//  STEP 3: BUILD HIGHLY SPECIFIC QUERIES
//  Every query = product + business-type qualifier + city
//  NO naked "product city" queries — they return POIs
// ══════════════════════════════════════════════════════════════
$queries = array();

// Primary: product × qualifier × city
foreach (array_slice($productArr,0,4) as $prod) {
    foreach (array_slice($qWords,0,4) as $qw) {
        $queries[] = "$prod $qw $city";
    }
}

// Secondary: multi-keyword
if ($kw2 !== $kw1) {
    $queries[] = "$kw1 $kw2 ".$qWords[0]." $city";
}

// Tertiary: category if different from product
if ($cat && strtolower($cat) !== strtolower($kw1)) {
    $queries[] = "$cat ".$qWords[0]." $city";
    if (isset($qWords[1])) $queries[] = "$cat ".$qWords[1]." $city";
}

// Type-specific extras
if ($detectedType==="wholesale" || $detectedType==="manufacturer") {
    $queries[] = "$kw1 distributor $city";
    $queries[] = "$kw1 bulk supplier $city";
    $queries[] = "$kw1 wholesale price $city";
    $queries[] = "$kw1 stockist $city";
}
if ($detectedType==="service") {
    $queries[] = "$kw1 service $city";
    $queries[] = "$kw1 installation $city";
    $queries[] = "$kw1 repair $city";
}
if ($detectedType==="retail") {
    $queries[] = "$kw1 shop near $city";
    $queries[] = "buy $kw1 $city";
    $queries[] = "$kw1 showroom $city";
}

$queries      = array_slice(array_unique($queries),0,16);
$debugQueries = $queries;

// ── Fetch Maps ─────────────────────────────────────────────────
$allPlaces     = array();
$outOfCitySkip = 0;
$categorySkip  = 0;

foreach ($queries as $q) {
    $res = doSerperMaps($q,$SERPER_KEY,$city,$state,20);
    foreach ($res as $place) {
        $addr     = isset($place["address"])  ? $place["address"]  : "";
        $placeCat = isset($place["category"]) ? $place["category"] : "";
        if (!empty($city) && !isInCity($addr,$city)) { $outOfCitySkip++; continue; }
        if (isCategoryBlocked($placeCat))             { $categorySkip++;  continue; }
        $allPlaces[] = $place;
    }
}

// ── Organic search ─────────────────────────────────────────────
$skipDomains = array(
    "justdial","indiamart","tradeindia","sulekha","wikipedia","quora",
    "facebook","instagram","youtube","amazon","flipkart","twitter","linkedin",
    "zomato","swiggy","practo","99acres","magicbricks","olx","snapdeal",
    "naukri","indeed","glassdoor","paytm","phonepe","maps.google",
);
$o1 = doSerperSearch("\"$kw1\" ".$qWords[0]." $city $state", $SERPER_KEY);
$o2 = doSerperSearch("$kw1 $kw2 ".$qWords[0]." $city", $SERPER_KEY);
$allOrganic = array_merge($o1,$o2);

// ══════════════════════════════════════════════════════════════
//  STEP 4: BUILD CANDIDATES
//  4 gates — only clean businesses reach Groq
// ══════════════════════════════════════════════════════════════
$seenKeys      = array();
$candidates    = array();
$chainsSkipped = 0;
$nameSkipped   = 0;

foreach ($allPlaces as $p) {
    $name = trim(isset($p["title"]) ? $p["title"] : "");
    if (!$name) continue;
    if (isChainStore($name))  { $chainsSkipped++; continue; }
    if (isNamePOI($name))     { $nameSkipped++;   continue; }
    $reviews=(int)(isset($p["ratingCount"])?$p["ratingCount"]:0);
    if ($reviews>5000)        { $chainsSkipped++; continue; }

    $key=strtolower(preg_replace('/[^a-z0-9]/i','',$name));
    if (isset($seenKeys[$key])) continue;
    similar_text($key,$ownKey,$pct);
    if ($pct>75) continue;
    $seenKeys[$key]=true;

    $address  = isset($p["address"])     ? $p["address"]     : $city;
    $phone    = isset($p["phoneNumber"]) ? $p["phoneNumber"] : "";
    $mapCat   = isset($p["category"])    ? $p["category"]    : "";
    $relScore = productRelevanceScore($name,$address." ".$mapCat,$productKeywords);

    $candidates[] = array(
        "name"        => $name,
        "address"     => $address,
        "phone"       => $phone,
        "website"     => isset($p["website"])?$p["website"]:"",
        "rating"      => isset($p["rating"]) ?$p["rating"] :null,
        "reviews"     => $reviews,
        "source"      => "Google Maps",
        "map_category"=> $mapCat,
        "relScore"    => $relScore,
    );
}

foreach ($allOrganic as $r) {
    $name=trim(isset($r["title"])  ?$r["title"]  :"");
    $url =trim(isset($r["link"])   ?$r["link"]   :"");
    $snip=trim(isset($r["snippet"])?$r["snippet"]:"");
    if (!$name||!$url) continue;
    if (isChainStore($name)) { $chainsSkipped++; continue; }
    if (isNamePOI($name))    { $nameSkipped++;   continue; }
    $skip=false;
    foreach ($skipDomains as $sk) if (stripos($url,$sk)!==false){$skip=true;break;}
    if ($skip) continue;
    if (!empty($city)&&!isInCity($snip." ".$name,$city)){$outOfCitySkip++;continue;}
    $key=strtolower(preg_replace('/[^a-z0-9]/i','',$name));
    if (isset($seenKeys[$key])) continue;
    similar_text($key,$ownKey,$pct);
    if ($pct>75) continue;
    $seenKeys[$key]=true;
    $relScore=productRelevanceScore($name,$snip,$productKeywords);
    $candidates[]=array(
        "name"=>$name,"address"=>$snip,"phone"=>"",
        "website"=>$url,"rating"=>null,"reviews"=>0,
        "source"=>"Google Search","map_category"=>"","relScore"=>$relScore,
    );
}

// ══════════════════════════════════════════════════════════════
//  STEP 5: TWO-STAGE GROQ FILTER
//
//  Stage A — PRODUCT MATCH (strict)
//    Does this business PRIMARILY deal in the product?
//    Not just "might carry it" — PRIMARILY sells it.
//
//  Stage B — BUSINESS TYPE MATCH (strict)
//    Is it the same business type (wholesale/retail/service/mfg)?
//
//  Both stages must pass. A "Car Accessories" shop fails Stage A
//  for a GPS wholesaler because GPS is not its primary product.
//  "Pooja Electronics" fails Stage B because it is retail, not wholesale.
// ══════════════════════════════════════════════════════════════
$groqFilteredNames = array();
$groqFilterError   = "";
$groqApprovedCount = 0;

if (!empty($candidates)) {
    $productList = implode(", ", $productArr);

    $nameLines = array();
    foreach ($candidates as $ci => $c) {
        $catNote  = $c["map_category"] ? " [Category: ".$c["map_category"]."]" : "";
        $nameLines[] = ($ci+1).". ".$c["name"].$catNote." | ".substr($c["address"],0,70);
    }
    $nameList = implode("\n",$nameLines);

    $filterPrompt = "You are a strict business competitor classifier for an Indian B2B analytics tool.

SELLER PROFILE:
- Business type: $typeLabel
- Primary products: \"$productList\"
- City: $city, $state, India

A COMPETITOR must pass BOTH tests:

TEST 1 — PRIMARY PRODUCT MATCH:
The business must PRIMARILY deal in \"$productList\".
FAIL this test if:
- The business is a general electronics/accessories shop that may carry the product among hundreds of other products
- The business is in a different industry (auto parts, batteries, car accessories) even if they sometimes stock the product
- GPS tracker wholesaler example: PASS = \"Singhal GPS Tracker\", \"Vehicle Tracking Systems India\" | FAIL = \"Car Accessories Shop\", \"Pooja Electronics\", \"Care Well Battery\", \"Auto Power Corporation\"

TEST 2 — BUSINESS TYPE MATCH:
The business must be the SAME business type as \"$typeLabel\".
FAIL this test if:
- Seller is WHOLESALE → reject retail shops, repair centers
- Seller is RETAIL → reject wholesalers, factories
- Seller is MANUFACTURER → reject retail shops, traders
- Seller is SERVICE → reject product-only shops
- \"Pooja Electronics\" (retail shop) FAILS for a GPS wholesaler

BOTH tests must pass. Fail either one = EXCLUDE.

Examples for GPS Tracker Wholesaler in Vadodara:
INCLUDE: \"Singhal GPS Tracker\" (GPS specialist + maps to distributor type)
INCLUDE: \"Vehicle Tracking Solution Supplier\" (GPS + wholesale type)
EXCLUDE: \"Car Accessories\" (not primarily GPS)
EXCLUDE: \"Pooja Electronics\" (general electronics retail, not GPS wholesale)
EXCLUDE: \"Care Well Battery\" (batteries, not GPS)
EXCLUDE: \"Auto Power Corporation\" (auto parts, not GPS)
EXCLUDE: \"Hathikhana Wholesale Grain Market\" (grain, not GPS)

Business list:
$nameList

Return ONLY: {\"include\": [2, 5, 8]}
Return empty if none qualify: {\"include\": []}
No explanation. JSON only.";

    $fr = doGroq($filterPrompt,$GROQ_KEY,500,0.0);

    if ($fr["err"]) {
        $groqFilterError = "Curl: ".$fr["err"];
    } elseif ((int)$fr["http"]!==200) {
        $groqFilterError = "HTTP ".$fr["http"].": ".substr($fr["raw"],0,200);
    } else {
        $gd      = json_decode($fr["raw"],true);
        $content = isset($gd["choices"][0]["message"]["content"])
                   ? $gd["choices"][0]["message"]["content"] : "";
        if ($content) {
            $parsed=$nums=array();
            $parsed=extractJson($content);
            if ($parsed) {
                if (isset($parsed["include"])&&is_array($parsed["include"]))
                    $nums=$parsed["include"];
                elseif (isset($parsed[0])&&is_numeric($parsed[0]))
                    $nums=$parsed;
                else foreach ($parsed as $v) if (is_array($v)){$nums=$v;break;}
            }
            foreach ($nums as $num) {
                $idx=(int)$num-1;
                if (isset($candidates[$idx])) {
                    $groqFilteredNames[]=strtolower(
                        preg_replace('/[^a-z0-9]/i','',$candidates[$idx]["name"])
                    );
                }
            }
            $groqApprovedCount=count($groqFilteredNames);
        } else {
            $groqFilterError="Empty Groq response";
        }
    }
}

// ══════════════════════════════════════════════════════════════
//  STEP 6: APPLY FILTER + FALLBACK
//  Groq succeeded → use Groq list (strict two-test filter)
//  Groq API failed → fallback to relScore >= 8
//  (score 8 = product keyword found in business NAME, which is
//   a strong signal the business primarily deals in the product)
// ══════════════════════════════════════════════════════════════
$competitors  = array();
$groqFiltered = 0;
$groqRanOk    = empty($groqFilterError) && !empty($candidates);

foreach ($candidates as $c) {
    $key      = strtolower(preg_replace('/[^a-z0-9]/i','',$c["name"]));
    $relScore = (int)$c["relScore"];

    if ($groqRanOk) {
        if (in_array($key,$groqFilteredNames)) {
            unset($c["relScore"],$c["map_category"]);
            $competitors[]=$c;
        } else {
            $groqFiltered++;
        }
    } else {
        // Groq failed: only include if product keyword is IN the business name
        // (relScore >= 8 means at least one full product keyword matched the name)
        if ($relScore>=8) {
            unset($c["relScore"],$c["map_category"]);
            $competitors[]=$c;
        } else {
            $groqFiltered++;
        }
    }
}

// ── Score + rank ───────────────────────────────────────────────
foreach ($competitors as &$c) {
    $c["score"] = ((int)$c["reviews"]*2)
                + ((float)($c["rating"]?:0)*10)
                + (!empty($c["website"])?5:0)
                + (!empty($c["phone"])  ?3:0);
}
unset($c);

usort($competitors,function($a,$b){return $b["score"]-$a["score"];});

$topCompetitor = isset($competitors[0])?$competitors[0]:null;
$competitors   = array_slice($competitors,0,25);
$compCount     = count($competitors);

// ── Strategy text ──────────────────────────────────────────────
$compListStr = empty($competitors)
    ? "No verified direct competitors found in $city for $rawProducts ($typeLabel)."
    : implode("\n",array_map(function($c){
        return "- ".$c["name"]
            ." | ".($c["rating"]?"⭐".$c["rating"]:"No rating")
            ." (".$c["reviews"]." reviews)"
            ." | ".substr($c["address"],0,55);
    },$competitors));

$topStr = $topCompetitor
    ? $topCompetitor["name"]." (⭐".($topCompetitor["rating"]?:"N/A").", ".$topCompetitor["reviews"]." reviews)"
    : "None found";

// ══════════════════════════════════════════════════════════════
//  STEP 7: GROQ STRATEGY
// ══════════════════════════════════════════════════════════════
$stratPrompt = "You are a senior Indian SMB growth strategist.

SELLER:
- Name: {$seller["name"]}
- Type: $typeLabel
- Products: $rawProducts
- City: $city, $state
- Employees: ".($seller["employees"]?:"Unknown")."
- Turnover: ".($seller["annual_turnover"]?:"Unknown")."
- Website: ".($seller["website"]?:"None")."
- GST: ".($seller["gst_number"]?"Yes":"No")."

VERIFIED DIRECT COMPETITORS ($compCount in $city — same products, same business type):
Top: $topStr
All:
$compListStr

Write practical growth strategy for a \"$typeLabel\" selling \"$rawProducts\" in $city.
Be specific to business type — wholesalers: B2B focus, bulk pricing, distributor network.

Return ONLY valid JSON:
{
  \"top_competitor\": {
    \"name\": \"\",
    \"why_winning\": \"\",
    \"their_strengths\": [\"\",\"\",\"\"],
    \"how_to_beat_them\": [\"\",\"\",\"\",\"\"]
  },
  \"bottlenecks\": [\"\",\"\",\"\",\"\"],
  \"keyword_targets\": [\"\",\"\",\"\",\"\",\"\",\"\",\"\",\"\"],
  \"seo_fixes\": [\"\",\"\",\"\",\"\",\"\"],
  \"content_gaps\": [\"\",\"\",\"\"],
  \"local_listings\": [\"\",\"\",\"\",\"\",\"\"],
  \"weekly_actions\": [\"\",\"\",\"\",\"\",\"\"],
  \"ad_strategy\": [\"\",\"\",\"\"],
  \"offline_strategy\": [\"\",\"\",\"\"],
  \"gst_advantage\": \"\",
  \"revenue_score\": \"\"
}";

$strategy  = array();
$groqError = "";
$gr = doGroq($stratPrompt,$GROQ_KEY,2000,0.3);

if ($gr["err"]) {
    $groqError="Curl: ".$gr["err"];
} elseif ((int)$gr["http"]!==200) {
    $groqError="HTTP ".$gr["http"].": ".substr($gr["raw"],0,300);
} else {
    $gd=json_decode($gr["raw"],true);
    $content=isset($gd["choices"][0]["message"]["content"])?$gd["choices"][0]["message"]["content"]:"";
    if ($content) {
        $parsed=extractJson($content);
        if ($parsed&&is_array($parsed)) $strategy=$parsed;
        else $groqError="Parse fail: ".substr($content,0,200);
    } else {
        $groqError="Empty strategy response";
    }
}

// ── Save ───────────────────────────────────────────────────────
if (!empty($strategy)) {
    try {
        db()->prepare("INSERT INTO seo_reports (seller_id, report_json) VALUES (?,?)")
           ->execute(array($sid,json_encode($strategy)));
    } catch (Exception $e) {}
}

// ── Discord ────────────────────────────────────────────────────
try {
    $wh=cfg("DISCORD_WEBHOOK")?:cfg("discord_webhook")?:"";
    if ($wh&&strpos($wh,"YOUR_WEBHOOK")===false) {
        $embed=array(
            "title"      =>"BizBoost: ".$seller["name"],
            "description"=>"$typeLabel · $city · $compCount direct competitors",
            "color"      =>3447003,
            "fields"     =>array(
                array("name"=>"Products",      "value"=>$rawProducts,      "inline"=>true),
                array("name"=>"Type",          "value"=>$typeLabel,         "inline"=>true),
                array("name"=>"Final Results", "value"=>"$compCount",       "inline"=>true),
                array("name"=>"Candidates",    "value"=>count($candidates), "inline"=>true),
                array("name"=>"Cat Blocked",   "value"=>"$categorySkip",    "inline"=>true),
                array("name"=>"Name Blocked",  "value"=>"$nameSkipped",     "inline"=>true),
                array("name"=>"Chains",        "value"=>"$chainsSkipped",   "inline"=>true),
                array("name"=>"Out-of-City",   "value"=>"$outOfCitySkip",   "inline"=>true),
                array("name"=>"AI Filtered",   "value"=>"$groqFiltered",    "inline"=>true),
            )
        );
        $ch=curl_init($wh);
        curl_setopt_array($ch,array(
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
            CURLOPT_TIMEOUT=>5,CURLOPT_SSL_VERIFYPEER=>false,
            CURLOPT_HTTPHEADER=>array("Content-Type: application/json"),
            CURLOPT_POSTFIELDS=>json_encode(array("embeds"=>array($embed)))
        ));
        curl_exec($ch);curl_close($ch);
    }
} catch (Exception $e) {}

// ── Final respond ──────────────────────────────────────────────
respond(array(
    "success"        => true,
    "strategy"       => $strategy,
    "competitors"    => $competitors,
    "top_competitor" => $topCompetitor,
    "counts"         => array(
        "competitors"     => $compCount,
        "keywords"        => count(isset($strategy["keyword_targets"])?$strategy["keyword_targets"]:array()),
        "seo_fixes"       => count(isset($strategy["seo_fixes"])      ?$strategy["seo_fixes"]      :array()),
        "chains_skipped"  => $chainsSkipped,
        "category_blocked"=> $categorySkip,
        "name_blocked"    => $nameSkipped,
        "ai_filtered"     => $groqFiltered,
        "out_of_city"     => $outOfCitySkip,
    ),
    "debug" => array(
        "php_version"         => PHP_VERSION,
        "detected_type"       => $detectedType,
        "type_label"          => $typeLabel,
        "queries_fired"       => count($debugQueries),
        "queries_list"        => $debugQueries,
        "products_parsed"     => $productArr,
        "candidates_total"    => count($candidates),
        "category_blocked"    => $categorySkip,
        "name_blocked"        => $nameSkipped,
        "chains_skipped"      => $chainsSkipped,
        "out_of_city_skipped" => $outOfCitySkip,
        "groq_approved"       => $groqApprovedCount,
        "ai_filtered_out"     => $groqFiltered,
        "final_competitors"   => $compCount,
        "groq_filter_error"   => $groqFilterError,
        "groq_strat_error"    => $groqError,
    ),
));
?>
