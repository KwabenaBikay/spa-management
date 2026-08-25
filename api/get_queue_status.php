<?php
header("Access-Control-Allow-Origin: *");
header('Content-Type: application/json');
session_start();

include '../db.php';

// Security check: Allow frontdesk, reception, admin, and supervisor roles
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['frontdesk', 'reception', 'admin', 'supervisor'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied. Privileged session required.']);
    exit;
}

$today = date('Y-m-d');
$current_month = date('Y-m');

$ongoing_services = [];
$hour = (int)date('H');
if (false && $hour >= 20) { // Disabled for testing; change to 'true && $hour >= 20' to reactivate
    $ongoing_res = $conn->query("SELECT * FROM clients WHERE DATE(created_at) = '$today' AND (status != 'Done' OR status IS NULL) ORDER BY created_at DESC");
} else {
    $ongoing_res = $conn->query("SELECT * FROM clients WHERE DATE(created_at) = '$today' ORDER BY created_at DESC");
}

if ($ongoing_res) {
    while ($row = $ongoing_res->fetch_assoc()) {
        $ongoing_services[] = $row;
    }
}

// Fetch KPI metrics
$total_today = $conn->query("SELECT COUNT(*) as total FROM clients WHERE DATE(created_at) = '$today'")->fetch_assoc()['total'];
$total_month = $conn->query("SELECT COUNT(*) as total FROM clients WHERE DATE_FORMAT(created_at, '%Y-%m') = '$current_month'")->fetch_assoc()['total'];

$rev_today = $conn->query("SELECT SUM(amount) as total FROM clients WHERE DATE(created_at) = '$today'")->fetch_assoc()['total'] ?? 0;
$rev_month = $conn->query("SELECT SUM(amount) as total FROM clients WHERE DATE_FORMAT(created_at, '%Y-%m') = '$current_month'")->fetch_assoc()['total'] ?? 0;

echo json_encode([
    'queue' => $ongoing_services,
    'kpis' => [
        'total_today' => (int)$total_today,
        'total_month' => (int)$total_month,
        'rev_today' => (float)$rev_today,
        'rev_month' => (float)$rev_month
    ]
]);
?>
