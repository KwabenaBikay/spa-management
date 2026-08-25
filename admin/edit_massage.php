<?php
session_start();
include '../db.php';

// Restrict access to admin and supervisor
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin', 'supervisor'])) {
    header("Location: ../login.php");
    exit;
}

// Check if massage ID is provided
if (!isset($_GET['id'])) {
    header("Location: massage_management.php");
    exit;
}

$massage_id = $_GET['id'];

// Fetch massage details
$stmt = $conn->prepare("SELECT * FROM massage_types WHERE id = ?");
$stmt->bind_param("i", $massage_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: massage_management.php");
    exit;
}

$massage = $result->fetch_assoc();

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST['name']);
    $price_60 = !empty($_POST['price_60']) ? (float)$_POST['price_60'] : 0.0;
    $price_90 = !empty($_POST['price_90']) ? (float)$_POST['price_90'] : 0.0;
    $price_120 = !empty($_POST['price_120']) ? (float)$_POST['price_120'] : 0.0;
    $category = trim($_POST['category']);

    $stmt = $conn->prepare("UPDATE massage_types SET name = ?, price_60 = ?, price_90 = ?, price_120 = ?, category = ? WHERE id = ?");
    $stmt->bind_param("sdddsi", $name, $price_60, $price_90, $price_120, $category, $massage_id);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = "Massage type catalog updated successfully.";
        header("Location: massage_management.php");
        exit;
    } else {
        $_SESSION['error'] = "System error: Unable to update record.";
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
    <title>Edit Massage Type | The Breeze Spa</title>
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
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }

        body { background-color: var(--bg-body); color: var(--text-main); display: flex; min-height: 100vh; font-size: 16px; }

        /* Sidebar & Topbar (Inherited Styles) */
        .sidebar { width: var(--sidebar-width); background-color: var(--brand-dark); color: #ffffff; position: fixed; height: 100vh; left: 0; top: 0; display: flex; flex-direction: column; z-index: 100; border-right: 1px solid #1e293b; }
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
        .user-profile h4 { color: #ffffff; font-weight: 600; margin-bottom: 4px; }
        .user-profile p { color: #94a3b8; font-size: 12px; }
        .sidebar-menu { padding: 20px 0; flex: 1; }
        .menu-item { display: flex; align-items: center; padding: 12px 24px; color: #cbd5e1; text-decoration: none; font-size: 14px; font-weight: 500; border-left: 3px solid transparent; transition: all 0.2s; }
        .menu-item i { width: 24px; font-size: 16px; margin-right: 10px; color: #64748b; }
        .menu-item:hover, .menu-item.active { background-color: #1e293b; color: #ffffff; border-left-color: var(--brand-blue); }
        .logout-btn { display: flex; align-items: center; padding: 15px 24px; color: #ef4444; text-decoration: none; font-size: 14px; font-weight: 600; border-top: 1px solid #1e293b; background-color: #0b1221; }
        .main-content { flex: 1; margin-left: var(--sidebar-width); display: flex; flex-direction: column; min-height: 100vh; }
        .topbar { height: var(--topbar-height); background-color: var(--bg-card); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 30px; position: sticky; top: 0; z-index: 90; }
        .topbar h2 { font-size: 22px; font-weight: 600; color: var(--text-main); display: flex; align-items: center; gap: 15px; }
        
        /* Centered Form Layout */
        .content-wrapper { padding: 40px 30px; flex: 1; width: 100%; max-width: 600px; margin: 0 auto; }
        .flat-card { background-color: var(--bg-card); border: 1px solid var(--border-color); border-radius: 0; padding: 40px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
        .flat-card-header { border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 30px; }
        .flat-card-header h3 { font-size: 20px; font-weight: 600; color: var(--text-main); text-transform: uppercase; letter-spacing: 0.5px; }
        
        .form-group { margin-bottom: 25px; }
        .form-group label { font-size: 14px; font-weight: 600; color: var(--text-muted); margin-bottom: 8px; text-transform: uppercase; display: block; }
        .form-control { padding: 14px 16px; border: 1px solid var(--border-color); border-radius: 2px; font-size: 16px; color: var(--text-main); background-color: #f8fafc; outline: none; width: 100%; }
        .form-control:focus { border-color: var(--brand-blue); background-color: #ffffff; }
        
        .btn { padding: 14px 28px; font-size: 16px; font-weight: 600; border: none; border-radius: 2px; cursor: pointer; transition: background-color 0.2s; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
        .btn-primary { background-color: var(--brand-blue); color: #ffffff; }
        .btn-primary:hover { background-color: #0284c7; }
        .btn-outline { background-color: transparent; border: 1px solid var(--border-color); color: var(--text-main); }
        .btn-outline:hover { background-color: #f1f5f9; }
        .action-group { display: flex; gap: 15px; margin-top: 30px; }
        
        .alert { padding: 16px 20px; margin-bottom: 24px; border-left: 4px solid; background-color: var(--bg-card); display: flex; align-items: center; gap: 10px; }
        .alert-error { border-left-color: var(--danger-color); color: #991b1b; }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-header"><img src="../assets/images/The-Breeze-1.png" alt="The Breeze Spa Logo" class="sidebar-logo"></div>
        <div class="user-profile"><h4><?= htmlspecialchars($_SESSION['user']['name']) ?></h4><p><?= htmlspecialchars($_SESSION['user']['username']) ?></p></div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item"><i class="fas fa-square-poll-vertical"></i> Dashboard</a>
            <a href="staff_management.php" class="menu-item"><i class="fas fa-users-rectangle"></i> Staff Roster</a>
            <a href="services_management.php" class="menu-item"><i class="fas fa-list-ul"></i> Services & Pricing</a>
            <a href="massage_management.php" class="menu-item active"><i class="fas fa-spa"></i> Massage Categories</a>
            <a href="appointments.php" class="menu-item"><i class="fas fa-calendar-days"></i> Appointments</a>
            <a href="reports.php" class="menu-item"><i class="fas fa-file-invoice-dollar"></i> Financial Reports</a>
            <a href="clients.php" class="menu-item"><i class="fas fa-address-book"></i> Client Directory</a>
            <a href="audit_logs.php" class="menu-item"><i class="fas fa-server"></i> System Audit Logs</a>
        </nav>
        <a href="../logout.php" class="logout-btn"><i class="fas fa-arrow-right-from-bracket"></i> Terminate Session</a>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <h2>
                <a href="massage_management.php" class="btn btn-outline btn-sm" style="margin-right: 15px; padding: 6px 14px;"><i class="fas fa-arrow-left"></i> Back</a>
                Modify Massage Catalog
            </h2>
        </header>

        <div class="content-wrapper">
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="flat-card">
                <div class="flat-card-header"><h3>Massage Configuration</h3></div>
                
                <form method="post">
                    <div class="form-group">
                        <label>Massage Name</label>
                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($massage['name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category" class="form-control" required>
                            <option value="Full Body Massage" <?= $massage['category'] === 'Full Body Massage' ? 'selected' : '' ?>>Full Body Massage</option>
                            <option value="Express Massage" <?= $massage['category'] === 'Express Massage' ? 'selected' : '' ?>>Express Massage</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Price for 60 Min (GHS)</label>
                        <input type="number" name="price_60" class="form-control" step="0.01" value="<?= htmlspecialchars($massage['price_60']) ?>">
                    </div>
                    <div class="form-group">
                        <label>Price for 90 Min (GHS)</label>
                        <input type="number" name="price_90" class="form-control" step="0.01" value="<?= htmlspecialchars($massage['price_90']) ?>">
                    </div>
                    <div class="form-group">
                        <label>Price for 120 Min (GHS)</label>
                        <input type="number" name="price_120" class="form-control" step="0.01" value="<?= htmlspecialchars($massage['price_120']) ?>">
                    </div>

                    <div class="action-group">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                        <a href="massage_management.php" class="btn btn-outline">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </main>
</body>
</html>