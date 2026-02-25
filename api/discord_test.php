<?php
// webhook_check.php — DELETE after testing!
require "config.php";

echo "<pre>";
echo "=== Discord Webhook Check ===\n\n";

// 1. Constant defined?
echo "1. DISCORD_WEBHOOK defined: " . (defined("DISCORD_WEBHOOK") ? "YES" : "NO ❌") . "\n";

if (!defined("DISCORD_WEBHOOK") || !DISCORD_WEBHOOK) {
    echo "❌ Webhook not defined in config.php\n</pre>"; exit;
}

$url = DISCORD_WEBHOOK;
echo "2. URL: " . substr($url, 0, 60) . "...\n";

// 2. Basic format check
$valid = preg_match('#^https://discord\.com/api/webhooks/\d+/[\w-]+$#', $url);
echo "3. URL format valid: " . ($valid ? "YES ✅" : "NO ❌ — URL looks malformed") . "\n\n";

// 3. DNS resolution
echo "--- DNS Check ---\n";
$ip = gethostbyname("discord.com");
$dnsOk = ($ip !== "discord.com");
echo "discord.com resolves to: " . ($dnsOk ? "$ip ✅" : "FAILED ❌ (outbound blocked)") . "\n\n";

if (!$dnsOk) {
    echo "❌ Your host cannot reach discord.com. Outbound connections are blocked.\n</pre>"; exit;
}

// 4. cURL to webhook (GET to check if webhook exists — returns 200 + info or 404 if deleted)
echo "--- Webhook Validity Check (GET) ---\n";
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPGET        => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);
$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

echo "HTTP Status: $code\n";
if ($err) echo "cURL Error: $err\n";

if ($code === 200) {
    $data = json_decode($res, true);
    echo "Webhook name: " . ($data["name"] ?? "unknown") . " ✅\n";
    echo "Channel ID:   " . ($data["channel_id"] ?? "unknown") . "\n";
    echo "Guild ID:     " . ($data["guild_id"] ?? "unknown") . "\n";
    echo "\n✅ Webhook EXISTS and is valid.\n";
} elseif ($code === 404) {
    $data = json_decode($res, true);
    echo "\n❌ Webhook NOT FOUND (404) — Discord deleted it (likely due to leaked URL).\n";
    echo "→ Go to Discord → Channel Settings → Integrations → Webhooks → Create New.\n";
} elseif ($code === 401) {
    echo "\n❌ Unauthorized (401) — URL is malformed or partially copied.\n";
} elseif ($code === 0) {
    echo "\n❌ No response — host is blocking outbound HTTPS.\n";
} else {
    echo "\nUnexpected status: $code\n";
    echo "Response: " . substr($res, 0, 300) . "\n";
}

// 5. Send a real test message only if webhook is valid
if ($code === 200) {
    echo "\n--- Sending Test Message ---\n";
    $payload = json_encode([
        "embeds" => [[
            "title"       => "✅ Webhook Test",
            "description" => "Your BizBoost webhook is working correctly!",
            "color"       => 0x34C759,
            "footer"      => ["text" => "BizBoost · " . date("d M Y, h:i A")],
        ]]
    ]);
    $ch2 = curl_init($url);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res2  = curl_exec($ch2);
    $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    $err2  = curl_error($ch2);
    curl_close($ch2);

    echo "POST status: $code2\n";
    if ($err2) echo "cURL error: $err2\n";

    if ($code2 === 204) {
        echo "✅ Message sent! Check your Discord channel.\n";
    } else {
        echo "❌ Send failed. Response: " . substr($res2, 0, 300) . "\n";
    }
}

echo "\n⚠️  Remember to DELETE this file from your server!\n";
echo "</pre>";
?>