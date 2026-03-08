<?php
require 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // DB columns: vital_id, incident_id, recorded_by, bp_systolic, bp_diastolic,
    //             heart_rate, oxygen_level, recorded_at
    // Dashboard expects: log_id, heart_rate, spo2, bp, temp, timestamp
    $stmt = $pdo->query("
        SELECT
            vital_id,
            incident_id,
            recorded_by,
            bp_systolic,
            bp_diastolic,
            heart_rate,
            oxygen_level,
            DATE_FORMAT(recorded_at, '%Y-%m-%d %H:%i') AS recorded_at
        FROM vitalstat
        ORDER BY recorded_at DESC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        // Map incident_id to LOG prefix so dashboard can join to device_log
        $out[] = [
            'log_id'     => $r['incident_id']
                ? 'LOG' . str_pad($r['incident_id'], 3, '0', STR_PAD_LEFT)
                : 'LOG000',
            'heart_rate' => $r['heart_rate']   !== null ? (int)$r['heart_rate']   : null,
            'spo2'       => $r['oxygen_level'] !== null ? (int)$r['oxygen_level'] : null,
            'bp'         => ($r['bp_systolic'] && $r['bp_diastolic'])
                            ? $r['bp_systolic'] . '/' . $r['bp_diastolic']
                            : null,
            'temp'       => null,   // No temp column in DB
            'timestamp'  => $r['recorded_at'],
            'recorded_by'=> $r['recorded_by'],
        ];
    }
    echo json_encode($out);
}
// POST not implemented: vitalstat is written by IoT devices, not the dashboard
?>