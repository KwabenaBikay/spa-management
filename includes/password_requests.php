<?php

function ensure_password_requests_table(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS password_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        staff_id INT NOT NULL,
        username VARCHAR(50) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_at DATETIME NULL,
        resolved_by VARCHAR(100) NULL,
        KEY staff_status (staff_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function record_password_request(mysqli $conn, string $username): void
{
    $username = trim($username);
    if ($username === '') {
        return;
    }

    ensure_password_requests_table($conn);

    $staff = $conn->prepare("SELECT id FROM staff WHERE username = ? LIMIT 1");
    if (!$staff) {
        return;
    }
    $staff->bind_param('s', $username);
    $staff->execute();
    $row = $staff->get_result()->fetch_assoc();
    if (!$row) {
        return;
    }

    $staffId = (int)$row['id'];
    $existing = $conn->prepare("SELECT id FROM password_requests WHERE staff_id = ? AND status = 'pending' LIMIT 1");
    if (!$existing) {
        return;
    }
    $existing->bind_param('i', $staffId);
    $existing->execute();
    if ($existing->get_result()->fetch_assoc()) {
        return;
    }

    $insert = $conn->prepare("INSERT INTO password_requests (staff_id, username, status, requested_at) VALUES (?, ?, 'pending', NOW())");
    if (!$insert) {
        return;
    }
    $insert->bind_param('is', $staffId, $username);
    $insert->execute();
}
