<?php
header("Access-Control-Allow-Origin: *");
header('Content-Type: application/json');
session_start();

include '../db.php';

// Security check: must be logged in
if (!isset($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied. Privileged session required.']);
    exit;
}

$role = $_SESSION['user']['role'] ?? '';
$sections = [];

if ($role === 'massage' || $role === 'therapist') {
    $sections = ['Massage'];
} elseif ($role === 'nails') {
    $sections = ['Nails & Manicure', 'Pedicure & Manicure'];
} elseif ($role === 'facials') {
    $sections = ['Facials'];
} elseif ($role === 'salon') {
    $sections = ['Hair Salon'];
} elseif ($role === 'barbering') {
    $sections = ['Hair Barbering'];
} else {
    // Admin, supervisor or frontdesk can specify section as param
    if (isset($_GET['section'])) {
        $sections = [$_GET['section']];
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid role or section parameter missing.']);
        exit;
    }
}

// Build prepared query dynamically based on count of sections
$in_clause = implode(',', array_fill(0, count($sections), '?'));
$stmt = $conn->prepare("SELECT * FROM clients WHERE section IN ($in_clause) ORDER BY created_at DESC");

if ($stmt) {
    $types = str_repeat('s', count($sections));
    $stmt->bind_param($types, ...$sections);
    $stmt->execute();
    $res = $stmt->get_result();

    $clients = [];
    while ($row = $res->fetch_assoc()) {
        $clients[] = $row;
    }
    echo json_encode($clients);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Database query preparation failed.']);
}
?>
