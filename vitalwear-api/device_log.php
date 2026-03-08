<?php
require 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Return all logs; format timestamps as date strings
    $stmt = $pdo->query("
        SELECT
            log_id,
            dev_id,
            resp_id,
            DATE_FORMAT(date_assigned, '%Y-%m-%d') AS date_assigned,
            DATE_FORMAT(date_returned, '%Y-%m-%d') AS date_returned,
            verified_return
        FROM device_log
        ORDER BY log_id
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'log_id'          => 'LOG' . str_pad($r['log_id'],  3, '0', STR_PAD_LEFT),
            'device_id'       => 'DEV' . str_pad($r['dev_id'],  3, '0', STR_PAD_LEFT),
            'responder_id'    => 'R'   . str_pad($r['resp_id'], 3, '0', STR_PAD_LEFT),
            'date_assigned'   => $r['date_assigned'],
            'date_returned'   => $r['date_returned'],   // null if not returned
            'verified_return' => (bool)$r['verified_return'],
        ];
    }
    echo json_encode($out);

} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) { echo json_encode(["error" => "Invalid JSON"]); exit; }
    if (empty($data))     { echo json_encode(["success" => true]); exit; }
    if (!isset($data[0])) $data = [$data];

    // Helper: strip alpha prefix and return integer
    function stripId($val) {
        if ($val === null) return null;
        $n = preg_replace('/[^0-9]/', '', $val);
        return $n !== '' ? (int)$n : null;
    }

    $upsert = $pdo->prepare("
        INSERT INTO device_log (log_id, dev_id, resp_id, date_assigned, date_returned, verified_return)
        VALUES (:log_id, :dev_id, :resp_id, :assigned, :returned, :verified)
        ON DUPLICATE KEY UPDATE
            dev_id          = VALUES(dev_id),
            resp_id         = VALUES(resp_id),
            date_assigned   = VALUES(date_assigned),
            date_returned   = VALUES(date_returned),
            verified_return = VALUES(verified_return)
    ");

    $incomingIds = [];
    $pdo->beginTransaction();
    try {
        foreach ($data as $row) {
            $logId  = stripId($row['log_id']       ?? $row['id']          ?? null);
            $devId  = stripId($row['device_id']    ?? $row['dev_id']      ?? null);
            $respId = stripId($row['responder_id'] ?? $row['resp_id']     ?? null);

            if (!$devId) continue;

            $returned = $row['date_returned'] ?? null;
            if ($returned === '' || $returned === 'null') $returned = null;

            $upsert->execute([
                ':log_id'   => $logId,
                ':dev_id'   => $devId,
                ':resp_id'  => $respId,
                ':assigned' => $row['date_assigned'] ?? date('Y-m-d'),
                ':returned' => $returned,
                ':verified' => !empty($row['verified_return']) ? 1 : 0,
            ]);

            if ($logId) $incomingIds[] = $logId;
        }

        // Remove logs that were deleted in dashboard
        if (!empty($incomingIds)) {
            $ph  = implode(',', array_fill(0, count($incomingIds), '?'));
            $del = $pdo->prepare("DELETE FROM device_log WHERE log_id NOT IN ($ph)");
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