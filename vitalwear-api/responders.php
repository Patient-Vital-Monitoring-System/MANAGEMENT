<?php
require 'db.php';

// Ensure the responder table has the columns the dashboard needs.
// If they don't exist yet, add them silently.
try {
    $pdo->exec("ALTER TABLE responder ADD COLUMN IF NOT EXISTS active TINYINT(1) NOT NULL DEFAULT 1");
} catch (Exception $e) {}
try {
    $pdo->exec("ALTER TABLE responder ADD COLUMN IF NOT EXISTS assigned_device VARCHAR(50) NULL DEFAULT NULL");
} catch (Exception $e) {}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query("
        SELECT resp_id, resp_name, resp_email, resp_contact,
               active, assigned_device
        FROM responder
        ORDER BY resp_id
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'resp_id'         => $r['resp_id'],
            'resp_name'       => $r['resp_name'],
            'resp_email'      => $r['resp_email'],
            'resp_phone'      => $r['resp_contact'],
            'active'          => (bool)$r['active'],
            'assigned_device' => $r['assigned_device'],
        ];
    }
    echo json_encode($out);

} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) { echo json_encode(["error" => "Invalid JSON"]); exit; }
    if (empty($data))     { echo json_encode(["success" => true]); exit; }
    if (!isset($data[0])) $data = [$data];

    $upsert = $pdo->prepare("
        INSERT INTO responder (resp_id, resp_name, resp_email, resp_contact, active, assigned_device)
        VALUES (:id, :name, :email, :contact, :active, :device)
        ON DUPLICATE KEY UPDATE
            resp_name       = VALUES(resp_name),
            resp_email      = VALUES(resp_email),
            resp_contact    = VALUES(resp_contact),
            active          = VALUES(active),
            assigned_device = VALUES(assigned_device)
    ");

    $incomingIds = [];
    $pdo->beginTransaction();
    try {
        foreach ($data as $row) {
            $id = $row['resp_id'] ?? $row['id'] ?? null;
            if (!$id) continue;
            $upsert->execute([
                ':id'      => $id,
                ':name'    => $row['resp_name']  ?? $row['name']  ?? null,
                ':email'   => $row['resp_email'] ?? $row['email'] ?? null,
                ':contact' => $row['resp_phone'] ?? $row['resp_contact'] ?? $row['phone'] ?? null,
                ':active'  => isset($row['active']) ? ($row['active'] ? 1 : 0) : 1,
                ':device'  => $row['assigned_device'] ?? $row['assignedDevice'] ?? null,
            ]);
            $incomingIds[] = $id;
        }
        if (!empty($incomingIds)) {
            $ph  = implode(',', array_fill(0, count($incomingIds), '?'));
            $del = $pdo->prepare("DELETE FROM responder WHERE resp_id NOT IN ($ph)");
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