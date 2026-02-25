<?php
require "config.php";

function curlGet($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => "Mozilla/5.0 (compatible; BizBoostBot/1.0)",
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    return $html ?: "";
}

function scrapeURL($url) {
    $html = curlGet($url);
    if (!$html) return [];

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    // Meta title
    $title = $dom->getElementsByTagName("title")->item(0)?->textContent ?? "";

    // Meta description
    $metaDesc = "";
    foreach ($dom->getElementsByTagName("meta") as $tag) {
        if (strtolower($tag->getAttribute("name")) === "description")
            $metaDesc = $tag->getAttribute("content");
        if (strtolower($tag->getAttribute("name")) === "keywords")
            $keywords = $tag->getAttribute("content");
    }

    // H1 tags
    $h1s = [];
    foreach ($dom->getElementsByTagName("h1") as $h) $h1s[] = trim($h->textContent);

    // H2 tags
    $h2s = [];
    foreach ($dom->getElementsByTagName("h2") as $h) $h2s[] = trim($h->textContent);

    // Schema markup check
    $hasSchema = strpos($html, 'application/ld+json') !== false;

    // Canonical
    $canonical = "";
    foreach ($dom->getElementsByTagName("link") as $link) {
        if ($link->getAttribute("rel") === "canonical")
            $canonical = $link->getAttribute("href");
    }

    return [
        "meta_title"  => $title,
        "meta_desc"   => $metaDesc,
        "keywords"    => $keywords ?? "",
        "h1_tags"     => implode(" | ", array_slice($h1s, 0, 5)),
        "h2_tags"     => implode(" | ", array_slice($h2s, 0, 8)),
        "has_schema"  => $hasSchema,
        "canonical"   => $canonical,
    ];
}

// API endpoint
$data = json_decode(file_get_contents("php://input"), true);
$url  = $data["url"] ?? "";
if ($url) {
    echo json_encode(scrapeURL($url));
} else {
    echo json_encode(["error" => "No URL provided"]);
}
?>
