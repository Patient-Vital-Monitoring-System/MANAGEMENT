<?php
require 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT dev_id, dev_serial, dev_name, dev_type, dev_status FROM device ORDER BY dev_id");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) { echo json_encode(["error" => "Invalid JSON"]); exit; }
    if (empty($data))     { echo json_encode(["success" => true]); exit; }
    if (!isset($data[0])) $data = [$data];

    $upsert = $pdo->prepare("
        INSERT INTO device (dev_id, dev_serial, dev_name, dev_type, dev_status)
        VALUES (:id, :serial, :name, :type, :status)
        ON DUPLICATE KEY UPDATE
            dev_serial = VALUES(dev_serial),
            dev_name   = VALUES(dev_name),
            dev_type   = VALUES(dev_type),
            dev_status = VALUES(dev_status)
    ");

    $incomingIds = [];
    $pdo->beginTransaction();
    try {
        foreach ($data as $row) {
            $id = $row['dev_id'] ?? $row['id'] ?? null;
            if (!$id) continue;
            $upsert->execute([
                ':id'     => $id,
                ':serial' => $row['dev_serial'] ?? $row['serial'] ?? null,
                ':name'   => $row['dev_name']   ?? $row['name']   ?? null,
                ':type'   => $row['dev_type']   ?? $row['type']   ?? null,
                ':status' => $row['dev_status'] ?? $row['status'] ?? 'available',
            ]);
            $incomingIds[] = $id;
        }
        if (!empty($incomingIds)) {
            $ph  = implode(',', array_fill(0, count($incomingIds), '?'));
            $del = $pdo->prepare("DELETE FROM device WHERE dev_id NOT IN ($ph)");
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