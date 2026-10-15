<?php
require_once __DIR__ . '/../includes/session.php';
include '../db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$user_role = $_SESSION['user']['role'];

$conn->query("CREATE TABLE IF NOT EXISTS audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  username VARCHAR(255) NULL,
  role VARCHAR(50) NULL,
  action VARCHAR(50) NOT NULL,
  entity VARCHAR(50) NOT NULL,
  entity_id INT NOT NULL,
  before_data TEXT NULL,
  after_data TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action_tabs = ['all' => 'All', 'create' => 'Created', 'update' => 'Updated', 'delete' => 'Deleted'];
$action = strtolower(trim((string)($_GET['action'] ?? 'all')));
if (!isset($action_tabs[$action])) {
    $action = 'all';
}

$user = trim((string)($_GET['user'] ?? ''));
$roleFilter = trim((string)($_GET['role'] ?? ''));
$entity = trim((string)($_GET['entity'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = '';
}
if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = '';
}

$known_roles = ['admin', 'supervisor', 'reception', 'frontdesk', 'massage', 'barbering', 'facials', 'nails', 'salon'];
$entity_labels = [
    'clients' => 'Visit',
    'appointments' => 'Appointment',
    'staff' => 'Staff',
];

$entities = [];
if ($ent_rs = $conn->query("SELECT DISTINCT entity FROM audit_logs WHERE entity IS NOT NULL AND entity != '' ORDER BY entity")) {
    while ($row = $ent_rs->fetch_assoc()) {
        $entities[] = (string)$row['entity'];
    }
}
foreach (array_keys($entity_labels) as $known) {
    if (!in_array($known, $entities, true)) {
        $entities[] = $known;
    }
}

$roles = $known_roles;
if ($role_rs = $conn->query("SELECT DISTINCT role FROM audit_logs WHERE role IS NOT NULL AND role != '' ORDER BY role")) {
    while ($row = $role_rs->fetch_assoc()) {
        $r = (string)$row['role'];
        if (!in_array($r, $roles, true)) {
            $roles[] = $r;
        }
    }
}

function audit_filters(string $user, string $roleFilter, string $entity, string $from, string $to): array
{
    $where = [];
    $params = [];
    $types = '';
    if ($user !== '') {
        $where[] = 'username = ?';
        $params[] = $user;
        $types .= 's';
    }
    if ($roleFilter !== '') {
        $where[] = 'role = ?';
        $params[] = $roleFilter;
        $types .= 's';
    }
    if ($entity !== '') {
        $where[] = 'entity = ?';
        $params[] = $entity;
        $types .= 's';
    }
    if ($from !== '') {
        $where[] = 'created_at >= ?';
        $params[] = $from . ' 00:00:00';
        $types .= 's';
    }
    if ($to !== '') {
        $where[] = 'created_at <= ?';
        $params[] = $to . ' 23:59:59';
        $types .= 's';
    }
    return [$where, $params, $types];
}

function audit_pretty(?string $raw): string
{
    if ($raw === null || $raw === '') {
        return '';
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $raw;
    }
    unset($decoded['password']);
    return (string)json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

[$base_where, $base_params, $base_types] = audit_filters($user, $roleFilter, $entity, $from, $to);

$counts = ['all' => 0, 'create' => 0, 'update' => 0, 'delete' => 0];
$count_sql = 'SELECT action, COUNT(*) AS c FROM audit_logs';
if ($base_where) {
    $count_sql .= ' WHERE ' . implode(' AND ', $base_where);
}
$count_sql .= ' GROUP BY action';
$count_stmt = $conn->prepare($count_sql);
if ($base_params) {
    $count_stmt->bind_param($base_types, ...$base_params);
}
$count_stmt->execute();
$count_res = $count_stmt->get_result();
while ($row = $count_res->fetch_assoc()) {
    $act = strtolower((string)($row['action'] ?? ''));
    $c = (int)$row['c'];
    $counts['all'] += $c;
    if (isset($counts[$act])) {
        $counts[$act] = $c;
    }
}

$where = $base_where;
$params = $base_params;
$types = $base_types;
if ($action !== 'all') {
    $where[] = 'action = ?';
    $params[] = $action;
    $types .= 's';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $conn->prepare("SELECT * FROM audit_logs $whereSql ORDER BY created_at DESC");
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
}

$qs = function (array $extra = []) use ($action, $user, $roleFilter, $entity, $from, $to) {
    $params = array_filter([
        'action' => $action,
        'user' => $user,
        'role' => $roleFilter,
        'entity' => $entity,
        'from' => $from,
        'to' => $to,
    ], static fn($v) => $v !== '' && $v !== null);
    $params = array_merge($params, $extra);
    return 'audit_logs.php?' . http_build_query($params);
};

$has_filters = ($user !== '' || $roleFilter !== '' || $entity !== '' || $from !== '' || $to !== '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>System Audit Logs | The Breeze Spa</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
  <style>
    :root {
        --brand-blue: #0ea5e9; --brand-dark: #0f172a; --bg-body: #f1f5f9; --bg-card: #ffffff;
        --text-main: #1e293b; --text-muted: #475569; --border-color: #e2e8f0;
        --sidebar-width: 250px; --topbar-height: 60px;
        --danger-color: #dc2626; --success-color: #10b981;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    body { background-color: var(--bg-body); color: var(--text-main); display: flex; min-height: 100vh; overflow-x: hidden; font-size: 15px; }

    .sidebar { width: var(--sidebar-width); background-color: var(--brand-dark); color: #ffffff; position: fixed; height: 100vh; left: 0; top: 0; display: flex; flex-direction: column; z-index: 100; border-right: 1px solid #1e293b; }
    .sidebar-header { padding: 20px; border-bottom: 1px solid #1e293b; display: flex; align-items: center; justify-content: center; }
    .sidebar-logo { width: 90%; max-height: 80px; object-fit: contain; }
    .user-profile { padding: 20px; border-bottom: 1px solid #1e293b; font-size: 14px; }
    .user-profile h4 { color: #ffffff; font-weight: 600; margin-bottom: 4px; }
    .user-profile p { color: #94a3b8; font-size: 12px; text-transform: uppercase; }
    .sidebar-menu { padding: 20px 0; flex: 1; }
    .menu-item { display: flex; align-items: center; padding: 12px 24px; color: #cbd5e1; text-decoration: none; font-size: 14px; font-weight: 500; border-left: 3px solid transparent; transition: all 0.2s; }
    .menu-item i { width: 24px; font-size: 16px; margin-right: 10px; color: #64748b; }
    .menu-item:hover, .menu-item.active { background-color: #1e293b; color: #ffffff; border-left-color: var(--brand-blue); }
    .menu-item:hover i, .menu-item.active i { color: var(--brand-blue); }
    .logout-btn { display: flex; align-items: center; padding: 15px 24px; color: #ef4444; text-decoration: none; font-size: 14px; font-weight: 600; border-top: 1px solid #1e293b; background-color: #0b1221; }
    .logout-btn i { margin-right: 10px; }
    .logout-btn:hover { background-color: #ef4444; color: #ffffff; }

    .main-content { flex: 1; margin-left: var(--sidebar-width); display: flex; flex-direction: column; min-height: 100vh; width: calc(100% - var(--sidebar-width)); }
    .topbar { height: var(--topbar-height); background-color: var(--bg-card); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 30px; position: sticky; top: 0; z-index: 90; }
    .topbar h2 { font-size: 22px; font-weight: 600; color: var(--text-main); }
    .topbar .date-stamp { font-size: 15px; color: var(--text-muted); font-weight: 500; }
    .content-wrapper { padding: 30px; flex: 1; width: 100%; }

    .flat-card { background: var(--bg-card); border: 1px solid var(--border-color); padding: 30px; margin-bottom: 30px; }
    .flat-card-header { margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
    .flat-card-header h3 { font-size: 18px; font-weight: 600; color: var(--text-main); text-transform: uppercase; letter-spacing: 0.5px; margin: 0; }

    .form-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; }
    .form-group { display: flex; flex-direction: column; min-width: 140px; flex: 1; }
    .form-group label { font-size: 11px; font-weight: 700; color: var(--text-muted); margin-bottom: 4px; text-transform: uppercase; }
    .form-control { padding: 10px 14px; border: 1px solid var(--border-color); border-radius: 2px; font-size: 14px; background: #f8fafc; outline: none; width: 100%; }
    .form-control:focus { border-color: var(--brand-blue); background: #ffffff; }

    .diary-tabs { display: flex; flex-wrap: wrap; border: 1px solid var(--border-color); }
    .diary-tab { padding: 8px 14px; font-size: 12px; font-weight: 700; background: #fff; color: var(--text-muted); text-decoration: none; }
    .diary-tab:hover:not(.active) { background: #f1f5f9; color: var(--text-main); }
    .diary-tab.active { background: var(--brand-dark); color: #fff; }

    .btn { padding: 10px 18px; font-size: 14px; font-weight: 600; border: none; border-radius: 2px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; }
    .btn-primary { background: var(--brand-blue); color: #fff; }
    .btn-primary:hover { background: #0284c7; }
    .btn-outline { background: #ffffff; border: 1px solid var(--border-color); color: var(--text-main); }

    .table-container { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 14px 16px; text-align: left; border-bottom: 1px solid var(--border-color); vertical-align: top; font-size: 14px; }
    th { background: #f8fafc; font-weight: 600; color: var(--text-muted); text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px; }

    .badge { padding: 5px 10px; font-size: 11px; font-weight: 700; border-radius: 2px; text-transform: uppercase; border: 1px solid; display: inline-block; }
    .badge-create { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
    .badge-update { background: #e0f2fe; color: #0369a1; border-color: #bae6fd; }
    .badge-delete { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
    .badge-role { background: #f1f5f9; color: #475569; border-color: #cbd5e1; }

    .json { white-space: pre-wrap; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12px; background: #f8fafc; border: 1px solid var(--border-color); padding: 10px; display: none; margin-top: 8px; word-break: break-word; }
    .toggle { cursor: pointer; color: var(--brand-blue); font-size: 13px; font-weight: 700; background: none; border: none; padding: 0; }

    @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .main-content { margin-left: 0; width: 100%; } }
  </style>
</head>
<body>

  <aside class="sidebar">
        <div class="sidebar-header">
            <img src="../assets/images/The-Breeze-1.png" alt="The Breeze Spa Logo" class="sidebar-logo">
        </div>
        <div class="user-profile">
            <h4><?= htmlspecialchars($_SESSION['user']['name']) ?></h4>
            <p><?= htmlspecialchars($user_role) ?></p>
        </div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item"><i class="fas fa-square-poll-vertical"></i> Dashboard Overview</a>
            <a href="staff_management.php" class="menu-item"><i class="fas fa-users-rectangle"></i> Staff Roster</a>
            <a href="password_requests.php" class="menu-item"><i class="fas fa-key"></i> Password Requests</a>
            <a href="services_management.php" class="menu-item"><i class="fas fa-list-ul"></i> Services & Pricing</a>
            <a href="massage_management.php" class="menu-item"><i class="fas fa-spa"></i> Massage Categories</a>
            <a href="appointments.php" class="menu-item"><i class="fas fa-calendar-days"></i> Appointments</a>
            <a href="reports.php" class="menu-item"><i class="fas fa-file-invoice-dollar"></i> Reports</a>
            <a href="till.php" class="menu-item"><i class="fas fa-cash-register"></i> Close of Day</a>
            <a href="clients.php" class="menu-item"><i class="fas fa-address-book"></i> Client Directory</a>
            
            <a href="backup.php" class="menu-item"><i class="fas fa-database"></i> Database Backup</a>
            <a href="audit_logs.php" class="menu-item active"><i class="fas fa-server"></i> System Audit Logs</a>
        </nav>
        <a href="../logout.php" class="logout-btn"><i class="fas fa-arrow-right-from-bracket"></i> Terminate Session</a>
    </aside>

  <main class="main-content">
    <header class="topbar">
        <h2>System Audit Logs</h2>
        <div class="date-stamp">System Time: <?= date('Y-m-d H:i') ?></div>
    </header>

    <div class="content-wrapper">
      <div class="flat-card">
        <div class="flat-card-header"><h3>Find events</h3></div>
        <form method="get" class="form-row">
          <input type="hidden" name="action" value="<?= htmlspecialchars($action) ?>">
          <div class="form-group">
            <label>Username</label>
            <input type="text" name="user" placeholder="Who did it" value="<?= htmlspecialchars($user) ?>" class="form-control">
          </div>
          <div class="form-group">
            <label>Role</label>
            <select name="role" class="form-control">
              <option value="">Any role</option>
              <?php foreach ($roles as $r): ?>
              <option value="<?= htmlspecialchars($r) ?>" <?= $roleFilter === $r ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Record</label>
            <select name="entity" class="form-control">
              <option value="">Any record</option>
              <?php foreach ($entities as $ent): ?>
              <option value="<?= htmlspecialchars($ent) ?>" <?= $entity === $ent ? 'selected' : '' ?>><?= htmlspecialchars($entity_labels[$ent] ?? $ent) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>From</label>
            <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="form-control">
          </div>
          <div class="form-group">
            <label>To</label>
            <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="form-control">
          </div>
          <button type="submit" class="btn btn-primary">Filter</button>
          <?php if ($has_filters): ?>
            <a href="audit_logs.php?action=<?= urlencode($action) ?>" class="btn btn-outline">Clear</a>
          <?php endif; ?>
        </form>
      </div>

      <div class="flat-card">
        <div class="flat-card-header">
          <h3><?= htmlspecialchars($action_tabs[$action]) ?></h3>
          <div class="diary-tabs">
            <?php foreach ($action_tabs as $key => $label): ?>
            <a class="diary-tab<?= $action === $key ? ' active' : '' ?>" href="<?= htmlspecialchars($qs(['action' => $key])) ?>"><?= $label ?> (<?= (int)$counts[$key] ?>)</a>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="table-container">
          <table>
            <thead>
              <tr>
                <th>When</th>
                <th>Who</th>
                <th>Action</th>
                <th>Record</th>
                <th>Changes</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r):
                  $act = strtolower((string)$r['action']);
                  $badge = 'badge-update';
                  if ($act === 'create') $badge = 'badge-create';
                  if ($act === 'delete') $badge = 'badge-delete';
                  $ent = (string)($r['entity'] ?? '');
                  $ent_label = $entity_labels[$ent] ?? $ent;
                  $before = audit_pretty($r['before_data'] ?? null);
                  $after = audit_pretty($r['after_data'] ?? null);
              ?>
                <tr>
                  <td style="white-space: nowrap; color: var(--text-muted); font-weight: 600;"><?= date('D, M j', strtotime($r['created_at'])) ?><div style="font-size: 13px; font-weight: 500; margin-top: 3px;"><?= date('h:i A', strtotime($r['created_at'])) ?></div></td>
                  <td>
                    <strong><?= htmlspecialchars($r['username'] ?? 'Unknown') ?></strong><br>
                    <span class="badge badge-role"><?= htmlspecialchars($r['role'] ?? 'Unknown') ?></span>
                  </td>
                  <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($action_tabs[$act] ?? $r['action']) ?></span></td>
                  <td><?= htmlspecialchars($ent_label) ?> #<?= (int)$r['entity_id'] ?></td>
                  <td>
                    <?php if ($before !== '' || $after !== ''): ?>
                      <button type="button" class="toggle" onclick="toggleJson(this)">View details</button>
                      <div class="json"><?php
                        if ($before !== '') echo "Before:\n" . htmlspecialchars($before);
                        if ($before !== '' && $after !== '') echo "\n\n";
                        if ($after !== '') echo "After:\n" . htmlspecialchars($after);
                      ?></div>
                    <?php else: ?>
                      <span style="color: var(--text-muted); font-size: 13px;">No snapshot</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$rows): ?>
                <tr>
                  <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 40px;">No audit events in this view.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </main>

  <script>
    function toggleJson(el){ var j = el.nextElementSibling; if(!j) return; j.style.display = j.style.display==='block'?'none':'block'; }
  </script>
</body>
</html>
