<?php
session_start();
include '../db.php';

if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['frontdesk', 'reception'])) {
    header("Location: ../login.php");
    exit;
}

if (!isset($_GET['id'])) {
    header("Location: view_clients.php");
    exit;
}

$id = (int)$_GET['id'];
$stmt = $conn->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: view_clients.php");
    exit;
}

$client = $result->fetch_assoc();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST['name']);
    $phone = preg_replace('/\D+/', '', $_POST['phone']);
    $service_type = $_POST['service_type'];
    $amount = $_POST['amount'];
    $duration = $_POST['duration'];
    $payment_mode = $_POST['payment_mode'];
    $massage_type = $_POST['massage_type'];
    $staff_name = $_POST['staff_name'];
    $section = $_POST['section'];

    if (strlen($phone) !== 10) {
        $error = "Phone must be exactly 10 digits.";
    } else {
        $update_sql = "UPDATE clients SET name=?, phone=?, service_type=?, amount=?, duration=?, payment_mode=?, massage_type=?, staff_name=?, section=? WHERE id=?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("sssssssssi", $name, $phone, $service_type, $amount, $duration, $payment_mode, $massage_type, $staff_name, $section, $id);
        
        if ($stmt->execute()) {
            $success = "Client record updated successfully.";
            $client = array_merge($client, $_POST); // Update local data
        } else {
            $error = "Update failed: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Client | Front Office</title>
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
        body { background-color: var(--bg-body); color: var(--text-main); font-size: 15px; display: flex; min-height: 100vh;}
        
        /* Sidebar Styling Inserted */
        .sidebar { width: var(--sidebar-width); background-color: var(--brand-dark); color: #ffffff; position: fixed; height: 100vh; left: 0; top: 0; display: flex; flex-direction: column; z-index: 100; border-right: 1px solid #1e293b; }
        .sidebar-header { padding: 20px; border-bottom: 1px solid #1e293b; display: flex; align-items: center; justify-content: center; }
        .sidebar-logo { width: 90%; max-height: 80px; object-fit: contain; }
        .user-profile { padding: 20px; border-bottom: 1px solid #1e293b; font-size: 14px; }
        .user-profile h4 { color: #ffffff; font-size: 15px; font-weight: 600; margin-bottom: 4px; }
        .user-profile p { color: #94a3b8; font-size: 12px; display: flex; align-items: center; gap: 6px; }
        .sidebar-menu { padding: 20px 0; flex: 1; }
        .menu-item { display: flex; align-items: center; padding: 14px 24px; color: #cbd5e1; text-decoration: none; font-size: 15px; font-weight: 500; border-left: 4px solid transparent; transition: all 0.2s; }
        .menu-item i { width: 24px; font-size: 16px; margin-right: 10px; color: #64748b; }
        .menu-item:hover, .menu-item.active { background-color: #1e293b; color: #ffffff; border-left-color: var(--brand-blue); }
        .menu-item:hover i, .menu-item.active i { color: var(--brand-blue); }
        .logout-btn { display: flex; align-items: center; padding: 16px 24px; color: #ef4444; text-decoration: none; font-size: 15px; font-weight: 600; border-top: 1px solid #1e293b; background-color: #0b1221; }
        
        /* Main Workspace */
        .main-content { flex: 1; margin-left: var(--sidebar-width); padding: 40px 20px; display: flex; flex-direction: column; width: calc(100% - var(--sidebar-width)); }
        
        .container { max-width: 800px; margin: 0 auto; width: 100%; }
        .flat-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 0; padding: 40px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .flat-card-header { border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 30px; }
        .flat-card-header h3 { font-size: 18px; font-weight: 600; text-transform: uppercase; color: var(--text-main); letter-spacing: 0.5px; }
        
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-size: 13px; font-weight: 600; color: var(--text-muted); margin-bottom: 8px; text-transform: uppercase; }
        .form-control { padding: 12px 14px; border: 1px solid var(--border-color); border-radius: 2px; font-size: 15px; width: 100%; outline: none; background: #f8fafc; }
        .form-control:focus { border-color: var(--brand-blue); background: #ffffff; }
        
        .btn { padding: 14px 28px; font-weight: 600; border: none; cursor: pointer; border-radius: 2px; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
        .btn-primary { background: var(--brand-blue); color: #fff; }
        .btn-primary:hover { background: #0284c7; }
        .btn-outline { background: #f1f5f9; color: var(--text-main); border: 1px solid var(--border-color); }
        
        .alert { padding: 16px; margin-bottom: 20px; border-left: 4px solid; font-weight: 500; }
        .alert-success { border-color: #10b981; background: #ecfdf5; color: #065f46; }
        .alert-error { border-color: #dc2626; background: #fef2f2; color: #991b1b; }
        
        @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .main-content { margin-left: 0; width: 100%; } }
        @media (max-width: 600px) { .form-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-header">
            <img src="../assets/images/The-Breeze-1.png" alt="The Breeze Spa Logo" class="sidebar-logo">
        </div>
        <div class="user-profile">
            <h4><?= htmlspecialchars($_SESSION['user']['name']) ?></h4>
            <p><i class="fas fa-circle" style="color: #10b981; font-size: 10px; margin-right: 5px;"></i> Front Office Ops</p>
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
        <div class="container">
            <a href="view_clients.php" style="display:inline-block; margin-bottom:20px; color:var(--text-muted); text-decoration:none;"><i class="fas fa-arrow-left"></i> Back to Directory</a>

            <?php if (isset($success)): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div><?php endif; ?>
            <?php if (isset($error)): ?><div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= $error ?></div><?php endif; ?>

            <div class="flat-card">
                <div class="flat-card-header"><h3>Edit Client Information</h3></div>
                
                <form method="post" class="form-grid">
                    <div class="form-group"><label>Full Name</label><input type="text" name="name" class="form-control" value="<?= htmlspecialchars($client['name']) ?>" required></div>
                    <div class="form-group"><label>Phone</label><input type="tel" name="phone" class="form-control" value="<?= htmlspecialchars($client['phone']) ?>" maxlength="10" required></div>
                    
                    <div class="form-group"><label>Service Provided</label><input type="text" name="service_type" class="form-control" value="<?= htmlspecialchars($client['service_type']) ?>" required></div>
                    <div class="form-group"><label>Amount (GHS)</label><input type="number" name="amount" class="form-control" step="0.01" value="<?= htmlspecialchars($client['amount']) ?>" required></div>

                    <div class="form-group"><label>Duration</label><input type="text" name="duration" id="duration-input" class="form-control" value="<?= htmlspecialchars($client['duration']) ?>"></div>
                    
                    <div class="form-group">
                        <label>Payment Mode</label>
                        <select name="payment_mode" class="form-control" required>
                            <option value="Cash" <?= ($client['payment_mode']??'') === 'Cash' ? 'selected' : '' ?>>Cash</option>
                            <option value="Mobile Money" <?= ($client['payment_mode']??'') === 'Mobile Money' ? 'selected' : '' ?>>Mobile Money</option>
                        </select>
                    </div>

                    <div class="form-group"><label>Massage Type</label><input type="text" name="massage_type" id="m-type-input" class="form-control" value="<?= htmlspecialchars($client['massage_type']) ?>"></div>
                    <div class="form-group"><label>Staff Attendant</label><input type="text" name="staff_name" class="form-control" value="<?= htmlspecialchars($client['staff_name']) ?>" required></div>
                    
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Section</label>
                        <select name="section" id="section-select" class="form-control" required>
                            <?php foreach (['Massage', 'Pedicure & Manicure', 'Barbering', 'Hair Salon', 'Facials', 'Make-Up services'] as $sec): ?>
                                <option value="<?= $sec ?>" <?= ($client['section']??'') === $sec ? 'selected' : '' ?>><?= $sec ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="grid-column: span 2; display: flex; gap: 10px;">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                        <a href="view_clients.php" class="btn btn-outline">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </main>

<script>
    const sectionSel = document.getElementById('section-select');
    const durIn = document.getElementById('duration-input');
    const mTypeIn = document.getElementById('m-type-input');

    function applyReq(){
        const isMassage = sectionSel.value === 'Massage';
        durIn.required = isMassage;
        mTypeIn.required = isMassage;
    }
    sectionSel.addEventListener('change', applyReq);
    applyReq();
</script>
</body>
</html>