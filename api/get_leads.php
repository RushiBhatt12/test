<?php
require "config.php";

$sellerId = (int)($_GET["seller_id"] ?? 0);
$category = trim($_GET["category"]   ?? "");
$city     = trim($_GET["city"]       ?? "");

if (!$sellerId) sendJSON(["success" => false, "error" => "seller_id required"]);

try {
    // Safely ensure assignment columns exist
    try { db()->exec("ALTER TABLE leads ADD COLUMN assigned_seller_id INT NULL DEFAULT NULL"); } catch (Throwable $_) {}
    try { db()->exec("ALTER TABLE leads ADD COLUMN assigned_at DATETIME NULL DEFAULT NULL");   } catch (Throwable $_) {}

    // 1. ASSIGNED leads — directly sent to this seller by admin
    $assignedStmt = db()->prepare("
        SELECT *, 'assigned' AS lead_source
        FROM leads
        WHERE assigned_seller_id = ?
          AND status = 'active'
        ORDER BY assigned_at DESC, created_at DESC
        LIMIT 50
    ");
    $assignedStmt->execute([$sellerId]);
    $assignedLeads = $assignedStmt->fetchAll();

    // Collect IDs to exclude from matched set (avoid duplicates)
    $assignedIds = array_column($assignedLeads, 'id');

    // 2. MATCHED leads — category/city overlap
    $matchedLeads = [];
    $excludeClause = $assignedIds
        ? "AND id NOT IN (" . implode(',', array_fill(0, count($assignedIds), '?')) . ")"
        : "";

    if ($category && $city) {
        $catKeyword = "%" . strtok($category, " ") . "%";
        $sql = "SELECT *, 'matched' AS lead_source FROM leads
                WHERE (product LIKE ? OR product LIKE ?)
                  AND (city = ? OR city LIKE ?)
                  AND status = 'active'
                  $excludeClause
                ORDER BY created_at DESC LIMIT 20";
        $params = ["%$category%", $catKeyword, $city, "%$city%"];
        if ($assignedIds) $params = array_merge($params, $assignedIds);
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $matchedLeads = $stmt->fetchAll();

    } elseif ($city) {
        $sql = "SELECT *, 'matched' AS lead_source FROM leads
                WHERE (city = ? OR city LIKE ?)
                  AND status = 'active'
                  $excludeClause
                ORDER BY created_at DESC LIMIT 20";
        $params = [$city, "%$city%"];
        if ($assignedIds) $params = array_merge($params, $assignedIds);
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $matchedLeads = $stmt->fetchAll();

    } else {
        $sql = "SELECT *, 'matched' AS lead_source FROM leads
                WHERE status = 'active'
                  $excludeClause
                ORDER BY created_at DESC LIMIT 20";
        $params = $assignedIds ?: [];
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $matchedLeads = $stmt->fetchAll();
    }

    // Merge: assigned first, then matched
    $allLeads = array_merge($assignedLeads, $matchedLeads);

    // Cast types
    foreach ($allLeads as &$l) {
        $l["id"]          = (int)$l["id"];
        $l["budget_min"]  = (float)($l["budget_min"] ?? 0);
        $l["budget_max"]  = (float)($l["budget_max"] ?? 0);
        $l["is_assigned"] = ((int)($l["assigned_seller_id"] ?? 0) === $sellerId);
    }
    unset($l);

    sendJSON([
        "success"        => true,
        "leads"          => $allLeads,
        "count"          => count($allLeads),
        "assigned_count" => count($assignedLeads),
        "matched_count"  => count($matchedLeads),
    ]);

} catch (Exception $e) {
    sendJSON(["success" => false, "error" => $e->getMessage()]);
}
?>