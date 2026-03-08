<?php
require 'db.php';

// The incident table is missing columns the dashboard needs.
// We add them automatically on first run if they don't exist.
$alterCols = [
    "ALTER TABLE incident ADD COLUMN IF NOT EXISTS inc_type   VARCHAR(100) NULL DEFAULT 'Health Alert'",
    "ALTER TABLE incident ADD COLUMN IF NOT EXISTS severity   ENUM('Low','Medium','High','Critical') NULL DEFAULT 'Medium'",
    "ALTER TABLE incident ADD COLUMN IF NOT EXISTS location   VARCHAR(200) NULL DEFAULT NULL",
    "ALTER TABLE incident ADD COLUMN IF NOT EXISTS inc_date   DATE         NULL DEFAULT NULL",
];
foreach ($alterCols as $sql) {
    try { $pdo->exec($sql); } catch (Exception $e) {}
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query("
        SELECT
            incident_id,
            resp_id,
            inc_type,
            severity,
            status,
            inc_date,
            location,
            DATE_FORMAT(start_time, '%Y-%m-%d') AS start_date
        FROM incident
        ORDER BY incident_id
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        // Map DB status enum to dashboard values
        $status = $r['status'] ?? 'active';
        if ($status === 'complete' || $status === 'transferred') $status = 'completed';
        if ($status === 'pending') $status = 'active';

        $out[] = [
            'inc_id'       => 'INC' . str_pad($r['incident_id'], 3, '0', STR_PAD_LEFT),
            'responder_id' => $r['resp_id'] ? 'R' . str_pad($r['resp_id'], 3, '0', STR_PAD_LEFT) : null,
            'type'         => $r['inc_type']  ?? 'Health Alert',
            'severity'     => $r['severity']  ?? 'Medium',
            'status'       => $status,
            'date'         => $r['inc_date']  ?? $r['start_date'],
            'location'     => $r['location']  ?? 'N/A',
        ];
    }
    echo json_encode($out);

} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) { echo json_encode(["error" => "Invalid JSON"]); exit; }
    if (empty($data))     { echo json_encode(["success" => true]); exit; }
    if (!isset($data[0])) $data = [$data];

    function stripId($val) {
        if ($val === null) return null;
        $n = preg_replace('/[^0-9]/', '', $val);
        return $n !== '' ? (int)$n : null;
    }

    $upsert = $pdo->prepare("
        INSERT INTO incident (incident_id, resp_id, inc_type, severity, status, inc_date, location)
        VALUES (:inc_id, :resp_id, :type, :severity, :status, :date, :location)
        ON DUPLICATE KEY UPDATE
            resp_id  = VALUES(resp_id),
            inc_type = VALUES(inc_type),
            severity = VALUES(severity),
            status   = VALUES(status),
            inc_date = VALUES(inc_date),
            location = VALUES(location)
    ");

    $incomingIds = [];
    $pdo->beginTransaction();
    try {
        foreach ($data as $row) {
            $incId  = stripId($row['inc_id']       ?? $row['id']       ?? null);
            $respId = stripId($row['responder_id'] ?? $row['resp_id']  ?? null);

            // Map dashboard status back to DB enum
            $status = $row['status'] ?? 'active';
            if ($status === 'completed') $status = 'complete';

            $upsert->execute([
                ':inc_id'   => $incId,
                ':resp_id'  => $respId,
                ':type'     => $row['type']     ?? 'Health Alert',
                ':severity' => $row['severity'] ?? 'Medium',
                ':status'   => $status,
                ':date'     => $row['date']     ?? date('Y-m-d'),
                ':location' => $row['location'] ?? null,
            ]);

            if ($incId) $incomingIds[] = $incId;
        }

        // Delete incidents removed in dashboard
        if (!empty($incomingIds)) {
            $ph  = implode(',', array_fill(0, count($incomingIds), '?'));
            $del = $pdo->prepare("DELETE FROM incident WHERE incident_id NOT IN ($ph)");
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