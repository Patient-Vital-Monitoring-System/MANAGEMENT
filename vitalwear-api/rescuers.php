<?php
require 'db.php';

// Add active column if it doesn't exist
try {
    $pdo->exec("ALTER TABLE rescuer ADD COLUMN IF NOT EXISTS active TINYINT(1) NOT NULL DEFAULT 1");
} catch (Exception $e) {}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query("
        SELECT resc_id, resc_name, resc_email, resc_contact, active
        FROM rescuer
        ORDER BY resc_id
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'resc_id'    => $r['resc_id'],
            'resc_name'  => $r['resc_name'],
            'resc_email' => $r['resc_email'],
            'resc_phone' => $r['resc_contact'],
            'active'     => (bool)$r['active'],
        ];
    }
    echo json_encode($out);

} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) { echo json_encode(["error" => "Invalid JSON"]); exit; }
    if (empty($data))     { echo json_encode(["success" => true]); exit; }
    if (!isset($data[0])) $data = [$data];

    $upsert = $pdo->prepare("
        INSERT INTO rescuer (resc_id, resc_name, resc_email, resc_contact, active)
        VALUES (:id, :name, :email, :contact, :active)
        ON DUPLICATE KEY UPDATE
            resc_name    = VALUES(resc_name),
            resc_email   = VALUES(resc_email),
            resc_contact = VALUES(resc_contact),
            active       = VALUES(active)
    ");

    $incomingIds = [];
    $pdo->beginTransaction();
    try {
        foreach ($data as $row) {
            $id = $row['resc_id'] ?? $row['id'] ?? null;
            if (!$id) continue;
            $upsert->execute([
                ':id'      => $id,
                ':name'    => $row['resc_name']  ?? $row['name']  ?? null,
                ':email'   => $row['resc_email'] ?? $row['email'] ?? null,
                ':contact' => $row['resc_phone'] ?? $row['resc_contact'] ?? $row['phone'] ?? null,
                ':active'  => isset($row['active']) ? ($row['active'] ? 1 : 0) : 1,
            ]);
            $incomingIds[] = $id;
        }
        if (!empty($incomingIds)) {
            $ph  = implode(',', array_fill(0, count($incomingIds), '?'));
            $del = $pdo->prepare("DELETE FROM rescuer WHERE resc_id NOT IN ($ph)");
            $del->execute($incomingIds);
        }
        $pdo->commit();
        echo json_encode(["success" => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(["error" => $e->getMessage()]);
    }
}
?>