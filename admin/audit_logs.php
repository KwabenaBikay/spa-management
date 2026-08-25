<?php
session_start();
include '../db.php';
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') { header('Location: ../login.php'); exit; }

$user_role = $_SESSION['user']['role'];

// Ensure table exists
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

$action = $_GET['action'] ?? '';
$user = $_GET['user'] ?? '';
$roleFilter = $_GET['role'] ?? '';
$entity = $_GET['entity'] ?? '';
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20; $offset = ($page - 1) * $limit;

$where = [];
$params = []; $types = '';
if ($action !== '') { $where[] = 'action = ?'; $params[] = $action; $types .= 's'; }
if ($user !== '') { $where[] = 'username = ?'; $params[] = $user; $types .= 's'; }
if ($entity !== '') { $where[] = 'entity = ?'; $params[] = $entity; $types .= 's'; }
if ($roleFilter !== '') { $where[] = 'role = ?'; $params[] = $roleFilter; $types .= 's'; }
if ($from !== '') { $where[] = 'created_at >= ?'; $params[] = $from . ' 00:00:00'; $types .= 's'; }
if ($to !== '') { $where[] = 'created_at <= ?'; $params[] = $to . ' 23:59:59'; $types .= 's'; }
$whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

// Count
$countSql = "SELECT COUNT(*) AS c FROM audit_logs $whereSql";
$countStmt = $conn->prepare($countSql);
if (!empty($params)) { $countStmt->bind_param($types, ...$params); }
$countStmt->execute();
$total = (int)$countStmt->get_result()->fetch_assoc()['c'];
$pages = max(1, (int)ceil($total / $limit));

// Fetch
$sql = "SELECT * FROM audit_logs $whereSql ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
$stmt = $conn->prepare($sql);
if (!empty($params)) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$rows = $stmt->get_result();
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
        --text-main: #1e293b; --text-muted: #64748b; --border-color: #e2e8f0;
        --sidebar-width: 250px; --topbar-height: 60px;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
    body { background-color: var(--bg-body); color: var(--text-main); display: flex; min-height: 100vh; overflow-x: hidden; font-size: 15px; }

    .sidebar { width: var(--sidebar-width); background-color: var(--brand-dark); color: #ffffff; position: fixed; height: 100vh; left: 0; top: 0; display: flex; flex-direction: column; z-index: 100; border-right: 1px solid #1e293b; }
    .sidebar-header { padding: 20px; border-bottom: 1px solid #1e293b; display: flex; align-items: center; gap: 15px; }
    .sidebar-logo { width: 90%; max-height: 80px; object-fit: contain; }
    .user-profile { padding: 20px; border-bottom: 1px solid #1e293b; }
    .user-profile h4 { color: #ffffff; font-size: 15px; font-weight: 600; margin-bottom: 4px; }
    .user-profile p { color: #94a3b8; font-size: 12px; text-transform: uppercase; }
    .sidebar-menu { padding: 20px 0; flex: 1; }
    .menu-item { display: flex; align-items: center; padding: 12px 24px; color: #cbd5e1; text-decoration: none; font-size: 14px; font-weight: 500; border-left: 3px solid transparent; transition: all 0.2s; }
    .menu-item i { width: 24px; font-size: 16px; margin-right: 10px; color: #64748b; }
    .menu-item:hover, .menu-item.active { background-color: #1e293b; color: #ffffff; border-left-color: var(--brand-blue); }
    .menu-item:hover i, .menu-item.active i { color: var(--brand-blue); }
    .logout-btn { display: flex; align-items: center; padding: 15px 24px; color: #ef4444; text-decoration: none; font-size: 14px; font-weight: 600; border-top: 1px solid #1e293b; background-color: #0b1221; }
    .logout-btn i { margin-right: 10px; }
    .logout-btn:hover { background-color: #ef4444; color: #ffffff; }

    .main-content { flex: 1; margin-left: var(--sidebar-width); display: flex; flex-direction: column; min-height: 100vh; }
    .topbar { height: var(--topbar-height); background-color: var(--bg-card); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 30px; position: sticky; top: 0; z-index: 90; }
    .topbar h2 { font-size: 18px; font-weight: 600; color: var(--text-main); }
    .content-wrapper { padding: 30px; flex: 1; max-width: 1200px; margin: 0 auto; width: 100%;}

    .flat-card { background: var(--bg-card); border: 1px solid var(--border-color); padding: 30px; margin-bottom: 30px; border-radius: 0; }
    .flat-card-header { border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 20px; }
    .flat-card-header h3 { font-size: 16px; font-weight: 600; color: var(--text-main); text-transform: uppercase; letter-spacing: 0.5px; margin: 0; }

    .form-row { display: flex; flex-wrap: wrap; gap: 15px; align-items: center; margin-bottom: 20px; }
    .form-control { flex: 1; min-width: 150px; padding: 10px 14px; border: 1px solid var(--border-color); border-radius: 2px; font-size: 14px; background: #f8fafc; outline: none; transition: all 0.2s; }
    .form-control:focus { border-color: var(--brand-blue); background: #ffffff; }
    
    .btn { padding: 10px 18px; font-size: 14px; font-weight: 600; border: none; border-radius: 2px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; transition: all 0.2s; }
    .btn-primary { background: var(--brand-blue); color: #fff; }
    .btn-primary:hover { background: #0284c7; }
    .btn-outline { background: #f8fafc; border: 1px solid var(--border-color); color: var(--text-main); }

    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { padding: 14px; text-align: left; border-bottom: 1px solid var(--border-color); vertical-align: top; }
    th { background: #f8fafc; font-weight: 600; color: var(--text-muted); text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px; }

    .json { white-space:pre-wrap; font-family: ui-monospace, Menlo, Consolas, monospace; font-size:12px; background:#fafafa; border:1px solid #eee; padding:8px; border-radius:4px; display:none; margin-top:8px; word-break: break-all; }
    .toggle { cursor:pointer; color:var(--brand-blue); text-decoration:underline; font-size: 13px; font-weight: 500; }
    
    .pagination { display: flex; justify-content: flex-end; align-items: center; margin-top: 25px; gap: 5px; }
    .page-link { padding: 6px 12px; background: var(--bg-card); border: 1px solid var(--border-color); color: var(--text-main); text-decoration: none; border-radius: 2px; font-size: 13px; font-weight: 500; transition: all 0.2s; }
    .page-link:hover { border-color: var(--brand-blue); color: var(--brand-blue); }
    
    @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .main-content { margin-left: 0; } }
  </style>
</head>
<body>

  <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <img src="../assets/images/The-Breeze-1.png" alt="The Breeze Spa Logo" class="sidebar-logo">
        </div>
        <div class="user-profile">
            <h4><?= htmlspecialchars($_SESSION['user']['name']) ?></h4>
            <p><i class="fas fa-shield-alt" style="margin-right: 5px; font-size: 10px;"></i> <?= htmlspecialchars($user_role) ?></p>
        </div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item"><i class="fas fa-square-poll-vertical"></i> Dashboard Overview</a>
            <a href="staff_management.php" class="menu-item"><i class="fas fa-users-rectangle"></i> Staff Roster</a>
            <a href="services_management.php" class="menu-item"><i class="fas fa-list-ul"></i> Services & Pricing</a>
            
            <?php if ($user_role === 'admin'): ?>
            <a href="massage_management.php" class="menu-item"><i class="fas fa-spa"></i> Massage Categories</a>
            <?php endif; ?>
            
            <a href="appointments.php" class="menu-item"><i class="fas fa-calendar-days"></i> Appointments</a>
            <a href="reports.php" class="menu-item"><i class="fas fa-file-invoice-dollar"></i> Financial Reports</a>
            <a href="clients.php" class="menu-item"><i class="fas fa-address-book"></i> Client Directory</a>
            
            <?php if ($user_role === 'admin'): ?>
            <a href="audit_logs.php" class="menu-item active"><i class="fas fa-server"></i> System Audit Logs</a>
            <?php endif; ?>
        </nav>
        <a href="../logout.php" class="logout-btn"><i class="fas fa-arrow-right-from-bracket"></i> Terminate Session</a>
    </aside>

  <main class="main-content">
    <header class="topbar">
        <h2>System Activity Logs</h2>
    </header>

    <div class="content-wrapper">
      <div class="flat-card">
        <div class="flat-card-header"><h3>Filters</h3></div>
        <form class="filters form-row" method="get">
          <select name="action" class="form-control">
            <option value="">Select Action</option>
            <?php foreach (['update','delete','create'] as $opt) { $sel = $action===$opt?'selected':''; echo "<option value=\"$opt\" $sel>$opt</option>"; } ?>
          </select>
          <input type="text" name="user" placeholder="Username" value="<?= htmlspecialchars($user) ?>" class="form-control" />
          <select name="role" class="form-control">
            <option value="">Select Role</option>
            <?php foreach (['admin','reception','therapist','manager','massage','salon','barbering','facials','nails'] as $r) { $sel = $roleFilter===$r?'selected':''; echo "<option value=\"$r\" $sel>$r</option>"; } ?>
          </select>
          <input type="text" name="entity" placeholder="Entity (e.g. clients)" value="<?= htmlspecialchars($entity) ?>" class="form-control" />
          <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="form-control" />
          <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="form-control" />
          <button type="submit" class="btn btn-primary">Filter</button>
          <?php if ($action || $user || $roleFilter || $entity || $from || $to): ?>
            <a href="audit_logs.php" class="btn btn-outline">Clear</a>
          <?php endif; ?>
        </form>
      </div>

      <div class="flat-card">
        <div class="flat-card-header"><h3>Activity History</h3></div>
        <div style="overflow-x: auto;">
          <table>
            <thead>
              <tr>
                <th>Date & Time</th>
                <th>User</th>
                <th>Role</th>
                <th>Action</th>
                <th>Target</th>
                <th>Details</th>
                <th>Changes</th>
              </tr>
            </thead>
            <tbody>
              <?php while ($r = $rows->fetch_assoc()): ?>
                <tr>
                  <td style="white-space: nowrap; color: var(--text-muted); font-size: 13px; font-weight: 500;"><?= date('M j, Y g:i A', strtotime($r['created_at'])) ?></td>
                  <td><strong><?= htmlspecialchars($r['username'] ?? 'Unknown') ?></strong></td>
                  <td><span class="badge badge-therapist" style="font-size: 11px;"><?= htmlspecialchars($r['role'] ?? 'Unknown') ?></span></td>
                  <td><span class="badge badge-manager" style="font-size: 11px;"><?= htmlspecialchars($r['action']) ?></span></td>
                  <td><?= htmlspecialchars($r['entity']) ?> #<?= (int)$r['entity_id'] ?></td>
                  <td>
                    <?php
                    $actionDesc = '';
                    if ($r['action'] === 'create') {
                      $actionDesc = 'Created ' . $r['entity'];
                    } elseif ($r['action'] === 'update') {
                      $actionDesc = 'Modified ' . $r['entity'];
                    } elseif ($r['action'] === 'delete') {
                      $actionDesc = 'Deleted ' . $r['entity'];
                    }
                    echo htmlspecialchars($actionDesc);
                    ?>
                  </td>
                  <td>
                    <?php if (!empty($r['before_data']) || !empty($r['after_data'])): ?>
                      <span class="toggle" onclick="toggleJson(this)">View Details</span>
                      <div class="json">
                        <?php if (!empty($r['before_data'])): ?>
                          <strong style="color:var(--brand-blue);">Before:</strong><br><?= htmlspecialchars($r['before_data']) ?><br><br>
                        <?php endif; ?>
                        <?php if (!empty($r['after_data'])): ?>
                          <strong style="color:#10b981;">After:</strong><br><?= htmlspecialchars($r['after_data']) ?>
                        <?php endif; ?>
                      </div>
                    <?php else: ?>
                      <span style="color:#999; font-size: 13px;">No changes</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endwhile; ?>
              <?php if ($total === 0): ?>
                <tr>
                  <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 30px;">No audit records found.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        
        <?php if ($pages > 1): ?>
        <div class="pagination">
          <span class="page-info">Showing page <?= $page ?> of <?= $pages ?> (<?= $total ?> total logs)</span>
          <?php if ($page > 1): $p=$page-1; ?>
            <a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$p])) ?>">← Prev</a>
          <?php endif; ?>
          <?php if ($page < $pages): $n=$page+1; ?>
            <a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$n])) ?>">Next →</a>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </main>
  
  <script>
    function toggleJson(el){ var j = el.nextElementSibling; if(!j) return; j.style.display = j.style.display==='block'?'none':'block'; }
  </script>
</body>
</html>
