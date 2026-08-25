<?php
session_start();
include '../db.php';

if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['frontdesk', 'reception'])) {
    header('Location: ../index.php');
    exit;
}

$conn->query("CREATE TABLE IF NOT EXISTS appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_name VARCHAR(255) NOT NULL,
    phone VARCHAR(50) NOT NULL,
    service_type VARCHAR(100) NOT NULL,
    staff_assigned VARCHAR(100) NULL,
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    status ENUM('Scheduled', 'Completed', 'Cancelled') DEFAULT 'Scheduled',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if (isset($_POST['schedule_appointment'])) {
    $client_name = trim($_POST['client_name']);
    $phone = trim($_POST['phone']);
    $service_type = trim($_POST['service_type']);
    $staff_assigned = trim($_POST['staff_assigned']);
    $appointment_date = $_POST['appointment_date'];
    $appointment_time = $_POST['appointment_time'];

    $stmt = $conn->prepare("INSERT INTO appointments (client_name, phone, service_type, staff_assigned, appointment_date, appointment_time) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssss", $client_name, $phone, $service_type, $staff_assigned, $appointment_date, $appointment_time);
    
    if ($stmt->execute()) {
        $inserted_id = $conn->insert_id;
        $_SESSION['message'] = "Appointment successfully scheduled.";
        
        $userId = (int)($_SESSION['user']['id'] ?? 0);
        $logUsername = $_SESSION['user']['username'] ?? '';
        $userRole = $_SESSION['user']['role'] ?? '';
        $afterData = json_encode(['id' => $inserted_id, 'client' => $client_name, 'date' => $appointment_date]);
        
        if ($log = $conn->prepare("INSERT INTO audit_logs (user_id, username, role, action, entity, entity_id, after_data, created_at) VALUES (?,?,?, 'create', 'appointments', ?, ?, NOW())")) {
            $log->bind_param('issis', $userId, $logUsername, $userRole, $inserted_id, $afterData);
            $log->execute();
        }
    } else {
        $_SESSION['error'] = "System error: Unable to schedule appointment.";
    }
    header("Location: appointments.php");
    exit;
}

if (isset($_POST['update_status'])) {
    $id = (int)$_POST['appointment_id'];
    $new_status = $_POST['new_status'];
    
    $stmt = $conn->prepare("UPDATE appointments SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $id);
    if ($stmt->execute()) {
        $_SESSION['message'] = "Appointment status updated to " . htmlspecialchars($new_status) . ".";
    }
    header("Location: appointments.php");
    exit;
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM appointments WHERE id = ?");
    $stmt->bind_param('i', $id);
    if ($stmt->execute()) {
        $_SESSION['message'] = 'Appointment record deleted successfully.';
    }
    header('Location: appointments.php');
    exit;
}

$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $like = "%$search%";
    $sql = "SELECT * FROM appointments WHERE client_name LIKE ? OR phone LIKE ? ORDER BY appointment_date ASC, appointment_time ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $like, $like);
} else {
    $stmt = $conn->prepare("SELECT * FROM appointments ORDER BY appointment_date ASC, appointment_time ASC");
}
$stmt->execute();
$result = $stmt->get_result();

// FIX: Fetch and combine BOTH generic services and massage types
$all_services = [];
$srv_query = $conn->query("SELECT service_name FROM services WHERE service_name IS NOT NULL AND service_name != ''");
if($srv_query) { while($row = $srv_query->fetch_assoc()) { $all_services[] = trim($row['service_name']); } }

$msg_query = $conn->query("SELECT name FROM massage_types WHERE name IS NOT NULL AND name != ''");
if($msg_query) { while($row = $msg_query->fetch_assoc()) { $all_services[] = trim($row['name']); } }

$all_services = array_unique($all_services);
sort($all_services);

// Fetch Staff
$staff = $conn->query("SELECT name FROM staff WHERE role IN ('therapist', 'barbering', 'facials', 'nails', 'salon') ORDER BY name ASC");

$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Appointments | Front Office</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-blue: #0ea5e9; --brand-dark: #0f172a; --bg-body: #f1f5f9; --bg-card: #ffffff;
            --text-main: #1e293b; --text-muted: #475569; --border-color: #e2e8f0;
            --sidebar-width: 250px; --topbar-height: 60px;
            --danger-color: #dc2626; --success-color: #10b981;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        body { background-color: var(--bg-body); color: var(--text-main); display: flex; min-height: 100vh; font-size: 15px; overflow-x: hidden; }

        .sidebar { width: var(--sidebar-width); background-color: var(--brand-dark); color: #ffffff; position: fixed; height: 100vh; left: 0; top: 0; display: flex; flex-direction: column; z-index: 100; border-right: 1px solid #1e293b; }
        .sidebar-header { padding: 20px; border-bottom: 1px solid #1e293b; display: flex; align-items: center; justify-content: center; }
        .sidebar-logo { width: 90%; max-height: 80px; object-fit: contain; }
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
        
        .main-content { flex: 1; margin-left: var(--sidebar-width); display: flex; flex-direction: column; min-height: 100vh; width: calc(100% - var(--sidebar-width)); }
        .topbar { height: var(--topbar-height); background-color: var(--bg-card); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 30px; position: sticky; top: 0; z-index: 90; }
        .topbar h2 { font-size: 18px; font-weight: 600; }
        .topbar .date-stamp { font-size: 13px; color: var(--text-muted); font-weight: 500; }
        .content-wrapper { padding: 30px; flex: 1; max-width: 1400px;}

        .alert { padding: 16px 20px; margin-bottom: 24px; font-size: 15px; font-weight: 500; border-left: 4px solid; background-color: var(--bg-card); display: flex; align-items: center; gap: 10px; }
        .alert-success { border-left-color: var(--success-color); color: #065f46; }
        .alert-error { border-left-color: var(--danger-color); color: #991b1b; }

        .flat-card { background-color: var(--bg-card); border: 1px solid var(--border-color); padding: 30px; margin-bottom: 30px; }
        .flat-card-header { border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center;}
        .flat-card-header h3 { font-size: 18px; font-weight: 600; text-transform: uppercase; }

        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; align-items: end; margin-bottom: 20px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-size: 13px; font-weight: 600; color: var(--text-muted); margin-bottom: 6px; text-transform: uppercase; }
        .form-control { padding: 12px 14px; border: 1px solid var(--border-color); border-radius: 2px; font-size: 15px; outline: none; background-color: #f8fafc; }
        .form-control:focus { border-color: var(--brand-blue); background-color: #ffffff; }

        .btn { padding: 10px 20px; font-size: 15px; font-weight: 600; border: none; border-radius: 2px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
        .btn-primary { background-color: var(--brand-blue); color: #ffffff; }
        .btn-success { background-color: #ecfdf5; color: var(--success-color); border: 1px solid #a7f3d0; }
        .btn-danger { background-color: #fef2f2; color: var(--danger-color); border: 1px solid #fca5a5; }
        .btn-outline { background-color: #ffffff; border: 1px solid var(--border-color); color: var(--text-main); }
        .btn-sm { padding: 6px 12px; font-size: 13px; }

        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 16px 20px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 15px; white-space: nowrap; }
        th { background-color: #f8fafc; font-weight: 600; color: var(--text-muted); text-transform: uppercase; font-size: 13px; }
        
        .badge { padding: 6px 12px; font-size: 12px; font-weight: 700; border-radius: 2px; text-transform: uppercase; border: 1px solid; display: inline-block; text-align: center; width: 100px; }
        .badge-scheduled { background-color: #e0f2fe; color: #0369a1; border-color: #bae6fd; }
        .badge-completed { background-color: #dcfce7; color: #166534; border-color: #bbf7d0; }
        .badge-cancelled { background-color: #fef2f2; color: #991b1b; border-color: #fecaca; }

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
            <p><i class="fas fa-circle"></i> Front Office Ops</p>
        </div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item"><i class="fas fa-desktop"></i> Station Dashboard</a>
            <a href="add_client.php" class="menu-item"><i class="fas fa-user-plus"></i> Register Client</a>
            <a href="appointments.php" class="menu-item active"><i class="fas fa-calendar-days"></i> Appointments</a>
            <a href="view_clients.php" class="menu-item"><i class="fas fa-address-book"></i> Client Directory</a>
        </nav>
        <a href="../logout.php" class="logout-btn"><i class="fas fa-arrow-right-from-bracket"></i> End Shift</a>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <h2>Appointments Schedule</h2>
            <div class="date-stamp"><?= date('l, F j, Y') ?></div>
        </header>

        <div class="content-wrapper">
            <?php if ($message): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>

            <div class="flat-card">
                <div class="flat-card-header"><h3>Book New Appointment</h3></div>
                <form method="post">
                    <div class="form-row">
                        <div class="form-group"><label>Client Name</label><input type="text" name="client_name" class="form-control" required></div>
                        <div class="form-group"><label>Phone Number</label><input type="text" name="phone" class="form-control" required></div>
                        <div class="form-group"><label>Service Type</label>
                            <select name="service_type" class="form-control" required>
                                <option value="" disabled selected>Select service...</option>
                                <?php foreach($all_services as $svc): ?>
                                    <option value="<?= htmlspecialchars($svc) ?>"><?= htmlspecialchars($svc) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Assigned Staff</label>
                            <select name="staff_assigned" class="form-control">
                                <option value="">Any Available</option>
                                <?php if($staff) while($st = $staff->fetch_assoc()): ?>
                                    <option value="<?= htmlspecialchars($st['name']) ?>"><?= htmlspecialchars($st['name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group"><label>Date</label><input type="date" name="appointment_date" class="form-control" required></div>
                        <div class="form-group"><label>Time</label><input type="time" name="appointment_time" class="form-control" required></div>
                        <div class="form-group"><button type="submit" name="schedule_appointment" class="btn btn-primary" style="height: 46px;"><i class="fas fa-calendar-plus"></i> Schedule</button></div>
                    </div>
                </form>
            </div>

            <div class="flat-card">
                <div class="flat-card-header">
                    <h3>Appointment Registry</h3>
                    <form method="get" style="display: flex; gap: 10px;">
                        <input type="text" name="search" placeholder="Search client or phone..." value="<?= htmlspecialchars($search) ?>" class="form-control" style="width: 250px; padding: 8px 12px;">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
                        <?php if ($search !== ''): ?><a href="appointments.php" class="btn btn-outline btn-sm"><i class="fas fa-times"></i></a><?php endif; ?>
                    </form>
                </div>
                
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Client Details</th>
                                <th>Service & Staff</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600;"><i class="far fa-calendar-alt" style="color:var(--text-muted); margin-right:5px;"></i> <?= date('M d, Y', strtotime($row['appointment_date'])) ?></div>
                                    <div style="font-size: 14px; color: var(--text-muted); margin-top: 4px;"><i class="far fa-clock" style="margin-right:5px;"></i> <?= date('h:i A', strtotime($row['appointment_time'])) ?></div>
                                </td>
                                <td><strong><?= htmlspecialchars($row['client_name']) ?></strong><br><span style="font-size: 13px; color: var(--text-muted);"><?= htmlspecialchars($row['phone']) ?></span></td>
                                <td><div style="font-weight: 500;"><?= htmlspecialchars($row['service_type']) ?></div><div style="font-size: 13px; color: var(--text-muted); margin-top: 4px;"><i class="fas fa-user-circle"></i> <?= htmlspecialchars($row['staff_assigned'] ?: 'Unassigned') ?></div></td>
                                <td>
                                    <?php 
                                        $sClass = 'badge-scheduled';
                                        if ($row['status'] === 'Completed') $sClass = 'badge-completed';
                                        if ($row['status'] === 'Cancelled') $sClass = 'badge-cancelled';
                                    ?>
                                    <span class="badge <?= $sClass ?>"><?= htmlspecialchars($row['status']) ?></span>
                                </td>
                                <td style="display: flex; gap: 8px;">
                                    <?php if ($row['status'] === 'Scheduled'): ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="appointment_id" value="<?= $row['id'] ?>">
                                            <input type="hidden" name="new_status" value="Completed">
                                            <button type="submit" name="update_status" class="btn btn-success btn-sm" title="Mark Completed"><i class="fas fa-check"></i></button>
                                        </form>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Cancel this appointment?');">
                                            <input type="hidden" name="appointment_id" value="<?= $row['id'] ?>">
                                            <input type="hidden" name="new_status" value="Cancelled">
                                            <button type="submit" name="update_status" class="btn btn-outline btn-sm" title="Cancel Appointment"><i class="fas fa-ban"></i></button>
                                        </form>
                                    <?php endif; ?>
                                    <a href="?delete=<?= $row['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this record?')" title="Delete"><i class="fas fa-trash-alt"></i></a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            <?php if ($result->num_rows === 0): ?>
                            <tr><td colspan="5" style="text-align: center; padding: 40px; color: var(--text-muted);">No appointments found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</body>
</html>