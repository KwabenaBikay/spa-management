<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/session.php';
check_api_rate_limit(120, 60);

include '../db.php';
include '../includes/client_timer.php';
include '../includes/due_appointments.php';

// Security check: Allow frontdesk, reception, admin, and supervisor roles
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['frontdesk', 'reception', 'admin', 'supervisor'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied. Privileged session required.']);
    exit;
}

ensure_client_timer_schema($conn);

$today = date('Y-m-d');

$ongoing_services = [];
$stmt = $conn->prepare("SELECT * FROM clients WHERE DATE(created_at) = ? AND voided_at IS NULL AND status != 'Cancelled' AND NOT (status = 'Pending' AND refund_amount >= amount AND amount > 0) ORDER BY created_at DESC");
if ($stmt) {
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $ongoing_res = $stmt->get_result();
    if ($ongoing_res) {
        while ($row = $ongoing_res->fetch_assoc()) {
            $ongoing_services[] = attach_timer_fields($row);
        }
    }
}

$stats = floor_stats_from_queue($ongoing_services);
$due_today = fetch_due_appointments_today($conn);

echo json_encode([
    'queue' => $ongoing_services,
    'due_today' => $due_today,
    'server_unix' => time(),
    'kpis' => [
        'total_today' => (int)$stats['checkins'],
        'rev_today' => (float)$stats['revenue'],
        'waiting' => (int)$stats['waiting'],
        'in_progress' => (int)$stats['in_progress'],
        'overtime' => (int)$stats['overtime'],
        'done' => (int)$stats['done'],
        'cash' => (float)$stats['cash'],
        'momo' => (float)$stats['momo'],
        'other' => (float)$stats['other'],
        'sections' => $stats['sections'],
    ]
]);
?>
