<?php
session_start();
include '../db.php';

// RBAC: Allow BOTH Admin and Supervisor
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

if (!isset($_GET['id'])) {
  header("Location: clients.php");
  exit;
}
$id = (int)$_GET['id'];

$stmt = $conn->prepare('SELECT * FROM clients WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
  header("Location: clients.php");
  exit;
}
$client = $res->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim($_POST['name']);
  $phone = preg_replace('/\D+/', '', $_POST['phone']);
  $service_type = trim($_POST['service_type']);
  $amount = $_POST['amount'];
  $duration = trim($_POST['duration']);
  $payment_mode = $_POST['payment_mode'];
  $massage_type = trim($_POST['massage_type']);
  $staff_name = trim($_POST['staff_name']);
  $section = $_POST['section'];

  if (strlen($phone) !== 10) {
    $error = 'Phone number must be exactly 10 digits.';
  }

  if (empty($error)) {
    // Snapshot before
    $beforeJson = json_encode($client);
    $upd = $conn->prepare('UPDATE clients SET name=?, phone=?, service_type=?, amount=?, duration=?, payment_mode=?, massage_type=?, staff_name=?, section=? WHERE id=?');
    $upd->bind_param('sssssssssi', $name, $phone, $service_type, $amount, $duration, $payment_mode, $massage_type, $staff_name, $section, $id);
    if ($upd->execute()) {
      // Audit log
      $after = [
        'id' => $id,
        'name' => $name,
        'phone' => $phone,
        'service_type' => $service_type,
        'amount' => $amount,
        'duration' => $duration,
        'payment_mode' => $payment_mode,
        'massage_type' => $massage_type,
        'staff_name' => $staff_name,
        'section' => $section
      ];
      $afterJson = json_encode($after);
      $userId = (int)($_SESSION['user']['id'] ?? 0);
      $username = $_SESSION['user']['username'] ?? '';
      $role = $_SESSION['user']['role'] ?? '';
      if ($log = $conn->prepare("INSERT INTO audit_logs (user_id, username, role, action, entity, entity_id, before_data, after_data, created_at) VALUES (?,?,?,?,?,?,?,?, NOW())")) {
        $action = 'update'; $entity = 'clients';
        $log->bind_param('issssiss', $userId, $username, $role, $action, $entity, $id, $beforeJson, $afterJson);
        $log->execute();
      }
      $_SESSION['message'] = 'Client updated successfully';
      header('Location: clients.php');
      exit;
    } else {
      $error = 'Update failed: ' . $conn->error;
    }
  }
}

$error = $_SESSION['error'] ?? ($error ?? '');
unset($_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Edit Client Record | The Breeze Spa</title>
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
    .user-profile p { color: #94a3b8; font-size: 12px; text-transform: uppercase; }
    
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
    .content-wrapper { padding: 30px; flex: 1; max-width: 900px; margin: 0 auto; width: 100%;}

    .alert { padding: 16px 20px; border-radius: 2px; margin-bottom: 25px; font-size: 15px; font-weight: 500; border-left: 4px solid; background: var(--bg-card); box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .alert-error { color: #991b1b; border-left-color: var(--danger-color); background: #fef2f2; }

    .flat-card { background: var(--bg-card); border: 1px solid var(--border-color); padding: 30px; margin-bottom: 30px; border-radius: 0; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
    .flat-card-header { border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 25px; }
    .flat-card-header h3 { font-size: 16px; font-weight: 600; color: var(--text-main); text-transform: uppercase; letter-spacing: 0.5px; }

    .grid-form { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .form-group { display: flex; flex-direction: column; }
    .form-group.full-width { grid-column: span 2; }
    .form-group label { font-size: 13px; font-weight: 600; color: var(--text-muted); margin-bottom: 8px; text-transform: uppercase; }
    .form-control { padding: 12px 14px; border: 1px solid var(--border-color); border-radius: 2px; font-size: 15px; background: #f8fafc; outline: none; transition: border-color 0.2s; width: 100%; }
    .form-control:focus { border-color: var(--brand-blue); background: #ffffff; }
    
    .btn { padding: 12px 24px; font-size: 15px; font-weight: 600; border: none; border-radius: 2px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; transition: background-color 0.2s; }
    .btn-primary { background: var(--brand-blue); color: #fff; }
    .btn-primary:hover { background: #0284c7; }
    .btn-outline { background: transparent; border: 1px solid var(--border-color); color: var(--text-main); }
    .btn-outline:hover { background: #f1f5f9; }
    
    .action-group { display: flex; gap: 15px; margin-top: 25px; grid-column: span 2; }

    @media (max-width: 768px) {
        .sidebar { transform: translateX(-100%); }
        .main-content { margin-left: 0; }
        .grid-form { grid-template-columns: 1fr; }
        .action-group { grid-column: span 1; }
    }
  </style>
</head>
<body>

  <aside class="sidebar" id="sidebar">
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

            <a href="services_management.php" class="menu-item"><i class="fas fa-list-ul"></i> Services & Pricing</a>
            
            <?php if ($user_role === 'admin' || $user_role === 'supervisor'): ?>
            <a href="massage_management.php" class="menu-item"><i class="fas fa-spa"></i> Massage Categories</a>
            <?php endif; ?>
            
            <a href="appointments.php" class="menu-item"><i class="fas fa-calendar-days"></i> Appointments</a>
            <a href="reports.php" class="menu-item"><i class="fas fa-file-invoice-dollar"></i> Financial Reports</a>
            <a href="clients.php" class="menu-item active"><i class="fas fa-address-book"></i> Client Directory</a>
            
            <?php if ($user_role === 'admin'): ?>
                <a href="audit_logs.php" class="menu-item"><i class="fas fa-server"></i> System Audit Logs</a>
            <?php endif; ?>
        </nav>
        <a href="../logout.php" class="logout-btn"><i class="fas fa-arrow-right-from-bracket"></i> Terminate Session</a>
    </aside>

  <main class="main-content">
    <header class="topbar">
        <h2>
            <a href="clients.php" class="btn btn-outline btn-sm" style="margin-right: 15px;"><i class="fas fa-arrow-left"></i> Back</a>
            Modify Client Record
        </h2>
    </header>

    <div class="content-wrapper">
      <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <div class="flat-card">
        <div class="flat-card-header"><h3>Client Information & Ledger</h3></div>
        
        <form method="post" class="grid-form">
            <div class="form-group">
                <label>Client Name</label>
                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($client['name'] ?? '') ?>" placeholder="Client Name" required />
            </div>

            <div class="form-group">
                <label>Contact Number</label>
                <input type="tel" name="phone" class="form-control" value="<?= htmlspecialchars($client['phone'] ?? '') ?>" placeholder="Phone (10 digits)" required pattern="^[0-9]{10}$" title="Enter exactly 10 digits" maxlength="10" inputmode="numeric" />
            </div>

            <div class="form-group">
                <label>Assigned Service</label>
                <input type="text" name="service_type" class="form-control" value="<?= htmlspecialchars($client['service_type'] ?? '') ?>" placeholder="Service Type" required />
            </div>

            <div class="form-group">
                <label>Amount (GHS)</label>
                <input type="number" step="0.01" name="amount" class="form-control" value="<?= htmlspecialchars($client['amount'] ?? '') ?>" placeholder="Amount" required />
            </div>

            <div class="form-group">
                <label>Duration</label>
                <input id="duration-input" type="text" name="duration" class="form-control" value="<?= htmlspecialchars($client['duration'] ?? '') ?>" placeholder="Duration" />
            </div>

            <div class="form-group">
                <label>Payment Mode</label>
                <select name="payment_mode" id="payment-mode" class="form-control" required>
                  <option value="" <?= empty($client['payment_mode']) ? 'selected' : '' ?>>Payment Mode</option>
                  <option value="Cash" <?= ($client['payment_mode'] ?? '') === 'Cash' ? 'selected' : '' ?>>Cash</option>
                  <option value="Mobile Money" <?= ($client['payment_mode'] ?? '') === 'Mobile Money' ? 'selected' : '' ?>>Mobile Money</option>
                </select>
            </div>

            <div class="form-group">
                <label>Massage Type (Massage only)</label>
                <input id="massage-type-input" type="text" name="massage_type" class="form-control" value="<?= htmlspecialchars($client['massage_type'] ?? '') ?>" placeholder="Massage Type" />
            </div>

            <div class="form-group">
                <label>Attending Staff</label>
                <input type="text" name="staff_name" class="form-control" value="<?= htmlspecialchars($client['staff_name'] ?? '') ?>" placeholder="Attendant" required />
            </div>

            <div class="form-group full-width">
                <label>Section</label>
                <select name="section" id="section-select" class="form-control" required>
                  <?php 
                  $sections = ['Massage','Hair Barbering','Hair Salon','Facials','Nails & Manicure','Pedicure & Manicure','Make-Up services']; 
                  foreach ($sections as $sec) { 
                    $sel = ($client['section'] ?? '') === $sec ? 'selected' : ''; 
                    echo "<option value=\"$sec\" $sel>$sec</option>"; 
                  } 
                  ?>
                </select>
            </div>

            <div class="action-group">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                <a href="clients.php" class="btn btn-outline">Cancel</a>
            </div>
        </form>
      </div>
    </div>
  </main>

  <script>
    (function(){
      var sectionSel = document.getElementById('section-select');
      var durationIn = document.getElementById('duration-input');
      var massageTypeIn = document.getElementById('massage-type-input');
      function applyReq(){
        var isMassage = sectionSel && sectionSel.value === 'Massage';
        if (durationIn) { 
          if (isMassage) { 
            durationIn.setAttribute('required','required'); 
          } else { 
            durationIn.removeAttribute('required'); 
          } 
        }
        if (massageTypeIn) { 
          if (isMassage) { 
            massageTypeIn.setAttribute('required','required'); 
          } else { 
            massageTypeIn.removeAttribute('required'); 
          } 
        }
      }
      if (sectionSel) { 
        sectionSel.addEventListener('change', applyReq); 
        applyReq(); 
      }
    })();
  </script>
</body>
</html>
