<?php
header("Content-Type: application/json");
echo json_encode([
    "php"     => "working",
    "version" => phpversion(),
    "curl"    => function_exists("curl_init") ? "enabled" : "DISABLED - this is your problem",
    "pdo"     => extension_loaded("pdo_mysql") ? "enabled" : "DISABLED",
]);
?>
