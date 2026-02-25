<?php
function sendDiscord($title, $color, $fields) {
    if (!defined("DISCORD_WEBHOOK") || !DISCORD_WEBHOOK ||
        strpos(DISCORD_WEBHOOK, "YOUR_WEBHOOK") !== false) return;

    $embed = [
        "title"     => $title,
        "color"     => $color,
        "timestamp" => date("c"),
        "fields"    => array_map(function($f) {
            $value = (string)($f[1] ?? "—");
            if ($value === "") $value = "—";
            return [
                "name"   => (string)($f[0] ?? "Field"),
                "value"  => substr($value, 0, 1020),
                "inline" => isset($f[2]) ? (bool)$f[2] : true,
            ];
        }, $fields),
        "footer" => ["text" => "BizBoost · " . date("d M Y, h:i A")]
    ];

    $ch = curl_init(DISCORD_WEBHOOK);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(["embeds" => [$embed]]),
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 204 && $httpCode !== 200) {
        error_log("Discord webhook failed [$httpCode] cURL: $curlErr | Response: " . substr($response, 0, 200));
    }
}
?>