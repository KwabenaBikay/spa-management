<?php

function ensure_money_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (getenv('SPA_AUTO_MIGRATE') === '1') {
        $conn->query("ALTER TABLE clients ADD COLUMN IF NOT EXISTS voided_at DATETIME NULL");
        $conn->query("ALTER TABLE clients ADD COLUMN IF NOT EXISTS void_reason VARCHAR(255) NULL");
        $conn->query("ALTER TABLE clients ADD COLUMN IF NOT EXISTS voided_by VARCHAR(100) NULL");
        $conn->query("ALTER TABLE clients ADD COLUMN IF NOT EXISTS refund_amount DECIMAL(10,2) NOT NULL DEFAULT 0");
        $conn->query("ALTER TABLE clients ADD COLUMN IF NOT EXISTS refund_reason VARCHAR(255) NULL");
        $conn->query("ALTER TABLE clients ADD COLUMN IF NOT EXISTS refunded_at DATETIME NULL");
        $conn->query("ALTER TABLE clients ADD COLUMN IF NOT EXISTS refunded_by VARCHAR(100) NULL");

        $conn->query("CREATE TABLE IF NOT EXISTS till_closes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            close_date DATE NOT NULL,
            cash_expected DECIMAL(10,2) NOT NULL DEFAULT 0,
            cash_counted DECIMAL(10,2) NOT NULL DEFAULT 0,
            momo_expected DECIMAL(10,2) NOT NULL DEFAULT 0,
            momo_counted DECIMAL(10,2) NOT NULL DEFAULT 0,
            note VARCHAR(255) NULL,
            closed_by VARCHAR(100) NOT NULL,
            closed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY close_date (close_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $appointments = $conn->query("SHOW TABLES LIKE 'appointments'");
        if ($appointments && $appointments->num_rows > 0) {
            $conn->query("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS reminded_at DATETIME NULL");
            $conn->query("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS reminded_by VARCHAR(100) NULL");
        }
    }
}

function client_net_amount_sql(string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    return "CASE WHEN {$prefix}voided_at IS NOT NULL THEN 0 ELSE GREATEST(COALESCE({$prefix}amount, 0) - COALESCE({$prefix}refund_amount, 0), 0) END";
}

function client_live_visit_sql(string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    return "{$prefix}voided_at IS NULL";
}

function client_net_amount(array $client): float
{
    if (!empty($client['voided_at'])) {
        return 0.0;
    }
    return max(0, (float)($client['amount'] ?? 0) - (float)($client['refund_amount'] ?? 0));
}

function money_actor_name(): string
{
    $user = $_SESSION['user'] ?? [];
    $name = trim((string)($user['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    return trim((string)($user['username'] ?? 'Staff'));
}

function write_money_audit(mysqli $conn, string $action, string $entity, int $entityId, ?array $before, ?array $after): void
{
    $userId = (int)($_SESSION['user']['id'] ?? 0);
    $username = (string)($_SESSION['user']['username'] ?? '');
    $role = (string)($_SESSION['user']['role'] ?? '');
    $beforeJson = $before ? json_encode($before) : null;
    $afterJson = $after ? json_encode($after) : null;
    if ($log = $conn->prepare("INSERT INTO audit_logs (user_id, username, role, action, entity, entity_id, before_data, after_data, created_at) VALUES (?,?,?,?,?,?,?,?, NOW())")) {
        $log->bind_param('issssiss', $userId, $username, $role, $action, $entity, $entityId, $beforeJson, $afterJson);
        $log->execute();
    }
}

function fetch_client_visit(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare('SELECT * FROM clients WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function void_client_visit(mysqli $conn, int $id, string $reason, bool $todayOnly): string
{
    $reason = trim($reason);
    if ($id < 1 || $reason === '') {
        return 'Enter a reason for the void.';
    }
    $visit = fetch_client_visit($conn, $id);
    if (!$visit) {
        return 'That visit was not found.';
    }
    if (!empty($visit['voided_at'])) {
        return 'That visit is already void.';
    }
    if ($todayOnly) {
        $created = !empty($visit['created_at']) ? date('Y-m-d', strtotime($visit['created_at'])) : '';
        if ($created !== date('Y-m-d')) {
            return 'Front desk can void only a visit from today. Ask an administrator for an older one.';
        }
    }
    $actor = money_actor_name();
    $stmt = $conn->prepare("UPDATE clients SET voided_at = NOW(), void_reason = ?, voided_by = ?, status = 'Cancelled' WHERE id = ? AND voided_at IS NULL");
    if (!$stmt) {
        return 'The void could not be saved.';
    }
    $stmt->bind_param('ssi', $reason, $actor, $id);
    if (!$stmt->execute() || $stmt->affected_rows < 1) {
        return 'The void could not be saved.';
    }
    write_money_audit($conn, 'void', 'clients', $id, [
        'amount' => $visit['amount'] ?? null,
        'payment_mode' => $visit['payment_mode'] ?? null,
        'client_code' => $visit['client_code'] ?? null,
    ], ['reason' => $reason]);
    return '';
}

function refund_client_visit(mysqli $conn, int $id, float $amount, string $reason, bool $todayOnly): string
{
    $reason = trim($reason);
    if ($id < 1 || $amount <= 0 || $reason === '') {
        return 'Enter the refund amount and a reason.';
    }
    $visit = fetch_client_visit($conn, $id);
    if (!$visit) {
        return 'That visit was not found.';
    }
    if (!empty($visit['voided_at'])) {
        return 'A voided visit cannot be refunded.';
    }
    if ($todayOnly) {
        $created = !empty($visit['created_at']) ? date('Y-m-d', strtotime($visit['created_at'])) : '';
        if ($created !== date('Y-m-d')) {
            return 'Front desk can refund only a visit from today. Ask an administrator for an older one.';
        }
    }
    $already = (float)($visit['refund_amount'] ?? 0);
    $remaining = max(0, (float)($visit['amount'] ?? 0) - $already);
    if ($amount - $remaining > 0.009) {
        return 'The refund is larger than the amount still on this visit.';
    }
    $actor = money_actor_name();
    $newRefund = round($already + $amount, 2);
    $totalAmount = (float)($visit['amount'] ?? 0);
    $isFullRefund = ($newRefund >= $totalAmount - 0.009);

    if ($isFullRefund && ($visit['status'] ?? '') !== 'Done') {
        $stmt = $conn->prepare("UPDATE clients SET refund_amount = ?, refund_reason = ?, refunded_at = NOW(), refunded_by = ?, status = 'Cancelled' WHERE id = ? AND voided_at IS NULL");
    } else {
        $stmt = $conn->prepare('UPDATE clients SET refund_amount = ?, refund_reason = ?, refunded_at = NOW(), refunded_by = ? WHERE id = ? AND voided_at IS NULL');
    }
    if (!$stmt) {
        return 'The refund could not be saved.';
    }
    $stmt->bind_param('dssi', $newRefund, $reason, $actor, $id);
    if (!$stmt->execute()) {
        return 'The refund could not be saved.';
    }
    write_money_audit($conn, 'refund', 'clients', $id, [
        'amount' => $visit['amount'] ?? null,
        'refund_amount' => $already,
        'client_code' => $visit['client_code'] ?? null,
    ], ['refund_amount' => $newRefund, 'reason' => $reason]);
    return '';
}

function handle_client_money_post(mysqli $conn, bool $todayOnly): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return false;
    }
    if (isset($_POST['void_client'])) {
        $error = void_client_visit($conn, (int)($_POST['client_id'] ?? 0), (string)($_POST['reason'] ?? ''), $todayOnly);
        if ($error === '') {
            $_SESSION['message'] = 'Visit voided. It no longer counts in the till.';
        } else {
            $_SESSION['error'] = $error;
        }
        return true;
    }
    if (isset($_POST['refund_client'])) {
        $error = refund_client_visit(
            $conn,
            (int)($_POST['client_id'] ?? 0),
            (float)($_POST['refund_amount'] ?? 0),
            (string)($_POST['reason'] ?? ''),
            $todayOnly
        );
        if ($error === '') {
            $_SESSION['message'] = 'Refund recorded. Reports now use the amount left after the refund.';
        } else {
            $_SESSION['error'] = $error;
        }
        return true;
    }
    return false;
}

function client_amount_label(array $row): string
{
    if (!empty($row['voided_at'])) {
        return 'Void';
    }
    $net = client_net_amount($row);
    $refund = (float)($row['refund_amount'] ?? 0);
    $label = 'GHS ' . number_format($net, 2);
    if ($refund > 0) {
        $label .= ' · refund ' . number_format($refund, 2);
    }
    return $label;
}

function client_adjustment_forms(array $row, string $view, bool $allow = true): string
{
    if (!empty($row['voided_at'])) {
        $reason = trim((string)($row['void_reason'] ?? ''));
        return '<span class="meta-note">Void' . ($reason !== '' ? ' · ' . htmlspecialchars($reason) : '') . '</span>';
    }
    if (!$allow) {
        return '';
    }
    $id = (int)($row['id'] ?? 0);
    $remaining = max(0, (float)($row['amount'] ?? 0) - (float)($row['refund_amount'] ?? 0));
    $csrf = csrf_field();
    $viewValue = htmlspecialchars($view);
    $html = '<details class="adjust"><summary>Adjust</summary><div class="adjust-body">';
    $html .= '<form method="post" onsubmit="return confirm(\'Void this visit? It will leave the till totals.\');">';
    $html .= $csrf;
    $html .= '<input type="hidden" name="void_client" value="1">';
    $html .= '<input type="hidden" name="client_id" value="' . $id . '">';
    $html .= '<input type="hidden" name="view" value="' . $viewValue . '">';
    $html .= '<input type="text" name="reason" class="form-control" placeholder="Reason for void" required>';
    $html .= '<button type="submit" class="btn btn-danger btn-sm">Void</button></form>';
    if ($remaining > 0) {
        $html .= '<form method="post">';
        $html .= $csrf;
        $html .= '<input type="hidden" name="refund_client" value="1">';
        $html .= '<input type="hidden" name="client_id" value="' . $id . '">';
        $html .= '<input type="hidden" name="view" value="' . $viewValue . '">';
        $html .= '<input type="number" name="refund_amount" class="form-control" min="0.01" max="' . htmlspecialchars((string)$remaining) . '" step="0.01" placeholder="Refund GHS" required>';
        $html .= '<input type="text" name="reason" class="form-control" placeholder="Reason for refund" required>';
        $html .= '<button type="submit" class="btn btn-outline btn-sm">Refund</button></form>';
    }
    $html .= '</div></details>';
    return $html;
}

function till_expected(mysqli $conn, string $date): array
{
    $net = client_net_amount_sql();
    $totals = ['cash' => 0.0, 'momo' => 0.0, 'other' => 0.0, 'visits' => 0];
    $stmt = $conn->prepare("SELECT payment_mode, SUM($net) AS total, SUM(CASE WHEN voided_at IS NULL THEN 1 ELSE 0 END) AS visits
        FROM clients WHERE DATE(created_at) = ? GROUP BY payment_mode");
    if (!$stmt) {
        return $totals;
    }
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result) {
        return $totals;
    }
    while ($row = $result->fetch_assoc()) {
        $mode = strtolower((string)($row['payment_mode'] ?? ''));
        $amount = (float)$row['total'];
        $totals['visits'] += (int)$row['visits'];
        if (str_contains($mode, 'cash')) {
            $totals['cash'] += $amount;
        } elseif (str_contains($mode, 'mobile') || str_contains($mode, 'momo')) {
            $totals['momo'] += $amount;
        } else {
            $totals['other'] += $amount;
        }
    }
    return $totals;
}

function save_till_close(mysqli $conn, string $date, float $cashCounted, float $momoCounted, string $note): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return 'Choose a valid day.';
    }
    if ($cashCounted < 0 || $momoCounted < 0) {
        return 'Counted amounts cannot be negative.';
    }
    $expected = till_expected($conn, $date);
    $actor = money_actor_name();
    $note = trim($note);
    $noteValue = $note === '' ? null : $note;
    $stmt = $conn->prepare("INSERT INTO till_closes
        (close_date, cash_expected, cash_counted, momo_expected, momo_counted, note, closed_by, closed_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            cash_expected = VALUES(cash_expected),
            cash_counted = VALUES(cash_counted),
            momo_expected = VALUES(momo_expected),
            momo_counted = VALUES(momo_counted),
            note = VALUES(note),
            closed_by = VALUES(closed_by),
            closed_at = NOW()");
    if (!$stmt) {
        return 'The close could not be saved.';
    }
    $stmt->bind_param(
        'sddddss',
        $date,
        $expected['cash'],
        $cashCounted,
        $expected['momo'],
        $momoCounted,
        $noteValue,
        $actor
    );
    if (!$stmt->execute()) {
        return 'The close could not be saved.';
    }
    write_money_audit($conn, 'close', 'till', 0, null, [
        'date' => $date,
        'cash_expected' => $expected['cash'],
        'cash_counted' => $cashCounted,
        'momo_expected' => $expected['momo'],
        'momo_counted' => $momoCounted,
    ]);
    return '';
}

function fetch_till_closes(mysqli $conn, int $limit = 14): array
{
    $limit = max(1, min(60, $limit));
    $rows = [];
    $stmt = $conn->prepare("SELECT * FROM till_closes ORDER BY close_date DESC LIMIT ?");
    if ($stmt) {
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
    }
    return $rows;
}

function backup_directory(): string
{
    $fromEnv = getenv('SPA_BACKUP_DIR');
    if (is_string($fromEnv) && $fromEnv !== '') {
        return rtrim($fromEnv, '/');
    }
    return dirname(__DIR__) . '/storage/backups';
}

function sql_dump_value(mysqli $conn, $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    return "'" . $conn->real_escape_string((string)$value) . "'";
}

function create_database_backup(mysqli $conn): string
{
    $dir = backup_directory();
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Backup folder could not be created.');
    }
    $tables = [];
    $list = $conn->query('SHOW TABLES');
    if ($list) {
        while ($row = $list->fetch_row()) {
            $tables[] = (string)$row[0];
        }
    }
    $sql = "-- The Breeze Spa backup " . date('Y-m-d H:i:s') . " UTC\n";
    $sql .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n";
    foreach ($tables as $table) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            continue;
        }
        $create = $conn->query("SHOW CREATE TABLE `$table`");
        $createRow = $create ? $create->fetch_assoc() : null;
        $createSql = $createRow['Create Table'] ?? '';
        if ($createSql === '') {
            continue;
        }
        $sql .= "\nDROP TABLE IF EXISTS `$table`;\n" . $createSql . ";\n";
        $data = $conn->query("SELECT * FROM `$table`");
        if (!$data) {
            continue;
        }
        while ($row = $data->fetch_assoc()) {
            $columns = array_map(static function ($column) {
                return '`' . str_replace('`', '``', $column) . '`';
            }, array_keys($row));
            $values = array_map(static function ($value) use ($conn) {
                return sql_dump_value($conn, $value);
            }, array_values($row));
            $sql .= 'INSERT INTO `' . $table . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n";
        }
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    $file = $dir . '/breeze-' . date('Ymd-His') . '.sql';
    if (file_put_contents($file, $sql) === false) {
        throw new RuntimeException('Backup file could not be written.');
    }
    $old = glob($dir . '/breeze-*.sql') ?: [];
    rsort($old);
    foreach (array_slice($old, 14) as $stale) {
        if (is_file($stale)) {
            unlink($stale);
        }
    }
    return $file;
}

function list_database_backups(): array
{
    $dir = backup_directory();
    $files = glob($dir . '/breeze-*.sql') ?: [];
    rsort($files);
    $rows = [];
    foreach ($files as $file) {
        $rows[] = [
            'name' => basename($file),
            'size' => (int)filesize($file),
            'mtime' => (int)filemtime($file),
        ];
    }
    return $rows;
}

function backup_file_path(string $name): ?string
{
    if (!preg_match('/^breeze-\d{8}-\d{6}\.sql$/', $name)) {
        return null;
    }
    $path = backup_directory() . '/' . $name;
    return is_file($path) ? $path : null;
}
