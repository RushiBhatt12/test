<?php
ob_start();
ini_set("display_errors", 0);
error_reporting(0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if (isset($_SERVER["REQUEST_METHOD"]) && $_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    ob_end_clean();
    http_response_code(200);
    exit;
}

// ── InfinityFree Database Credentials ─────────────────────────
define("DB_HOST", "sql311.infinityfree.com"); 
define("DB_PORT", "3306");
define("DB_NAME", "if0_41214152_stest");
define("DB_USER", "if0_41214152");
define("DB_PASS", "LGjkW1eFXy6");

// ── Free API Keys ──────────────────────────────────────────────
define("SERPER_KEY",       "b51155a8139fc218728144065b36731f9fefa113");
define("GROQ_KEY",         "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");
define("DISCORD_WEBHOOK",  "https://discord.com/api/webhooks/1474912431808713019/fEM1v3Lq6zMED-VmK5eC1CUcXar0BZOk4W7IWjKZJFO2b3kHu_6ok4foNrfZQRLlVrfh");

// ── Discord Webhook Notifier ───────────────────────────────────
// Colors: 3066993 green | 5814783 blue | 15844367 yellow | 15105570 orange | 15158332 red
function notifyDiscord($message, $username = "BizBot", $color = 3066993) {
    $payload = json_encode([
        "username" => $username,
        "embeds"   => [[
            "description" => $message,
            "color"       => $color,
            "timestamp"   => date("c"),
        ]]
    ]);

    $ch = curl_init(DISCORD_WEBHOOK);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ── Clean JSON output ──────────────────────────────────────────
function sendJSON($data) {
    ob_end_clean();
    header("Content-Type: application/json");
    echo json_encode($data);
    exit;
}

// ── DB Connection ──────────────────────────────────────────────
function db() {
    static $pdo = null;
    if (!$pdo) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => true,
                ]
            );
        } catch (PDOException $e) {
            notifyDiscord("❌ **DB Connection Failed**\n```" . $e->getMessage() . "```", "BizBot", 15158332);
            sendJSON(["success" => false, "error" => "DB failed: " . $e->getMessage()]);
        }
    }
    return $pdo;
}

// ── Tables WITHOUT foreign keys (InfinityFree compatible) ──────
function setupDB() {
    try {
        $pdo = db();

        $pdo->exec("CREATE TABLE IF NOT EXISTS sellers (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            name       VARCHAR(255) NOT NULL,
            category   VARCHAR(255) NOT NULL,
            city       VARCHAR(255) NOT NULL,
            state      VARCHAR(255) NOT NULL,
            website    VARCHAR(500) DEFAULT NULL,
            contact    VARCHAR(50)  NOT NULL,
            email      VARCHAR(255) NOT NULL UNIQUE,
            password   VARCHAR(255) NOT NULL,
            created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS buyers (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            name        VARCHAR(255) NOT NULL,
            requirement TEXT,
            city        VARCHAR(255) NOT NULL,
            state       VARCHAR(255) NOT NULL,
            budget_min  FLOAT        DEFAULT 0,
            budget_max  FLOAT        DEFAULT 0,
            contact     VARCHAR(50)  NOT NULL,
            email       VARCHAR(255) NOT NULL UNIQUE,
            password    VARCHAR(255) NOT NULL,
            created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS seo_reports (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            seller_id    INT       NOT NULL,
            report_json  LONGTEXT  NOT NULL,
            generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS competitor_data (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            seller_id      INT          NOT NULL,
            competitor_url VARCHAR(500),
            meta_title     TEXT,
            meta_desc      TEXT,
            keywords       TEXT,
            h1_tags        TEXT,
            scraped_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS external_listings (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            platform      VARCHAR(50)  NOT NULL,
            category      VARCHAR(255),
            city          VARCHAR(255),
            business_name VARCHAR(255),
            contact       VARCHAR(100),
            url           VARCHAR(500),
            cached_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS connections (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            buyer_id     INT         NOT NULL,
            seller_id    INT         NOT NULL,
            message      TEXT,
            status       VARCHAR(50) DEFAULT 'pending',
            initiated_at TIMESTAMP   DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4");

        // ── leads defined once (duplicate removed) ─────────────
        $pdo->exec("CREATE TABLE IF NOT EXISTS leads (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            buyer_id    INT          NOT NULL,
            buyer_name  VARCHAR(255),
            buyer_phone VARCHAR(50),
            product     VARCHAR(255) NOT NULL,
            city        VARCHAR(255) NOT NULL,
            state       VARCHAR(255),
            quantity    VARCHAR(100),
            unit        VARCHAR(50),
            budget_min  FLOAT        DEFAULT 0,
            budget_max  FLOAT        DEFAULT 0,
            status      VARCHAR(50)  DEFAULT 'active',
            created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS seller_details (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            seller_id        INT          NOT NULL UNIQUE,
            gst_number       VARCHAR(20)  DEFAULT NULL,
            business_type    VARCHAR(100) DEFAULT NULL,
            year_established VARCHAR(10)  DEFAULT NULL,
            employees        VARCHAR(50)  DEFAULT NULL,
            annual_turnover  VARCHAR(100) DEFAULT NULL,
            products_offered TEXT,
            business_desc    TEXT,
            address          TEXT,
            pincode          VARCHAR(10)  DEFAULT NULL,
            certifications   VARCHAR(255) DEFAULT NULL,
            facebook_url     VARCHAR(255) DEFAULT NULL,
            instagram_url    VARCHAR(255) DEFAULT NULL,
            whatsapp         VARCHAR(20)  DEFAULT NULL,
            working_hours    VARCHAR(100) DEFAULT NULL,
            delivery_radius  VARCHAR(100) DEFAULT NULL,
            updated_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4");

    } catch (PDOException $e) {
        notifyDiscord("❌ **Table Setup Failed**\n```" . $e->getMessage() . "```", "BizBot", 15158332);
        sendJSON(["success" => false, "error" => "Table setup failed: " . $e->getMessage()]);
    }
}

setupDB();

// ── Example: call notifyDiscord() after key events in your app ─
//
// New seller registered:
//   notifyDiscord("🏪 **New Seller**\n**Name:** $name\n**Category:** $category\n**Location:** $city, $state");
//
// New buyer registered:
//   notifyDiscord("🛒 **New Buyer**\n**Name:** $name\n**Location:** $city, $state\n**Budget:** ₹$budget_min–₹$budget_max", "BizBot", 5814783);
//
// New lead posted:
//   notifyDiscord("📋 **New Lead**\n**Product:** $product\n**Qty:** $quantity $unit\n**Location:** $city, $state\n**Budget:** ₹$budget_min–₹$budget_max", "BizBot", 15844367);
//
// New connection request:
//   notifyDiscord("🔗 **New Connection**\nBuyer #$buyer_id → Seller #$seller_id\n**Message:** $message", "BizBot", 15105570);
?>