<?php
session_start();
include '../db.php';

if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['frontdesk', 'reception'])) {
    header('Location: ../login.php');
    exit;
}



// Handle search
$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $like = "%$search%";
    $sql = "SELECT * FROM clients WHERE name LIKE ? OR phone LIKE ? OR client_code LIKE ? ORDER BY created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $like, $like, $like);
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
    <title>Client Directory | Front Office</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-blue: #0ea5e9;
            --brand-dark: #0f172a;
            --bg-body: #f1f5f9;
            --bg-card: #ffffff;
            --text-main: #1e293b;
            --text-muted: #475569;
            --border-color: #e2e8f0;
            --sidebar-width: 250px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        body { background-color: var(--bg-body); color: var(--text-main); display: flex; min-height: 100vh; font-size: 15px; }

        /* Sidebar */
        .sidebar { width: var(--sidebar-width); background-color: var(--brand-dark); color: #ffffff; position: fixed; height: 100vh; display: flex; flex-direction: column; z-index: 100; }
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid #1e293b;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .sidebar-logo {
            width: 90%;
            max-height: 80px;
            object-fit: contain;
        }
        .user-profile { padding: 20px; border-bottom: 1px solid #1e293b; font-size: 14px; }
        .user-profile h4 { color: #ffffff; font-size: 15px; font-weight: 600; margin-bottom: 4px; }
        .user-profile p { color: #94a3b8; font-size: 12px; display: flex; align-items: center; gap: 6px; }
        .user-profile p i { font-size: 10px; color: #10b981; }

        .sidebar-menu { padding: 20px 0; flex: 1; }
        .menu-item { display: flex; align-items: center; padding: 14px 24px; color: #cbd5e1; text-decoration: none; font-size: 15px; font-weight: 500; border-left: 4px solid transparent; transition: all 0.2s; }
        .menu-item i { width: 24px; font-size: 16px; margin-right: 10px; color: #64748b; }
        .menu-item:hover, .menu-item.active { background-color: #1e293b; color: #ffffff; border-left-color: var(--brand-blue); }
        .menu-item:hover i, .menu-item.active i { color: var(--brand-blue); }
        .logout-btn { display: flex; align-items: center; padding: 16px 24px; color: #ef4444; text-decoration: none; font-size: 15px; font-weight: 600; border-top: 1px solid #1e293b; background-color: #0b1221; }
        .logout-btn i { margin-right: 10px; }
        .logout-btn:hover { background-color: #ef4444; color: #ffffff; }

        /* Main Content */
        .main-content { flex: 1; margin-left: var(--sidebar-width); padding: 30px; }
        .flat-card { background: var(--bg-card); border: 1px solid var(--border-color); padding: 25px; margin-bottom: 20px; }
        
        .toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .search-form { display: flex; gap: 10px; }
        .form-control { padding: 10px 14px; border: 1px solid var(--border-color); border-radius: 2px; width: 280px; }
        
        .btn { padding: 10px 20px; font-weight: 600; border: none; cursor: pointer; border-radius: 2px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary { background: var(--brand-blue); color: #fff; }
        .btn-danger { background: #fef2f2; color: #dc2626; border: 1px solid #fca5a5; }
        .btn-outline { background: #f1f5f9; color: var(--text-main); }

        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; text-align: left; padding: 14px; border-bottom: 2px solid var(--border-color); text-transform: uppercase; font-size: 13px; color: var(--text-muted); }
        td { padding: 14px; border-bottom: 1px solid var(--border-color); }
        
        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; font-size: 12px; font-weight: 600; border-radius: 4px; text-transform: uppercase; border: 1px solid; }
        .badge-pending { background-color: #fef3c7; color: #d97706; border-color: #fde68a; }
        .badge-inprogress { background-color: #e0f2fe; color: #0284c7; border-color: #bae6fd; }
        .badge-done { background-color: #dcfce7; color: #16a34a; border-color: #bbf7d0; }
        .badge-dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; }
        .badge-pending .badge-dot { background-color: #d97706; }
        .badge-inprogress .badge-dot { background-color: #0284c7; animation: pulse 1.5s infinite; }
        .badge-done .badge-dot { background-color: #16a34a; }
        @keyframes pulse {
            0% { transform: scale(0.9); opacity: 1; }
            50% { transform: scale(1.2); opacity: 0.5; }
            100% { transform: scale(0.9); opacity: 1; }
        }

        .alert { padding: 15px; margin-bottom: 20px; border-radius: 2px; }
        .alert-success { background: #d1fae5; color: #065f46; }
        .alert-error { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-header">
            <img src="../assets/images/The-Breeze-1.png" alt="The Breeze Spa Logo" class="sidebar-logo">
        </div>
        <div class="user-profile">
            <h4><?= htmlspecialchars($_SESSION['user']['name']) ?></h4>
            <p><i class="fas fa-circle"></i> Front Office Ops</p>
        </div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item"><i class="fas fa-desktop"></i> Station Dashboard</a>
            <a href="add_client.php" class="menu-item"><i class="fas fa-user-plus"></i> Register Client</a>
            <a href="appointments.php" class="menu-item"><i class="fas fa-calendar-days"></i> Appointments</a>
            <a href="view_clients.php" class="menu-item active"><i class="fas fa-address-book"></i> Client Directory</a>
        </nav>
        <a href="../logout.php" class="logout-btn"><i class="fas fa-arrow-right-from-bracket"></i> End Shift</a>
    </aside>

    <main class="main-content">
        <a href="dashboard.php" style="color:var(--text-muted); text-decoration:none; margin-bottom:20px; display:inline-block;"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        
        <?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

        <div class="flat-card">
            <div class="toolbar">
                <h2>Client Records</h2>
                <form method="get" class="search-form">
                    <input type="text" name="search" placeholder="Search by name or phone..." value="<?= htmlspecialchars($search) ?>" class="form-control">
                    <button type="submit" class="btn btn-primary">Search</button>
                    <?php if ($search !== ''): ?><a href="view_clients.php" class="btn btn-outline">Clear</a><?php endif; ?>
                </form>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Service</th>
                            <th>Amount</th>
                            <th>Staff</th>
                            <th>Code</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($row['name']) ?></strong></td>
                            <td><?= htmlspecialchars($row['phone']) ?></td>
                            <td><?= htmlspecialchars($row['service_type']) ?></td>
                            <td>GHS <?= number_format($row['amount'], 2) ?></td>
                            <td><?= htmlspecialchars($row['staff_name']) ?></td>
                            <td><code><?= htmlspecialchars($row['client_code']) ?></code></td>
                            <td>
                                <?php
                                $status = $row['status'] ?? 'Pending';
                                $badge_class = 'badge-pending';
                                if ($status === 'In Progress') {
                                    $badge_class = 'badge-inprogress';
                                } elseif ($status === 'Done') {
                                    $badge_class = 'badge-done';
                                }
                                ?>
                                <span class="badge <?= $badge_class ?>">
                                    <span class="badge-dot"></span>
                                    <?= htmlspecialchars($status) ?>
                                </span>
                            </td>
                            <td>
                                <a href="edit_client.php?id=<?= $row['id'] ?>" class="btn btn-outline" style="padding: 4px 8px;">Edit</a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</body>
</html>