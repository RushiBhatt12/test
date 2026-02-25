<?php
ob_start();
ini_set("display_errors", 0);
error_reporting(0);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Admin-Key");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    ob_end_clean(); echo json_encode(["success" => true]); exit;
}

function respond($d) { ob_end_clean(); echo json_encode($d); exit; }

require __DIR__ . "/config.php";

// ── Password check via header or body ──────────────────────────
define("ADMIN_PASSWORD", "bizboost2026"); // ← change this

$raw  = file_get_contents("php://input");
$data = json_decode($raw, true) ?? [];

$key = $_SERVER["HTTP_X_ADMIN_KEY"] ?? ($data["admin_key"] ?? "");
if ($key !== ADMIN_PASSWORD) {
    respond(["success" => false, "error" => "Unauthorized"]);
}

$action = $data["action"] ?? "";
$sid    = (int)($data["seller_id"] ?? 0);

try {

    // ── LIST ALL SELLERS ───────────────────────────────────────
    if ($action === "list") {
        $rows = db()->query("
            SELECT s.id, s.name, s.category, s.city, s.state, s.email,
                   s.is_featured, s.is_verified, s.featured_order, s.created_at,
                   COALESCE(d.products_offered,'') AS products_offered
            FROM sellers s
            LEFT JOIN seller_details d ON d.seller_id = s.id
            ORDER BY s.is_featured DESC, s.featured_order ASC, s.name ASC
        ")->fetchAll();

        foreach ($rows as &$r) {
            $r["id"]            = (int)$r["id"];
            $r["is_featured"]   = (bool)$r["is_featured"];
            $r["is_verified"]   = (bool)$r["is_verified"];
            $r["featured_order"]= (int)$r["featured_order"];
        }
        respond(["success" => true, "sellers" => $rows]);
    }

    // ── TOGGLE FEATURED ────────────────────────────────────────
    if ($action === "toggle_featured" && $sid) {
        $cur = db()->prepare("SELECT is_featured FROM sellers WHERE id=?");
        $cur->execute([$sid]);
        $val = $cur->fetch()["is_featured"] ? 0 : 1;
        db()->prepare("UPDATE sellers SET is_featured=? WHERE id=?")->execute([$val, $sid]);
        respond(["success" => true, "value" => (bool)$val]);
    }

    // ── TOGGLE VERIFIED ────────────────────────────────────────
    if ($action === "toggle_verified" && $sid) {
        $cur = db()->prepare("SELECT is_verified FROM sellers WHERE id=?");
        $cur->execute([$sid]);
        $val = $cur->fetch()["is_verified"] ? 0 : 1;
        db()->prepare("UPDATE sellers SET is_verified=? WHERE id=?")->execute([$val, $sid]);
        respond(["success" => true, "value" => (bool)$val]);
    }

    // ── SET ORDER ──────────────────────────────────────────────
    if ($action === "set_order" && $sid) {
        $order = (int)($data["order"] ?? 0);
        db()->prepare("UPDATE sellers SET featured_order=? WHERE id=?")->execute([$order, $sid]);
        respond(["success" => true]);
    }

    // ── LIST ALL LEADS (with assigned seller name) ─────────────
    if ($action === "list_leads") {

        // Safely add assignment columns if not yet in DB
        try { db()->exec("ALTER TABLE leads ADD COLUMN assigned_seller_id INT NULL DEFAULT NULL"); } catch (Throwable $_) {}
        try { db()->exec("ALTER TABLE leads ADD COLUMN assigned_at DATETIME NULL DEFAULT NULL");   } catch (Throwable $_) {}

        $rows = db()->query("
            SELECT
                l.*,
                s.name  AS assigned_seller_name,
                s.city  AS assigned_seller_city
            FROM leads l
            LEFT JOIN sellers s ON s.id = l.assigned_seller_id
            ORDER BY l.created_at DESC
            LIMIT 500
        ")->fetchAll();

        foreach ($rows as &$r) {
            $r["id"]                 = (int)$r["id"];
            $r["budget_min"]         = (float)($r["budget_min"] ?? 0);
            $r["budget_max"]         = (float)($r["budget_max"] ?? 0);
            $r["assigned_seller_id"] = $r["assigned_seller_id"]
                                       ? (int)$r["assigned_seller_id"]
                                       : null;
        }
        respond(["success" => true, "leads" => $rows, "count" => count($rows)]);
    }

    // ── ASSIGN LEAD(S) TO SELLER ───────────────────────────────
    if ($action === "assign_lead") {
        $targetSellerId = (int)($data["seller_id"] ?? 0);
        $leadIds        = $data["lead_ids"] ?? [];

        if (!$targetSellerId) {
            respond(["success" => false, "error" => "seller_id required"]);
        }
        if (empty($leadIds) || !is_array($leadIds)) {
            respond(["success" => false, "error" => "lead_ids required (array)"]);
        }

        // Safely add columns if missing
        try { db()->exec("ALTER TABLE leads ADD COLUMN assigned_seller_id INT NULL DEFAULT NULL"); } catch (Throwable $_) {}
        try { db()->exec("ALTER TABLE leads ADD COLUMN assigned_at DATETIME NULL DEFAULT NULL");   } catch (Throwable $_) {}

        // Validate seller exists
        $chk = db()->prepare("SELECT id FROM sellers WHERE id=?");
        $chk->execute([$targetSellerId]);
        if (!$chk->fetch()) {
            respond(["success" => false, "error" => "Seller not found"]);
        }

        // Sanitise lead ids to integers
        $leadIds = array_map('intval', $leadIds);
        $leadIds = array_filter($leadIds, fn($id) => $id > 0);

        if (empty($leadIds)) {
            respond(["success" => false, "error" => "No valid lead_ids"]);
        }

        $placeholders = implode(',', array_fill(0, count($leadIds), '?'));
        $stmt = db()->prepare("
            UPDATE leads
            SET assigned_seller_id = ?, assigned_at = NOW()
            WHERE id IN ($placeholders)
        ");
        $stmt->execute(array_merge([$targetSellerId], $leadIds));

        respond([
            "success"  => true,
            "updated"  => $stmt->rowCount(),
            "seller_id"=> $targetSellerId,
        ]);
    }

    // ── DELETE LEAD ────────────────────────────────────────────
    if ($action === "delete_lead") {
        $lid = (int)($data["lead_id"] ?? 0);
        if (!$lid) {
            respond(["success" => false, "error" => "lead_id required"]);
        }
        $stmt = db()->prepare("DELETE FROM leads WHERE id=?");
        $stmt->execute([$lid]);
        if ($stmt->rowCount() === 0) {
            respond(["success" => false, "error" => "Lead not found"]);
        }
        respond(["success" => true, "deleted_id" => $lid]);
    }

    respond(["success" => false, "error" => "Unknown action or missing parameters"]);

} catch (Throwable $e) {
    respond(["success" => false, "error" => $e->getMessage()]);
}
?>