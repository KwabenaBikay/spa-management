<?php
session_start();
include '../db.php';

// RBAC: Allow Admin and Supervisor
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin', 'supervisor'])) {
    header('Location: ../login.php');
    exit;
}

$user_role = $_SESSION['user']['role'];

// Ensure audit_logs table exists
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

// Handle Delete Action (ADMIN ONLY)
if (isset($_GET['delete']) && $user_role === 'admin') {
    $id = (int)$_GET['delete'];
    
    // Fetch before snapshot for audit
    $before = null;
    if ($stmtSel = $conn->prepare("SELECT * FROM clients WHERE id = ?")) { 
        $stmtSel->bind_param('i', $id); 
        $stmtSel->execute(); 
        $beforeRes = $stmtSel->get_result(); 
        $before = $beforeRes ? $beforeRes->fetch_assoc() : null; 
    }
    
    $stmt = $conn->prepare("DELETE FROM clients WHERE id = ?");
    $stmt->bind_param('i', $id);
    
    if ($stmt->execute()) {
        // Audit log
        $userId = (int)($_SESSION['user']['id'] ?? 0);
        $username = $_SESSION['user']['username'] ?? '';
        $role = $_SESSION['user']['role'] ?? '';
        $beforeJson = $before ? json_encode($before) : null;
        $afterJson = null;
        
        if ($log = $conn->prepare("INSERT INTO audit_logs (user_id, username, role, action, entity, entity_id, before_data, after_data, created_at) VALUES (?,?,?,?,?,?,?,?, NOW())")) {
            $action = 'delete'; $entity = 'clients';
            $log->bind_param('issssiss', $userId, $username, $role, $action, $entity, $id, $beforeJson, $afterJson);
            $log->execute();
        }
        $_SESSION['message'] = 'Client record deleted successfully.';
    } else {
        $_SESSION['error'] = 'System error: Unable to delete client record.';
    }
    
    header('Location: clients.php');
    exit;
}

// Handle Search & Listing
$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $like = "%$search%";
    $sql = "SELECT * FROM clients WHERE name LIKE ? OR phone LIKE ? OR client_code LIKE ? ORDER BY created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sss', $like, $like, $like);
} else {
    $stmt = $conn->prepare("SELECT * FROM clients ORDER BY created_at DESC");
}
$stmt->execute();
$result = $stmt->get_result();

$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Directory | The Breeze Spa</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-blue: #0ea5e9; --brand-dark: #0f172a; --bg-body: #f1f5f9; --bg-card: #ffffff;
            --text-main: #1e293b; --text-muted: #475569; --border-color: #e2e8f0;
            --sidebar-width: 250px; --topbar-height: 60px;
            --danger-color: #dc2626; --success-color: #10b981;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background-color: var(--bg-body); color: var(--text-main); display: flex; min-height: 100vh; font-size: 16px; overflow-x: hidden; }

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
        
        .content-wrapper { padding: 30px; flex: 1; }

        .alert { padding: 16px 20px; margin-bottom: 24px; font-size: 15px; font-weight: 500; border-left: 4px solid; background-color: var(--bg-card); box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; align-items: center; gap: 10px; }
        .alert-success { border-left-color: var(--success-color); color: #065f46; }
        .alert-error { border-left-color: var(--danger-color); color: #991b1b; }

        .flat-card { background-color: var(--bg-card); border: 1px solid var(--border-color); border-radius: 0; padding: 0; margin-bottom: 30px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        
        .toolbar { padding: 20px 30px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background-color: #f8fafc; }
        .toolbar h3 { font-size: 18px; font-weight: 600; color: var(--text-main); text-transform: uppercase; letter-spacing: 0.5px; margin: 0; }
        
        .search-form { display: flex; gap: 10px; }
        .search-form input { padding: 10px 16px; border: 1px solid var(--border-color); border-radius: 2px; font-size: 15px; width: 300px; outline: none; }
        .search-form input:focus { border-color: var(--brand-blue); }
        
        .btn { padding: 10px 20px; font-size: 15px; font-weight: 600; border: none; border-radius: 2px; cursor: pointer; transition: background-color 0.2s; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; }
        .btn-primary { background-color: var(--brand-blue); color: #ffffff; }
        .btn-primary:hover { background-color: #0284c7; }
        .btn-outline { background-color: #ffffff; border: 1px solid var(--border-color); color: var(--text-main); }
        .btn-outline:hover { background-color: #f1f5f9; }
        .btn-danger { background-color: #fef2f2; color: var(--danger-color); border: 1px solid #fca5a5; }
        .btn-danger:hover { background-color: var(--danger-color); color: #ffffff; }
        .btn-sm { padding: 6px 12px; font-size: 13px; }

        .table-container { overflow-x: auto; padding: 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 16px 30px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 15px; white-space: nowrap; }
        th { background-color: #ffffff; font-weight: 600; color: var(--text-muted); text-transform: uppercase; font-size: 13px; letter-spacing: 0.5px; position: sticky; top: 0; }
        tr:hover td { background-color: #f8fafc; }
        
        .amount-text { font-family: monospace; font-weight: 600; color: #047857; font-size: 15px; }
        .date-text { color: var(--text-muted); font-size: 14px; }
        
        .badge { padding: 4px 10px; font-size: 12px; font-weight: 600; border-radius: 2px; text-transform: uppercase; border: 1px solid; display: inline-block; letter-spacing: 0.5px; }
        .badge-gray { background-color: #f1f5f9; color: #475569; border-color: #cbd5e1; }
        .badge-blue { background-color: #e0f2fe; color: #0369a1; border-color: #bae6fd; }

        .action-cell { display: flex; gap: 8px; }

        @media (max-width: 1024px) {
            .toolbar { flex-direction: column; align-items: flex-start; gap: 15px; }
            .search-form { width: 100%; }
            .search-form input { flex: 1; width: auto; }
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); transition: transform 0.3s ease; }
            .main-content { margin-left: 0; width: 100%; }
        }
    </style>
</head>
<body>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header"><img src="../assets/images/The-Breeze-1.png" alt="The Breeze Spa Logo" class="sidebar-logo"></div>
        <div class="user-profile">
            <h4><?= htmlspecialchars($_SESSION['user']['name']) ?></h4>
            <p><?= htmlspecialchars($user_role) ?></p>
        </div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item"><i class="fas fa-square-poll-vertical"></i> Dashboard Overview</a>
            <a href="staff_management.php" class="menu-item"><i class="fas fa-users-rectangle"></i> Staff Roster</a>
            <a href="services_management.php" class="menu-item"><i class="fas fa-list-ul"></i> Services & Pricing</a>
            
            <?php if ($user_role === 'admin' || $user_role === 'supervisor'): ?>
            <a href="massage_management.php" class="menu-item"><i class="fas fa-spa"></i> Massage Categories</a>
            <?php endif; ?>
            
            <a href="appointments.php" class="menu-item"><i class="fas fa-calendar-days"></i> Appointments</a>
            <a href="reports.php" class="menu-item"><i class="fas fa-file-invoice-dollar"></i> Business Analytics</a>
            <a href="clients.php" class="menu-item active"><i class="fas fa-address-book"></i> Client Directory</a>
            
            <?php if ($user_role === 'admin'): ?>
            <a href="audit_logs.php" class="menu-item"><i class="fas fa-server"></i> System Audit Logs</a>
            <?php endif; ?>
        </nav>
        <a href="../logout.php" class="logout-btn"><i class="fas fa-arrow-right-from-bracket"></i> Terminate Session</a>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <h2>Client Registry & Ledger</h2>
            <div class="date-stamp">System Time: <?= date('Y-m-d H:i') ?></div>
        </header>

        <div class="content-wrapper">
            
            <?php if ($message): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="flat-card">
                
                <div class="toolbar">
                    <h3>Master Client List</h3>
                    <form method="get" class="search-form">
                        <input type="text" name="search" placeholder="Search by name, phone, or code..." value="<?= htmlspecialchars($search) ?>">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Query</button>
                        <?php if ($search !== ''): ?>
                            <a href="clients.php" class="btn btn-outline" title="Clear Search"><i class="fas fa-times"></i></a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Client Code</th>
                                <th>Timestamp</th>
                                <th>Client Name</th>
                                <th>Contact Number</th>
                                <th>Assigned Service</th>
                                <th>Section</th>
                                <th>Amount (GHS)</th>
                                <th>Attending Staff</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <?php if(!empty($row['client_code'])): ?>
                                        <span class="badge badge-gray"><?= htmlspecialchars($row['client_code']) ?></span>
                                    <?php else: ?>
                                        <span class="date-text">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td class="date-text"><?= date('M d, Y H:i', strtotime($row['created_at'])) ?></td>
                                <td><strong><?= htmlspecialchars($row['name'] ?? '') ?></strong></td>
                                <td><?= htmlspecialchars($row['phone'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['service_type'] ?? '') ?></td>
                                <td>
                                    <?php if(!empty($row['section'])): ?>
                                        <span class="badge badge-blue"><?= htmlspecialchars($row['section']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="amount-text"><?= number_format((float)($row['amount'] ?? 0), 2) ?></td>
                                <td><i class="fas fa-user-circle" style="color:var(--text-muted); margin-right:5px;"></i> <?= htmlspecialchars($row['staff_name'] ?? '') ?></td>
                                <td>
                                    <div class="action-cell">
                                        <a href="edit_client.php?id=<?= (int)$row['id'] ?>" class="btn btn-outline btn-sm" title="Edit Record"><i class="fas fa-edit"></i></a>
                                        
                                        <?php if ($user_role === 'admin'): ?>
                                        <a href="?delete=<?= (int)$row['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Warning: This action permanently deletes the client record. Proceed?')" title="Delete Record"><i class="fas fa-trash-alt"></i></a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            <?php if ($result->num_rows === 0): ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 40px; color: var(--text-muted);">
                                    <i class="fas fa-search" style="font-size: 24px; margin-bottom: 10px; display: block;"></i>
                                    No client records found matching your criteria.
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </main>
</body>
</html>