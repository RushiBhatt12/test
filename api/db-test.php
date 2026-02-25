<?php
ob_start();
ini_set("display_errors", 1);
error_reporting(E_ALL);

// ── Config (inline so this file is self-contained) ─────────────
define("DB_HOST", "sql311.infinityfree.com");
define("DB_PORT", "3306");
define("DB_NAME", "if0_41214152_stest");
define("DB_USER", "if0_41214152");
define("DB_PASS", "LGjkW1eFXy6");

$results = [];
$pass = 0;
$fail = 0;

function ok($label, $detail = "") {
    global $results, $pass;
    $results[] = ["status" => "pass", "label" => $label, "detail" => $detail];
    $pass++;
}
function fail($label, $detail = "") {
    global $results, $fail;
    $results[] = ["status" => "fail", "label" => $label, "detail" => $detail];
    $fail++;
}
function info($label, $detail = "") {
    global $results;
    $results[] = ["status" => "info", "label" => $label, "detail" => $detail];
}

// ── 1. DB Connection ───────────────────────────────────────────
$pdo = null;
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    ok("DB Connection", "Connected to " . DB_HOST . " → " . DB_NAME);
} catch (PDOException $e) {
    fail("DB Connection", $e->getMessage());
}

if ($pdo) {

    // ── 2. Server Info ─────────────────────────────────────────
    try {
        $ver = $pdo->query("SELECT VERSION() AS v")->fetch()["v"];
        info("Server Version", $ver);
    } catch (Throwable $e) { fail("Server Version", $e->getMessage()); }

    // ── 3. Check all expected tables exist ────────────────────
    $expected = ["sellers", "seller_details", "buyers", "leads", "connections", "competitor_data", "external_listings", "seo_reports"];
    try {
        $existing = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($expected as $t) {
            if (in_array($t, $existing))
                ok("Table exists: $t");
            else
                fail("Table exists: $t", "Table not found in database");
        }
    } catch (Throwable $e) { fail("Table Check", $e->getMessage()); }

    // ── 4. Check sellers columns ───────────────────────────────
    try {
        $cols = $pdo->query("DESCRIBE sellers")->fetchAll(PDO::FETCH_COLUMN);
        $need = ["id","name","category","city","state","website","contact","email","password","created_at"];
        foreach ($need as $c) {
            if (in_array($c, $cols)) ok("sellers.{$c} column exists");
            else fail("sellers.{$c} column missing");
        }
    } catch (Throwable $e) { fail("Describe sellers", $e->getMessage()); }

    // ── 5. Check seller_details columns ───────────────────────
    try {
        $cols = $pdo->query("DESCRIBE seller_details")->fetchAll(PDO::FETCH_COLUMN);
        $need = ["id","seller_id","gst_number","business_type","year_established","employees",
                 "annual_turnover","products_offered","business_desc","address","pincode",
                 "certifications","facebook_url","instagram_url","whatsapp","working_hours","delivery_radius","updated_at"];
        foreach ($need as $c) {
            if (in_array($c, $cols)) ok("seller_details.{$c} column exists");
            else fail("seller_details.{$c} column missing");
        }
    } catch (Throwable $e) { fail("Describe seller_details", $e->getMessage()); }

    // ── 6. Row counts ──────────────────────────────────────────
    foreach (["sellers","seller_details","buyers","leads"] as $t) {
        try {
            $count = $pdo->query("SELECT COUNT(*) AS c FROM `$t`")->fetch()["c"];
            info("Rows in $t", "$count row(s)");
        } catch (Throwable $e) { fail("Count $t", $e->getMessage()); }
    }

    // ── 7. INSERT test seller ──────────────────────────────────
    $testEmail = "db_test_" . time() . "@bizboost-test.com";
    $testId = null;
    try {
        $stmt = $pdo->prepare("INSERT INTO sellers (name, category, city, state, website, contact, email, password)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute(["TEST Business", "Electronics", "Vadodara", "Gujarat", "", "9999999999", $testEmail,
                        password_hash("TestPass@123", PASSWORD_DEFAULT)]);
        $testId = (int)$pdo->lastInsertId();
        ok("INSERT seller", "Inserted test seller with id=$testId");
    } catch (Throwable $e) { fail("INSERT seller", $e->getMessage()); }

    // ── 8. SELECT test seller back ─────────────────────────────
    if ($testId) {
        try {
            $row = $pdo->prepare("SELECT * FROM sellers WHERE id = ?");
            $row->execute([$testId]);
            $s = $row->fetch();
            if ($s && $s["email"] === $testEmail)
                ok("SELECT seller", "name={$s['name']}, city={$s['city']}, category={$s['category']}");
            else
                fail("SELECT seller", "Row not found or email mismatch");
        } catch (Throwable $e) { fail("SELECT seller", $e->getMessage()); }

        // ── 9. INSERT seller_details ───────────────────────────
        try {
            $stmt = $pdo->prepare("INSERT INTO seller_details
                (seller_id, gst_number, business_type, year_established, employees,
                 annual_turnover, products_offered, business_desc, address, pincode,
                 certifications, facebook_url, instagram_url, whatsapp, working_hours, delivery_radius)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$testId,"27AABCU9603R1ZX","Retailer","2020","10",
                            "50 Lakhs","Phones, Laptops","Test description","123 Main St","390001",
                            "ISO 9001","","","9999999999","9am-6pm","10 km"]);
            ok("INSERT seller_details", "Details saved for seller_id=$testId");
        } catch (Throwable $e) { fail("INSERT seller_details", $e->getMessage()); }

        // ── 10. JOIN sellers + seller_details ──────────────────
        try {
            $stmt = $pdo->prepare("SELECT s.name, s.city, d.products_offered, d.gst_number
                FROM sellers s LEFT JOIN seller_details d ON d.seller_id = s.id
                WHERE s.id = ?");
            $stmt->execute([$testId]);
            $row = $stmt->fetch();
            if ($row && $row["gst_number"] === "27AABCU9603R1ZX")
                ok("JOIN sellers+seller_details", "products_offered={$row['products_offered']}");
            else
                fail("JOIN sellers+seller_details", "Join returned unexpected data: " . json_encode($row));
        } catch (Throwable $e) { fail("JOIN query", $e->getMessage()); }

        // ── 11. UPDATE sellers ─────────────────────────────────
        try {
            $stmt = $pdo->prepare("UPDATE sellers SET city = ? WHERE id = ?");
            $stmt->execute(["Surat", $testId]);
            $check = $pdo->prepare("SELECT city FROM sellers WHERE id = ?");
            $check->execute([$testId]);
            $city = $check->fetch()["city"];
            if ($city === "Surat") ok("UPDATE seller", "City updated to Surat");
            else fail("UPDATE seller", "City was not updated, got: $city");
        } catch (Throwable $e) { fail("UPDATE seller", $e->getMessage()); }

        // ── 12. DUPLICATE email test (should fail) ─────────────
        try {
            $stmt = $pdo->prepare("INSERT INTO sellers (name,category,city,state,contact,email,password) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute(["Dupe","Electronics","Vadodara","Gujarat","0000000000",$testEmail,"hash"]);
            fail("Duplicate email rejected", "Should have thrown an error but didn't — UNIQUE constraint not working!");
        } catch (Throwable $e) {
            ok("Duplicate email rejected", "UNIQUE constraint working correctly");
        }

        // ── 13. CLEANUP — delete test rows ────────────────────
        try {
            $pdo->prepare("DELETE FROM seller_details WHERE seller_id = ?")->execute([$testId]);
            $pdo->prepare("DELETE FROM sellers WHERE id = ?")->execute([$testId]);
            $check = $pdo->prepare("SELECT id FROM sellers WHERE id = ?");
            $check->execute([$testId]);
            if (!$check->fetch()) ok("CLEANUP", "Test rows deleted successfully");
            else fail("CLEANUP", "Test row still exists after DELETE");
        } catch (Throwable $e) { fail("CLEANUP", $e->getMessage()); }
    }

    // ── 14. password_hash verify ───────────────────────────────
    $hash = password_hash("TestPass@123", PASSWORD_DEFAULT);
    if (password_verify("TestPass@123", $hash))
        ok("password_hash / verify", "PHP password hashing works correctly");
    else
        fail("password_hash / verify", "password_verify returned false — PHP version issue?");

    // ── 15. JSON encode/decode roundtrip ──────────────────────
    $obj = ["seller_id" => 99, "name" => "Test", "city" => "Vadodara"];
    $encoded = json_encode($obj);
    $decoded = json_decode($encoded, true);
    if ($decoded["seller_id"] === 99 && $decoded["city"] === "Vadodara")
        ok("JSON encode/decode", $encoded);
    else
        fail("JSON encode/decode", "Roundtrip failed");
}

// ── OUTPUT ─────────────────────────────────────────────────────
ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BizBoost · DB Test</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&family=Syne:wght@700;800&display=swap');
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg: #0a0a0f;
    --card: #111118;
    --border: rgba(255,255,255,0.07);
    --text: #e8e8f0;
    --muted: #6b6b80;
    --pass: #00e676;
    --fail: #ff5252;
    --info: #448aff;
    --pass-bg: rgba(0,230,118,0.07);
    --fail-bg: rgba(255,82,82,0.07);
    --info-bg: rgba(68,138,255,0.07);
  }
  body {
    font-family: 'JetBrains Mono', monospace;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    padding: 40px 20px 60px;
    background-image:
      radial-gradient(ellipse 60% 40% at 50% 0%, rgba(68,138,255,0.08), transparent 60%);
  }
  .wrap { max-width: 780px; margin: 0 auto; }

  header { margin-bottom: 36px; }
  .logo { font-family: 'Syne', sans-serif; font-size: 28px; font-weight: 800; letter-spacing: -0.04em; }
  .logo em { color: var(--info); font-style: normal; }
  .subtitle { color: var(--muted); font-size: 13px; margin-top: 6px; }

  .scoreboard {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 28px;
  }
  .score-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 20px;
    text-align: center;
  }
  .score-card .num { font-size: 36px; font-weight: 700; line-height: 1; }
  .score-card .lbl { font-size: 11px; color: var(--muted); margin-top: 6px; letter-spacing: 0.08em; text-transform: uppercase; }
  .score-card.pass .num { color: var(--pass); }
  .score-card.fail .num { color: var(--fail); }
  .score-card.total .num { color: var(--text); }

  .result-list { display: flex; flex-direction: column; gap: 6px; }

  .result {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 16px;
    border-radius: 10px;
    border: 1px solid transparent;
    font-size: 13px;
    line-height: 1.5;
  }
  .result.pass { background: var(--pass-bg); border-color: rgba(0,230,118,0.15); }
  .result.fail { background: var(--fail-bg); border-color: rgba(255,82,82,0.2); }
  .result.info { background: var(--info-bg); border-color: rgba(68,138,255,0.15); }

  .dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; margin-top: 4px; }
  .pass .dot { background: var(--pass); box-shadow: 0 0 6px var(--pass); }
  .fail .dot { background: var(--fail); box-shadow: 0 0 6px var(--fail); }
  .info .dot { background: var(--info); box-shadow: 0 0 6px var(--info); }

  .label { font-weight: 600; flex-shrink: 0; min-width: 200px; }
  .pass .label { color: var(--pass); }
  .fail .label { color: var(--fail); }
  .info .label { color: var(--info); }

  .detail { color: var(--muted); word-break: break-all; }

  .section-title {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--muted);
    margin: 24px 0 10px;
    padding-left: 4px;
  }

  .footer { margin-top: 36px; color: var(--muted); font-size: 11px; text-align: center; }
  .footer span { color: var(--info); }
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div class="logo">Biz<em>Boost</em> <span style="font-weight:400;font-size:18px;color:var(--muted)">· DB Test</span></div>
    <div class="subtitle">
      <?= DB_HOST ?> → <?= DB_NAME ?> &nbsp;·&nbsp; <?= date("d M Y, H:i:s") ?>
    </div>
  </header>

  <div class="scoreboard">
    <div class="score-card pass">
      <div class="num"><?= $pass ?></div>
      <div class="lbl">Passed</div>
    </div>
    <div class="score-card fail">
      <div class="num"><?= $fail ?></div>
      <div class="lbl">Failed</div>
    </div>
    <div class="score-card total">
      <div class="num"><?= $pass + $fail ?></div>
      <div class="lbl">Total Checks</div>
    </div>
  </div>

  <div class="result-list">
    <?php
    $sections = [
      "Connection"       => ["DB Connection", "Server Version"],
      "Tables"           => array_map(fn($t) => "Table exists: $t", ["sellers","seller_details","buyers","leads","connections","competitor_data","external_listings","seo_reports"]),
      "Schema"           => [], // all column checks
      "Row Counts"       => array_map(fn($t) => "Rows in $t", ["sellers","seller_details","buyers","leads"]),
      "CRUD Operations"  => ["INSERT seller","SELECT seller","INSERT seller_details","JOIN sellers+seller_details","UPDATE seller","Duplicate email rejected","CLEANUP"],
      "PHP Checks"       => ["password_hash / verify","JSON encode/decode"],
    ];

    // Build a lookup for section titles
    $sectionMap = [];
    foreach ($sections as $title => $labels) {
      foreach ($labels as $l) $sectionMap[$l] = $title;
    }
    $currentSection = null;

    foreach ($results as $r) {
      // Determine section
      $section = $sectionMap[$r['label']] ?? (str_contains($r['label'], 'column') ? "Schema" : null);
      if ($section && $section !== $currentSection) {
        $currentSection = $section;
        echo "<div class='section-title'>$section</div>";
      }
      $cls = htmlspecialchars($r['status']);
      $lbl = htmlspecialchars($r['label']);
      $det = htmlspecialchars($r['detail']);
      echo "<div class='result {$cls}'><div class='dot'></div><div class='label'>{$lbl}</div>" . ($det ? "<div class='detail'>{$det}</div>" : "") . "</div>";
    }
    ?>
  </div>

  <div class="footer">
    <?php if ($fail === 0): ?>
      ✅ All checks passed — database is healthy
    <?php else: ?>
      ⚠️ <span><?= $fail ?> check(s) failed</span> — review the red items above
    <?php endif; ?>
    &nbsp;·&nbsp; Delete this file after testing
  </div>
</div>
</body>
</html>