<?php
session_start();
include '../db.php';

// RBAC: Allow Admin and Supervisor
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin', 'supervisor'])) {
    header("Location: login.php");
    exit;
}

$user_role = $_SESSION['user']['role'];

// Check if service ID is provided
if (!isset($_GET['id'])) {
    header("Location: services_management.php");
    exit;
}

$service_id = (int)$_GET['id'];

// Fetch service details
$stmt = $conn->prepare("SELECT * FROM services WHERE id = ?");
$stmt->bind_param("i", $service_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: services_management.php");
    exit;
}

$service = $result->fetch_assoc();

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST['service_name']);
    $section = $_POST['section'];
    $price = $_POST['price'];
    $duration = isset($_POST['duration']) ? trim($_POST['duration']) : '';

    $stmt = $conn->prepare("UPDATE services SET service_name = ?, section = ?, price = ?, duration = ? WHERE id = ?");
    $stmt->bind_param("ssdsi", $name, $section, $price, $duration, $service_id);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = "Service updated successfully.";
        header("Location: services_management.php");
        exit;
    } else {
        $_SESSION['error'] = "System error: Unable to update service.";
    }
}

$error = $_SESSION['error'] ?? '';
unset($_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Edit Service | The Breeze Spa</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
  <style>
    :root {
        --brand-blue: #0ea5e9; --brand-dark: #0f172a; --bg-body: #f1f5f9; --bg-card: #ffffff;
        --text-main: #1e293b; --text-muted: #475569; --border-color: #e2e8f0;
        --sidebar-width: 250px; --topbar-height: 60px;
        --danger-color: #dc2626; --success-color: #10b981;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    body { background-color: var(--bg-body); color: var(--text-main); display: flex; min-height: 100vh; overflow-x: hidden; }

    /* FLAT SIDEBAR */
    .sidebar { width: var(--sidebar-width); background-color: var(--brand-dark); color: #ffffff; position: fixed; height: 100vh; left: 0; top: 0; display: flex; flex-direction: column; z-index: 100; border-right: 1px solid #1e293b; }
    .sidebar-header { padding: 20px; border-bottom: 1px solid #1e293b; display: flex; align-items: center; justify-content: center; }
    .sidebar-logo { width: 90%; max-height: 80px; object-fit: contain; }
    .user-profile { padding: 20px; border-bottom: 1px solid #1e293b; font-size: 14px; }
    .user-profile h4 { color: #ffffff; font-weight: 600; margin-bottom: 4px; }
    .user-profile p { color: #94a3b8; font-size: 12px; }
    
    .sidebar-menu { padding: 20px 0; flex: 1; }
    .menu-item { display: flex; align-items: center; padding: 12px 24px; color: #cbd5e1; text-decoration: none; font-size: 14px; font-weight: 500; border-left: 3px solid transparent; transition: all 0.2s; }
    .menu-item i { width: 24px; font-size: 16px; margin-right: 10px; color: #64748b; }
    .menu-item:hover, .menu-item.active { background-color: #1e293b; color: #ffffff; border-left-color: var(--brand-blue); }
    .menu-item:hover i, .menu-item.active i { color: var(--brand-blue); }
    
    .logout-btn { display: flex; align-items: center; padding: 15px 24px; color: #ef4444; text-decoration: none; font-size: 14px; font-weight: 600; border-top: 1px solid #1e293b; background-color: #0b1221; }
    .logout-btn i { margin-right: 10px; }
    .logout-btn:hover { background-color: #ef4444; color: #ffffff; }

    /* MAIN CONTENT */
    .main-content { flex: 1; margin-left: var(--sidebar-width); display: flex; flex-direction: column; min-height: 100vh; }
    .topbar { height: var(--topbar-height); background-color: var(--bg-card); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 30px; position: sticky; top: 0; z-index: 90; }
    .topbar h2 { font-size: 18px; font-weight: 600; color: var(--text-main); display: flex; align-items: center; gap: 15px; }
    .content-wrapper { padding: 30px; flex: 1; max-width: 600px; margin: 0 auto; width: 100%;}

    .alert { padding: 16px 20px; border-radius: 2px; margin-bottom: 25px; font-size: 15px; font-weight: 500; border-left: 4px solid; background: var(--bg-card); box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .alert-error { color: #991b1b; border-left-color: var(--danger-color); background: #fef2f2; }

    .flat-card { background: var(--bg-card); border: 1px solid var(--border-color); padding: 30px; margin-bottom: 30px; border-radius: 0; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
    .flat-card-header { border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 25px; }
    .flat-card-header h3 { font-size: 16px; font-weight: 600; color: var(--text-main); text-transform: uppercase; letter-spacing: 0.5px; }

    .form-group { display: flex; flex-direction: column; margin-bottom: 20px; }
    .form-group label { font-size: 13px; font-weight: 600; color: var(--text-muted); margin-bottom: 8px; text-transform: uppercase; }
    .form-control { padding: 12px 14px; border: 1px solid var(--border-color); border-radius: 2px; font-size: 15px; background: #f8fafc; outline: none; transition: border-color 0.2s; width: 100%; }
    .form-control:focus { border-color: var(--brand-blue); background: #ffffff; }
    
    .btn { padding: 12px 24px; font-size: 15px; font-weight: 600; border: none; border-radius: 2px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; transition: background-color 0.2s; }
    .btn-primary { background: var(--brand-blue); color: #fff; }
    .btn-primary:hover { background: #0284c7; }
    .btn-outline { background: transparent; border: 1px solid var(--border-color); color: var(--text-main); }
    .btn-outline:hover { background: #f1f5f9; }
    
    .action-group { display: flex; gap: 15px; margin-top: 25px; }

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
            <p><?= htmlspecialchars($_SESSION['user']['username']) ?></p>
        </div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item"><i class="fas fa-square-poll-vertical"></i> Dashboard Overview</a>
            
            <a href="staff_management.php" class="menu-item"><i class="fas fa-users-rectangle"></i> Staff Roster</a>

            <a href="services_management.php" class="menu-item active"><i class="fas fa-list-ul"></i> Services & Pricing</a>
            <a href="massage_management.php" class="menu-item"><i class="fas fa-spa"></i> Massage Categories</a>
            <a href="appointments.php" class="menu-item"><i class="fas fa-calendar-days"></i> Appointments</a>
            <a href="reports.php" class="menu-item"><i class="fas fa-file-invoice-dollar"></i> Financial Reports</a>
            <a href="clients.php" class="menu-item"><i class="fas fa-address-book"></i> Client Directory</a>
            
            <?php if ($user_role === 'admin'): ?>
                <a href="audit_logs.php" class="menu-item"><i class="fas fa-server"></i> System Audit Logs</a>
            <?php endif; ?>
        </nav>
        <a href="../logout.php" class="logout-btn"><i class="fas fa-arrow-right-from-bracket"></i> Terminate Session</a>
    </aside>

  <main class="main-content">
    <header class="topbar">
        <h2>
            <a href="services_management.php" class="btn btn-outline btn-sm" style="margin-right: 15px;"><i class="fas fa-arrow-left"></i> Back</a>
            Modify Service Detail
        </h2>
    </header>

    <div class="content-wrapper">
      <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <div class="flat-card">
        <div class="flat-card-header"><h3>Service Configuration</h3></div>
        
        <form method="post">
            <div class="form-group">
                <label>Service Name</label>
                <input type="text" name="service_name" class="form-control" value="<?= htmlspecialchars($service['service_name']) ?>" required>
            </div>
            
            <div class="form-group">
                <label>Section</label>
                <select name="section" id="section" class="form-control" required>
                    <option value="Massage" <?= $service['section'] === 'Massage' ? 'selected' : '' ?>>Massage</option>
                    <option value="Pedicure & Manicure" <?= $service['section'] === 'Pedicure & Manicure' ? 'selected' : '' ?>>Pedicure & Manicure</option>
                    <option value="Barbering" <?= $service['section'] === 'Barbering' ? 'selected' : '' ?>>Barbering</option>
                    <option value="Hair Salon" <?= $service['section'] === 'Hair Salon' ? 'selected' : '' ?>>Hair Salon</option>
                    <option value="Facials" <?= $service['section'] === 'Facials' ? 'selected' : '' ?>>Facials</option>
                    <option value="Make-Up services" <?= $service['section'] === 'Make-Up services' ? 'selected' : '' ?>>Make-Up services</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Price (GHS)</label>
                <input type="number" name="price" class="form-control" step="0.01" value="<?= htmlspecialchars($service['price']) ?>" required>
            </div>
            
            <div class="form-group">
                <label>Duration (Optional)</label>
                <input type="text" name="duration" class="form-control" value="<?= htmlspecialchars($service['duration']) ?>" placeholder="e.g. 30 mins (Optional)">
            </div>
            
            <div class="action-group">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                <a href="services_management.php" class="btn btn-outline">Cancel</a>
            </div>
        </form>
      </div>
    </div>
  </main>
</body>
</html>