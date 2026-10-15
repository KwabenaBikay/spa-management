<?php
/**
 * Today's booked appointments for the Front Desk floor station.
 * Full booking CRUD stays on appointments.php.
 */

function ensure_appointments_table(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (getenv('SPA_AUTO_MIGRATE') === '1') {
        $conn->query("CREATE TABLE IF NOT EXISTS appointments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_name VARCHAR(255) NOT NULL,
            phone VARCHAR(50) NOT NULL,
            service_type VARCHAR(100) NOT NULL,
            staff_assigned VARCHAR(100) NULL,
            appointment_date DATE NOT NULL,
            appointment_time TIME NOT NULL,
            status ENUM('Scheduled', 'Completed', 'Cancelled') DEFAULT 'Scheduled',
            reminded_at DATETIME NULL,
            reminded_by VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS reminded_at DATETIME NULL");
        $conn->query("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS reminded_by VARCHAR(100) NULL");
    }
}

function mark_appointment_reminded(mysqli $conn, int $id, string $by): bool
{
    if ($id < 1) {
        return false;
    }
    ensure_appointments_table($conn);
    $stmt = $conn->prepare("UPDATE appointments SET reminded_at = NOW(), reminded_by = ? WHERE id = ? AND status = 'Scheduled'");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('si', $by, $id);
    return $stmt->execute() && $stmt->affected_rows > 0;
}

function fetch_due_appointments_today(mysqli $conn, ?int $now = null): array
{
    ensure_appointments_table($conn);
    $now = $now ?? time();
    $today = date('Y-m-d', $now);
    $due = [];

    $stmt = $conn->prepare("SELECT id, client_name, phone, service_type, staff_assigned, appointment_date, appointment_time, status, reminded_at
        FROM appointments
        WHERE appointment_date = ? AND status = 'Scheduled'
        ORDER BY appointment_time ASC");
    if (!$stmt) {
        return $due;
    }
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result) {
        return $due;
    }

    while ($row = $result->fetch_assoc()) {
        $unix = strtotime($row['appointment_date'] . ' ' . $row['appointment_time']);
        $due[] = [
            'id' => (int)$row['id'],
            'client_name' => $row['client_name'] ?? '',
            'phone' => $row['phone'] ?? '',
            'service_type' => $row['service_type'] ?? '',
            'staff_assigned' => $row['staff_assigned'] ?? '',
            'appointment_time' => $row['appointment_time'] ?? '',
            'appointment_unix' => $unix ?: null,
            'time_label' => $unix ? date('h:i A', $unix) : '',
            'is_late' => $unix ? ($unix < $now) : false,
            'reminded' => !empty($row['reminded_at']),
        ];
    }

    return $due;
}

function resolve_appointment_for_register(mysqli $conn, array $appointment): array
{
    $service = trim((string)($appointment['service_type'] ?? ''));
    $prefill = [
        'appointment_id' => (int)($appointment['id'] ?? 0),
        'form' => 'others',
        'name' => (string)($appointment['client_name'] ?? ''),
        'phone' => preg_replace('/\D+/', '', (string)($appointment['phone'] ?? '')),
        'staff_name' => (string)($appointment['staff_assigned'] ?? ''),
        'section' => '',
        'service_type' => $service,
        'massage_type' => '',
        'category' => '',
        'time_label' => '',
    ];

    $unix = strtotime(($appointment['appointment_date'] ?? '') . ' ' . ($appointment['appointment_time'] ?? ''));
    if ($unix) {
        $prefill['time_label'] = date('h:i A', $unix);
    }

    if ($service !== '') {
        $stmt = $conn->prepare("SELECT name, category FROM massage_types WHERE name = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $service);
            $stmt->execute();
            $massage = $stmt->get_result()->fetch_assoc();
            if ($massage) {
                $prefill['form'] = 'massage';
                $prefill['massage_type'] = $massage['name'];
                $prefill['category'] = $massage['category'] ?: 'Full Body Massage';
                $prefill['service_type'] = 'Massage';
                return $prefill;
            }
        }

        $stmt = $conn->prepare("SELECT service_name, section FROM services WHERE service_name = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $service);
            $stmt->execute();
            $svc = $stmt->get_result()->fetch_assoc();
            if ($svc) {
                $prefill['form'] = 'others';
                $prefill['section'] = (string)($svc['section'] ?? '');
                $prefill['service_type'] = (string)($svc['service_name'] ?? $service);
                return $prefill;
            }
        }
    }

    if (strcasecmp($service, 'Massage') === 0) {
        $prefill['form'] = 'massage';
        $prefill['service_type'] = 'Massage';
    }

    return $prefill;
}
