<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/session.php';
check_api_rate_limit(120, 60);
include '../db.php';
include '../includes/client_timer.php';

if (!isset($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

$role = (string)($_SESSION['user']['role'] ?? '');
$sections = staff_sections_for_role($role);
if (!$sections) {
    http_response_code(403);
    echo json_encode(['error' => 'This station does not have a floor queue.']);
    exit;
}

ensure_client_timer_schema($conn);

$queue = filter_clients_for_staff(fetch_section_clients_today($conn, $sections), $_SESSION['user']);
$stats = floor_stats_from_queue($queue);

echo json_encode([
    'queue' => $queue,
    'server_unix' => time(),
    'kpis' => [
        'waiting' => (int)$stats['waiting'],
        'in_progress' => (int)$stats['in_progress'],
        'overtime' => (int)$stats['overtime'],
        'done' => (int)$stats['done'],
        'mine_waiting' => (int)$stats['waiting'],
    ],
]);
