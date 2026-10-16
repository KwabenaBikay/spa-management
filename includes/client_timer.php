<?php
/**
 * Service-duration countdown helpers for Front Desk tracking.
 * Status is still marked Done only by the attending staff.
 */

function ensure_client_timer_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (getenv('SPA_AUTO_MIGRATE') === '1') {
        $conn->query("ALTER TABLE clients ADD COLUMN IF NOT EXISTS status VARCHAR(50) DEFAULT 'Pending'");
        $conn->query("ALTER TABLE clients ADD COLUMN IF NOT EXISTS service_started_at DATETIME NULL DEFAULT NULL");
    }
}

function parse_duration_minutes($duration): ?int
{
    if ($duration === null || $duration === '') {
        return null;
    }
    if (preg_match('/(\d+)/', (string)$duration, $m)) {
        $minutes = (int)$m[1];
        return $minutes > 0 ? $minutes : null;
    }
    return null;
}

function update_client_status(mysqli $conn, int $client_id, string $status): bool
{
    $allowed = ['Pending', 'In Progress', 'Done'];
    if (!in_array($status, $allowed, true) || $client_id < 1) {
        return false;
    }

    if ($status === 'In Progress') {
        $stmt = $conn->prepare("UPDATE clients SET status = ?, service_started_at = COALESCE(service_started_at, NOW()) WHERE id = ?");
        $stmt->bind_param("si", $status, $client_id);
    } elseif ($status === 'Pending') {
        $stmt = $conn->prepare("UPDATE clients SET status = ?, service_started_at = NULL WHERE id = ?");
        $stmt->bind_param("si", $status, $client_id);
    } else {
        $stmt = $conn->prepare("UPDATE clients SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $client_id);
    }

    return $stmt && $stmt->execute();
}

function attach_timer_fields(array $row): array
{
    $row['duration_minutes'] = parse_duration_minutes($row['duration'] ?? null);
    $row['service_started_unix'] = !empty($row['service_started_at'])
        ? strtotime($row['service_started_at'])
        : null;
    $row['created_unix'] = !empty($row['created_at'])
        ? strtotime($row['created_at'])
        : null;
    return $row;
}

function countdown_cell_html(array $client): string
{
    $status = $client['status'] ?? 'Pending';
    $mins = $client['duration_minutes'] ?? parse_duration_minutes($client['duration'] ?? null);
    $started = $client['service_started_unix'] ?? null;
    $rawDuration = trim((string)($client['duration'] ?? ''));

    if (!$mins || $status === 'Done') {
        return '<span class="countdown-idle">—</span>';
    }

    if ($status !== 'In Progress' || !$started) {
        $label = $rawDuration !== '' ? htmlspecialchars($rawDuration) : ($mins . ' min');
        if ($status === 'Pending') {
            return '<span class="countdown-idle">' . $label . ' booked</span>';
        }
        return '<span class="countdown-idle">' . $label . '</span>';
    }

    $id = (int)$client['id'];
    $name = htmlspecialchars($client['name'] ?? '', ENT_QUOTES);
    $service = $client['service_type'] ?? '';
    if (!empty($client['massage_type'])) {
        $service .= ' (' . $client['massage_type'] . ')';
    }
    $service = htmlspecialchars($service, ENT_QUOTES);

    return '<span class="countdown" data-client-id="' . $id . '" data-started-unix="' . (int)$started . '" data-minutes="' . (int)$mins . '" data-name="' . $name . '" data-service="' . $service . '"><i class="fas fa-clock"></i> —</span>';
}

function is_client_overtime(array $client, ?int $now = null): bool
{
    $now = $now ?? time();
    $status = $client['status'] ?? 'Pending';
    if ($status !== 'In Progress') {
        return false;
    }
    $mins = $client['duration_minutes'] ?? parse_duration_minutes($client['duration'] ?? null);
    $started = $client['service_started_unix'] ?? null;
    return $mins && $started && ($started + ($mins * 60)) <= $now;
}

function floor_stats_from_queue(array $clients): array
{
    $now = time();
    $waiting = 0;
    $in_progress = 0;
    $overtime = 0;
    $done = 0;
    $cash = 0.0;
    $momo = 0.0;
    $other = 0.0;
    $sections = [];

    $checkins = 0;
    foreach ($clients as $client) {
        if (!empty($client['voided_at']) || ($client['status'] ?? '') === 'Cancelled') {
            continue;
        }
        $rawAmount = (float)($client['amount'] ?? 0);
        $refundAmount = (float)($client['refund_amount'] ?? 0);
        if ($refundAmount >= $rawAmount && $rawAmount > 0 && ($client['status'] ?? 'Pending') === 'Pending') {
            continue;
        }
        $checkins++;
        $status = $client['status'] ?? 'Pending';
        $amount = max(0, (float)($client['amount'] ?? 0) - (float)($client['refund_amount'] ?? 0));
        $mode = strtolower((string)($client['payment_mode'] ?? ''));
        if (strpos($mode, 'cash') !== false) {
            $cash += $amount;
        } elseif (strpos($mode, 'mobile') !== false || strpos($mode, 'momo') !== false) {
            $momo += $amount;
        } else {
            $other += $amount;
        }

        $section = trim((string)($client['section'] ?? ''));
        if ($section === '') {
            $section = 'Unspecified';
        }
        if (!isset($sections[$section])) {
            $sections[$section] = [
                'visits' => 0,
                'waiting' => 0,
                'in_progress' => 0,
                'overtime' => 0,
                'revenue' => 0.0,
            ];
        }
        $sections[$section]['visits']++;
        $sections[$section]['revenue'] += $amount;

        if ($status === 'Done') {
            $done++;
        } elseif ($status === 'In Progress') {
            $in_progress++;
            $sections[$section]['in_progress']++;
            if (is_client_overtime($client, $now)) {
                $overtime++;
                $sections[$section]['overtime']++;
            }
        } else {
            $waiting++;
            $sections[$section]['waiting']++;
        }
    }

    uasort($sections, static function ($a, $b) {
        $backlogA = (int)$a['waiting'] + (int)$a['overtime'];
        $backlogB = (int)$b['waiting'] + (int)$b['overtime'];
        if ($backlogA !== $backlogB) {
            return $backlogB <=> $backlogA;
        }
        if ((int)$a['in_progress'] !== (int)$b['in_progress']) {
            return (int)$b['in_progress'] <=> (int)$a['in_progress'];
        }
        return (int)$b['visits'] <=> (int)$a['visits'];
    });

    return [
        'checkins' => $checkins,
        'waiting' => $waiting,
        'in_progress' => $in_progress,
        'overtime' => $overtime,
        'done' => $done,
        'cash' => $cash,
        'momo' => $momo,
        'other' => $other,
        'revenue' => $cash + $momo + $other,
        'sections' => $sections,
    ];
}

function sort_queue_for_floor(array $clients, string $view = 'active', ?int $now = null): array
{
    $now = $now ?? time();
    usort($clients, static function ($a, $b) use ($view, $now) {
        $createdA = (int)($a['created_unix'] ?? 0);
        $createdB = (int)($b['created_unix'] ?? 0);
        if ($view === 'completed') {
            return $createdB <=> $createdA;
        }

        $statusA = $a['status'] ?? 'Pending';
        $statusB = $b['status'] ?? 'Pending';
        $rankA = $statusA === 'In Progress' ? 1 : 0;
        $rankB = $statusB === 'In Progress' ? 1 : 0;
        if ($rankA !== $rankB) {
            return $rankA <=> $rankB;
        }

        if ($statusA !== 'In Progress') {
            return $createdA <=> $createdB;
        }

        $overA = is_client_overtime($a, $now) ? 0 : 1;
        $overB = is_client_overtime($b, $now) ? 0 : 1;
        if ($overA !== $overB) {
            return $overA <=> $overB;
        }

        $endA = ((int)($a['service_started_unix'] ?? 0)) + (((int)($a['duration_minutes'] ?? 0)) * 60);
        $endB = ((int)($b['service_started_unix'] ?? 0)) + (((int)($b['duration_minutes'] ?? 0)) * 60);
        return $endA <=> $endB;
    });

    return array_values($clients);
}

function waiting_minutes(array $client, ?int $now = null): ?int
{
    $status = $client['status'] ?? 'Pending';
    if ($status !== 'Pending') {
        return null;
    }
    $created = $client['created_unix'] ?? null;
    if (!$created) {
        return null;
    }
    $now = $now ?? time();
    return (int)max(0, floor(($now - $created) / 60));
}

function wait_row_class(?int $wait_minutes): string
{
    if ($wait_minutes === null) {
        return '';
    }
    if ($wait_minutes >= 30) {
        return 'row-wait-long';
    }
    if ($wait_minutes >= 15) {
        return 'row-wait';
    }
    return '';
}

function staff_sections_for_role(string $role): array
{
    switch ($role) {
        case 'massage':
        case 'therapist':
            return ['Massage'];
        case 'nails':
            return ['Nails & Manicure', 'Pedicure & Manicure'];
        case 'facials':
            return ['Facials'];
        case 'salon':
            return ['Hair Salon'];
        case 'barbering':
            return ['Hair Barbering'];
        default:
            return [];
    }
}

function floor_staff_by_section(mysqli $conn): array
{
    $bySection = [];
    $res = $conn->query("SELECT name, username, role FROM staff WHERE role IN ('massage', 'therapist', 'salon', 'barbering', 'facials', 'nails') ORDER BY name ASC");
    if (!$res) {
        return $bySection;
    }

    $seen = [];
    while ($row = $res->fetch_assoc()) {
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') {
            $name = trim((string)($row['username'] ?? ''));
        }
        if ($name === '') {
            continue;
        }
        foreach (staff_sections_for_role((string)($row['role'] ?? '')) as $section) {
            $key = strtolower($section . '|' . $name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $bySection[$section][] = $name;
        }
    }

    return $bySection;
}

function fetch_section_clients_today(mysqli $conn, array $sections): array
{
    if (!$sections) {
        return [];
    }
    $today = date('Y-m-d');
    $in = implode(',', array_fill(0, count($sections), '?'));
    $sql = "SELECT * FROM clients WHERE section IN ($in) AND voided_at IS NULL AND status != 'Cancelled' AND NOT (status = 'Pending' AND refund_amount >= amount AND amount > 0) AND (DATE(created_at) = ? OR status = 'In Progress') ORDER BY created_at ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $types = str_repeat('s', count($sections)) . 's';
    $params = array_merge(array_values($sections), [$today]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = attach_timer_fields($row);
        }
    }
    return $rows;
}

function client_service_label(array $client): string
{
    $service = trim((string)($client['service_type'] ?? ''));
    if (!empty($client['massage_type'])) {
        $service .= ' (' . $client['massage_type'] . ')';
    }
    return $service;
}

function staff_identity_names($staff): array
{
    $names = [];
    if (is_array($staff)) {
        foreach (['name', 'username'] as $key) {
            $value = trim((string)($staff[$key] ?? ''));
            if ($value !== '') {
                $names[] = $value;
            }
        }
    } else {
        $value = trim((string)$staff);
        if ($value !== '') {
            $names[] = $value;
        }
    }

    $unique = [];
    foreach ($names as $name) {
        $key = strtolower($name);
        if (!isset($unique[$key])) {
            $unique[$key] = $name;
        }
    }
    return array_values($unique);
}

function is_ticket_assigned_to(array $client, $staff): bool
{
    $assigned = trim((string)($client['staff_name'] ?? ''));
    if ($assigned === '') {
        return false;
    }
    foreach (staff_identity_names($staff) as $alias) {
        if (strcasecmp($assigned, $alias) === 0) {
            return true;
        }
    }
    return false;
}

function filter_clients_for_staff(array $clients, $staff): array
{
    return array_values(array_filter($clients, static function ($client) use ($staff) {
        return is_ticket_assigned_to($client, $staff);
    }));
}

function staff_status_badge_class(string $status): string
{
    if ($status === 'In Progress') {
        return 'badge-inprogress';
    }
    if ($status === 'Done') {
        return 'badge-done';
    }
    return 'badge-pending';
}
