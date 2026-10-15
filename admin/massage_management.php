<?php
require_once __DIR__ . '/../includes/session.php';
include '../db.php';

// Restrict access to admin and supervisor
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin', 'supervisor'])) {
    header("Location: login.php");
    exit;
}

$user_role = $_SESSION['user']['role'];
$massage_categories = ['Full Body Massage', 'Express Massage'];

// Add new massage type
if (isset($_POST['add_massage'])) {
    $name = trim($_POST['name'] ?? '');
    $price_60 = !empty($_POST['price_60']) ? (float)$_POST['price_60'] : 0.0;
    $price_90 = !empty($_POST['price_90']) ? (float)$_POST['price_90'] : 0.0;
    $price_120 = !empty($_POST['price_120']) ? (float)$_POST['price_120'] : 0.0;
    $category = trim($_POST['category'] ?? '');

    if ($name === '' || !in_array($category, $massage_categories, true)) {
        $_SESSION['error'] = 'Please enter a name and choose Full Body or Express.';
        $fallback = trim((string)($_GET['category'] ?? 'Full Body Massage'));
        if (!in_array($fallback, $massage_categories, true)) {
            $fallback = 'Full Body Massage';
        }
        header('Location: massage_management.php?category=' . urlencode($fallback));
        exit;
    }

    $insert_massage = "INSERT INTO massage_types (name, price_60, price_90, price_120, category) VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($insert_massage);
    $stmt->bind_param("sddds", $name, $price_60, $price_90, $price_120, $category);

    if ($stmt->execute()) {
        $_SESSION['message'] = "Massage type cataloged successfully.";
    } else {
        $_SESSION['error'] = "System error: Unable to add massage type.";
    }
    header('Location: massage_management.php?category=' . urlencode($category));
    exit;
}

// Delete massage type
if (isset($_POST['delete_massage'])) {
    $id = (int)$_POST['massage_id'];
    $return_category = trim((string)($_POST['return_category'] ?? ''));
    if (!in_array($return_category, $massage_categories, true)) {
        $lookup = $conn->prepare("SELECT category FROM massage_types WHERE id = ?");
        $lookup->bind_param("i", $id);
        $lookup->execute();
        $cat_row = $lookup->get_result()->fetch_assoc();
        $return_category = (string)($cat_row['category'] ?? 'Full Body Massage');
    }
    if (!in_array($return_category, $massage_categories, true)) {
        $return_category = 'Full Body Massage';
    }
    $stmt = $conn->prepare("DELETE FROM massage_types WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['message'] = "Massage type removed from catalog.";
    } else {
        $_SESSION['error'] = "System error: Unable to delete massage type.";
    }
    header('Location: massage_management.php?category=' . urlencode($return_category));
    exit;
}

$category_view = trim((string)($_GET['category'] ?? 'Full Body Massage'));
if (!in_array($category_view, $massage_categories, true)) {
    $category_view = 'Full Body Massage';
}

$counts = ['Full Body Massage' => 0, 'Express Massage' => 0];
if ($count_rs = $conn->query("SELECT category, COUNT(*) AS c FROM massage_types GROUP BY category")) {
    while ($row = $count_rs->fetch_assoc()) {
        $cat = (string)($row['category'] ?? '');
        if (isset($counts[$cat])) {
            $counts[$cat] = (int)$row['c'];
        }
    }
}

$massage_rows = [];
$stmt = $conn->prepare("SELECT * FROM massage_types WHERE category = ? ORDER BY name");
$stmt->bind_param('s', $category_view);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $massage_rows[] = $row;
    }
}

$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Massage Management | The Breeze Spa</title>
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
            --topbar-height: 60px;
            --danger-color: #dc2626;
            --success-color: #10b981;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: var(--bg-body);
            color: var(--text-main);
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
            font-size: 16px;
        }

        /* -------------------------
           FLAT SIDEBAR
        -------------------------- */
        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--brand-dark);
            color: #ffffff;
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
            display: flex;
            flex-direction: column;
            z-index: 100;
            border-right: 1px solid #1e293b;
        }

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

        .user-profile {
            padding: 20px;
            border-bottom: 1px solid #1e293b;
            font-size: 14px;
        }

        .user-profile h4 {
            color: #ffffff;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .user-profile p {
            color: #94a3b8;
            font-size: 12px;
        }

        .sidebar-menu {
            padding: 20px 0;
            flex: 1;
        }

        .menu-item {
            display: flex;
            align-items: center;
            padding: 12px 24px;
            color: #cbd5e1;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            border-left: 3px solid transparent;
            transition: all 0.2s;
        }

        .menu-item i {
            width: 24px;
            font-size: 16px;
            margin-right: 10px;
            color: #64748b;
        }

        .menu-item:hover, .menu-item.active {
            background-color: #1e293b;
            color: #ffffff;
            border-left-color: var(--brand-blue);
        }

        .menu-item:hover i, .menu-item.active i {
            color: var(--brand-blue);
        }

        .logout-btn {
            display: flex;
            align-items: center;
            padding: 15px 24px;
            color: #ef4444;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            border-top: 1px solid #1e293b;
            background-color: #0b1221;
        }

        .logout-btn i {
            margin-right: 10px;
        }

        .logout-btn:hover {
            background-color: #ef4444;
            color: #ffffff;
        }

        /* -------------------------
           MAIN CONTENT 
        -------------------------- */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .topbar {
            height: var(--topbar-height);
            background-color: var(--bg-card);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
            position: sticky;
            top: 0;
            z-index: 90;
        }

        .topbar h2 {
            font-size: 22px;
            font-weight: 600;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .topbar .date-stamp {
            font-size: 15px;
            color: var(--text-muted);
            font-weight: 500;
        }

        .content-wrapper {
            padding: 30px;
            flex: 1;
        }

        /* -------------------------
           ALERTS
        -------------------------- */
        .alert {
            padding: 16px 20px;
            margin-bottom: 24px;
            font-size: 16px;
            font-weight: 500;
            border-left: 4px solid;
            background-color: var(--bg-card);
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            border-left-color: var(--success-color);
            color: #065f46;
        }

        .alert-error {
            border-left-color: var(--danger-color);
            color: #991b1b;
        }

        /* -------------------------
           FLAT CARDS & FORMS
        -------------------------- */
        .flat-card {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 0;
            padding: 30px;
            margin-bottom: 30px;
        }

        .flat-card-header {
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 15px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .flat-card-header h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-main);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 8px;
            text-transform: uppercase;
        }

        .form-control {
            padding: 12px 14px;
            border: 1px solid var(--border-color);
            border-radius: 2px;
            font-size: 16px;
            color: var(--text-main);
            background-color: #f8fafc;
            outline: none;
            transition: border-color 0.2s;
            width: 100%;
        }

        .form-control:focus {
            border-color: var(--brand-blue);
            background-color: #ffffff;
        }

        /* Buttons */
        .btn {
            padding: 12px 24px;
            font-size: 16px;
            font-weight: 600;
            border: none;
            border-radius: 2px;
            cursor: pointer;
            transition: background-color 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background-color: var(--brand-blue);
            color: #ffffff;
        }

        .btn-primary:hover { background-color: #0284c7; }

        .btn-danger {
            background-color: #fef2f2;
            color: var(--danger-color);
            border: 1px solid #fca5a5;
        }

        .btn-danger:hover {
            background-color: var(--danger-color);
            color: #ffffff;
        }

        .btn-sm {
            padding: 8px 14px;
            font-size: 14px;
        }

        .action-cell {
            display: flex;
            gap: 10px;
        }

        /* Data Table */
        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 16px 20px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            font-size: 16px;
        }

        th {
            background-color: #f8fafc;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            font-size: 14px;
            letter-spacing: 0.5px;
        }

        tr:hover td {
            background-color: #f8fafc;
        }

        .price-text {
            font-family: monospace;
            font-weight: 600;
            color: #047857;
            font-size: 16px;
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            .main-content {
                margin-left: 0;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        .btn-outline { background: #f8fafc; border: 1px solid var(--border-color); color: var(--text-main); }
        .btn-outline:hover { background: #e2e8f0; }
        .diary-tabs { display: flex; flex-wrap: wrap; border: 1px solid var(--border-color); }
        .diary-tab { padding: 8px 14px; font-size: 12px; font-weight: 700; background: #fff; color: var(--text-muted); text-decoration: none; }
        .diary-tab:hover:not(.active) { background: #f1f5f9; color: var(--text-main); }
        .diary-tab.active { background: var(--brand-dark); color: #fff; }
    </style>
</head>
<body>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <img src="../assets/images/The-Breeze-1.png" alt="The Breeze Spa Logo" class="sidebar-logo">
        </div>
        <div class="user-profile">
            <h4><?= htmlspecialchars($_SESSION['user']['name']) ?></h4>
            <p><?= htmlspecialchars($_SESSION['user']['username']) ?></p>
        </div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item">
                <i class="fas fa-square-poll-vertical"></i> Dashboard Overview
            </a>
            <a href="staff_management.php" class="menu-item">
                <i class="fas fa-users-rectangle"></i> Staff Roster
            </a>
            <a href="password_requests.php" class="menu-item"><i class="fas fa-key"></i> Password Requests</a>
            <a href="services_management.php" class="menu-item">
                <i class="fas fa-list-ul"></i> Services & Pricing
            </a>
            <a href="massage_management.php" class="menu-item active">
                <i class="fas fa-spa"></i> Massage Categories
            </a>
            <a href="appointments.php" class="menu-item">
                <i class="fas fa-calendar-days"></i> Appointments
            </a>
            <a href="reports.php" class="menu-item">
                <i class="fas fa-file-invoice-dollar"></i> Reports
            </a>
            <a href="clients.php" class="menu-item">
                <i class="fas fa-address-book"></i> Client Directory
            </a>
            <?php if ($user_role === 'admin'): ?>
            
            <a href="backup.php" class="menu-item"><i class="fas fa-database"></i> Database Backup</a>
            <a href="audit_logs.php" class="menu-item">
                <i class="fas fa-server"></i> System Audit Logs
            </a>
            <?php endif; ?>
        </nav>
        <a href="../logout.php" class="logout-btn">
            <i class="fas fa-arrow-right-from-bracket"></i> Terminate Session
        </a>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <h2>Massage Category Catalog</h2>
            <div class="date-stamp">System Time: <?= date('Y-m-d H:i') ?></div>
        </header>

        <div class="content-wrapper">
            
            <?php if ($message): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle" style="margin-right:8px;"></i> <?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-triangle" style="margin-right:8px;"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="flat-card">
                <div class="flat-card-header">
                    <h3>Register Massage Type</h3>
                    <span style="font-size:12px; color:var(--text-muted);">60 / 90 / 120 prices are used on Register Client. Leave unused durations blank.</span>
                </div>
                <form method="post">
<?= csrf_field() ?>
                    <div class="form-row" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));">
                        <div class="form-group">
                            <label>Massage Name</label>
                            <input type="text" name="name" placeholder="e.g., Deep Tissue" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Category</label>
                            <select name="category" class="form-control" required>
                                <option value="Full Body Massage" <?= $category_view === 'Full Body Massage' ? 'selected' : '' ?>>Full Body Massage</option>
                                <option value="Express Massage" <?= $category_view === 'Express Massage' ? 'selected' : '' ?>>Express Massage</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Price (60 Min)</label>
                            <input type="number" name="price_60" placeholder="0.00" class="form-control" step="0.01">
                        </div>
                        <div class="form-group">
                            <label>Price (90 Min)</label>
                            <input type="number" name="price_90" placeholder="0.00" class="form-control" step="0.01">
                        </div>
                        <div class="form-group">
                            <label>Price (120 Min)</label>
                            <input type="number" name="price_120" placeholder="0.00" class="form-control" step="0.01">
                        </div>
                        <div class="form-group">
                            <button type="submit" name="add_massage" class="btn btn-primary" style="height: 46px;">
                                <i class="fas fa-plus"></i> Add Entry
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="flat-card">
                <div class="flat-card-header">
                    <h3><?= htmlspecialchars($category_view) ?></h3>
                    <div class="diary-tabs">
                        <?php foreach ($massage_categories as $cat): ?>
                        <a class="diary-tab<?= $category_view === $cat ? ' active' : '' ?>" href="massage_management.php?category=<?= urlencode($cat) ?>"><?= $cat === 'Full Body Massage' ? 'Full Body' : 'Express' ?> (<?= (int)$counts[$cat] ?>)</a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Category</th>
                                <th>Price (60 Min)</th>
                                <th>Price (90 Min)</th>
                                <th>Price (120 Min)</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($massage_rows as $massage): ?>
                            <tr>
                                <td><i class="fas fa-spa" style="color:var(--text-muted); margin-right:10px;"></i> <strong><?= htmlspecialchars($massage['name']) ?></strong></td>
                                <td><span style="font-size: 14px; background: #e2e8f0; color: #475569; padding: 4px 8px; border-radius: 4px; font-weight: 500;"><?= htmlspecialchars($massage['category']) ?></span></td>
                                <td class="price-text"><?= $massage['price_60'] > 0 ? number_format($massage['price_60'], 2) : '-' ?></td>
                                <td class="price-text"><?= $massage['price_90'] > 0 ? number_format($massage['price_90'], 2) : '-' ?></td>
                                <td class="price-text"><?= $massage['price_120'] > 0 ? number_format($massage['price_120'], 2) : '-' ?></td>
                                <td>
                                    <div class="action-cell">
                                        <a href="edit_massage.php?id=<?= (int)$massage['id'] ?>&category=<?= urlencode($category_view) ?>" class="btn btn-outline btn-sm">Edit</a>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Warning: This will remove this massage type. Continue?');">
<?= csrf_field() ?>
                                            <input type="hidden" name="massage_id" value="<?= (int)$massage['id'] ?>">
                                            <input type="hidden" name="return_category" value="<?= htmlspecialchars($category_view) ?>">
                                            <button type="submit" name="delete_massage" class="btn btn-danger btn-sm">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$massage_rows): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 40px; font-size: 16px;">No massage types in this category.</td>
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