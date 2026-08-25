<?php
session_start();
include '../db.php';

if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['frontdesk', 'reception'])) {
    header("Location: ../login.php");
    exit;
}

// Ensure audit_logs exists
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

// Fetch data for dropdowns
$massage_types = [];
if ($res = $conn->query("SELECT name, category, price_60, price_90, price_120 FROM massage_types ORDER BY name")) {
    while ($row = $res->fetch_assoc()) {
        $massage_types[] = [
            'name' => $row['name'],
            'category' => $row['category'],
            'price_60' => (float)$row['price_60'],
            'price_90' => (float)$row['price_90'],
            'price_120' => (float)$row['price_120']
        ];
    }
}

$servicesBySection = [];
$servicePriceBySectionAndName = [];
$svcRes = $conn->query("SELECT * FROM services");
if ($svcRes) {
    while ($svc = $svcRes->fetch_assoc()) {
        $sec = $svc['section'] ?? 'Other';
        $nm = $svc['service_name'] ?? '';
        $pr = (float)($svc['price'] ?? 0);
        if ($nm !== '') {
            $servicesBySection[$sec][] = $nm;
            $servicePriceBySectionAndName[$sec][$nm] = $pr;
        }
    }
}

$success = $error = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST['name']);
    $phone = preg_replace('/\D+/', '', $_POST['phone']);
    if (strlen($phone) !== 10) { $error = "Phone must be exactly 10 digits."; }
    
    $service_type = $_POST['service_type'];
    $amount = (float)$_POST['amount'];
    $payment_mode = $_POST['payment_mode'];
    $staff_name = $_POST['staff_name'];
    $section = $_POST['section'];
    $client_code = "CL-" . rand(10000, 99999);
    $duration = $_POST['form_type'] === 'massage' ? $_POST['duration'] : null;
    $massage_type = $_POST['form_type'] === 'massage' ? $_POST['massage_type'] : null;

    if (empty($error)) {
        $stmt = $conn->prepare("INSERT INTO clients (name, phone, service_type, amount, duration, payment_mode, massage_type, staff_name, section, client_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssssss", $name, $phone, $service_type, $amount, $duration, $payment_mode, $massage_type, $staff_name, $section, $client_code);
        
        if ($stmt->execute()) {
            $inserted_id = $conn->insert_id;
            $success = "Registration Successful! Code: $client_code";
            
            // Audit Log
            $after = ['id' => $inserted_id, 'name' => $name, 'client_code' => $client_code];
            $userId = (int)($_SESSION['user']['id'] ?? 0);
            $username = $_SESSION['user']['username'] ?? '';
            $role = $_SESSION['user']['role'] ?? '';
            $log = $conn->prepare("INSERT INTO audit_logs (user_id, username, role, action, entity, entity_id, after_data, created_at) VALUES (?,?,?, 'create', 'clients', ?, ?, NOW())");
            $afterJson = json_encode($after);
            $log->bind_param('issis', $userId, $username, $role, $inserted_id, $afterJson);
            $log->execute();
        } else {
            $error = "System Error: Unable to register client.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>New Registration | The Breeze Spa</title>
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
            --success-color: #10b981;
            --danger-color: #dc2626;
            --sidebar-width: 250px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        body { background-color: var(--bg-body); display: flex; color: var(--text-main); font-size: 15px; min-height: 100vh;}
        
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
        
        .btn-group { display: flex; gap: 5px; margin-bottom: 30px; }
        .form-btn { flex: 1; padding: 14px; border: 1px solid var(--border-color); background: #f8fafc; font-weight: 600; cursor: pointer; border-radius: 0; }
        .form-btn.active { background: var(--brand-blue); color: white; border-color: var(--brand-blue); }
        
        form { display: none; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-size: 13px; font-weight: 600; color: var(--text-muted); margin-bottom: 8px; text-transform: uppercase; }
        .form-control { padding: 14px; border: 1px solid var(--border-color); border-radius: 2px; font-size: 16px; width: 100%; outline: none; background: #f8fafc; }
        .form-control:focus { border-color: var(--brand-blue); background: #ffffff; }
        
        .btn { grid-column: span 2; padding: 14px; border: none; background: var(--brand-blue); color: white; font-weight: 600; cursor: pointer; }
        .btn:hover { background: #0284c7; }
        
        .alert { padding: 16px; margin-bottom: 20px; border-left: 4px solid; font-weight: 500; }
        .alert-success { border-color: var(--success-color); background: #ecfdf5; color: #065f46; }
        .alert-error { border-color: var(--danger-color); background: #fef2f2; color: #991b1b; }
        
        @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .main-content { margin-left: 0; width: 100%; } }
        @media (max-width: 600px) { form { grid-template-columns: 1fr; } .btn { grid-column: span 1; } }
        
        .category-tabs { display: flex; gap: 5px; }
        .tab-btn { padding: 4px 10px; border: 1px solid var(--border-color); background: #f8fafc; font-weight: 600; cursor: pointer; border-radius: 4px; font-size: 11px; outline: none; }
        .tab-btn.active { background: var(--brand-blue); color: white; border-color: var(--brand-blue); }
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
            <a href="add_client.php" class="menu-item active"><i class="fas fa-user-plus"></i> Register Client</a>
            <a href="appointments.php" class="menu-item"><i class="fas fa-calendar-days"></i> Appointments</a>
            <a href="view_clients.php" class="menu-item"><i class="fas fa-address-book"></i> Client Directory</a>
        </nav>
        <a href="../logout.php" class="logout-btn"><i class="fas fa-arrow-right-from-bracket"></i> End Shift</a>
    </aside>

    <main class="main-content">
        <div class="container">
            <a href="dashboard.php" style="display:inline-block; margin-bottom:20px; color:var(--text-muted); text-decoration:none;"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            
            <?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= $error ?></div><?php endif; ?>

            <div class="flat-card">
                <div class="flat-card-header"><h3>Register New Client</h3></div>
                
                <div class="btn-group">
                    <button class="form-btn active" onclick="toggleForm('massage')">Massage Service</button>
                    <button class="form-btn" onclick="toggleForm('others')">Other Services</button>
                </div>

                <form method="post" id="massage-form">
                    <input type="hidden" name="form_type" value="massage">
                    <input type="hidden" name="service_type" value="Massage">
                    <input type="hidden" name="section" value="Massage">
                    <div class="form-group"><label>Client Name</label><input type="text" name="name" class="form-control" required></div>
                    <div class="form-group"><label>Phone</label><input type="tel" name="phone" class="form-control" maxlength="10" required></div>
                    <div class="form-group">
                        <label style="display: flex; justify-content: space-between; align-items: center;">
                            <span>Massage Type</span>
                            <div class="category-tabs">
                                <button type="button" class="tab-btn active" data-category="Full Body Massage" onclick="selectMassageCategory('Full Body Massage', this)">Full Body</button>
                                <button type="button" class="tab-btn" data-category="Express Massage" onclick="selectMassageCategory('Express Massage', this)">Express</button>
                            </div>
                        </label>
                        <select name="massage_type" id="m-type" class="form-control" required>
                            <option value="">Select...</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Duration</label><select name="duration" id="m-duration" class="form-control" required>
                        <option value="">Select...</option>
                        <option value="60 min">60 min</option>
                        <option value="90 min">90 min</option>
                        <option value="120 min">120 min</option>
                    </select></div>
                    <div class="form-group"><label>Amount (GHS)</label><input type="number" name="amount" id="m-amt" class="form-control" step="0.01" readonly required></div>
                    <div class="form-group"><label>Payment Mode</label><select name="payment_mode" class="form-control" required><option>Cash</option><option>Mobile Money</option></select></div>
                    <div class="form-group"><label>Staff</label><input type="text" name="staff_name" class="form-control" required></div>
                    <button type="submit" class="btn">Register Client</button>
                </form>

                <form method="post" id="others-form">
                    <input type="hidden" name="form_type" value="others">
                    <div class="form-group"><label>Client Name</label><input type="text" name="name" class="form-control" required></div>
                    <div class="form-group"><label>Phone</label><input type="tel" name="phone" class="form-control" maxlength="10" required></div>
                    <div class="form-group"><label>Section</label><select name="section" id="o-sec" class="form-control" required><option value="">Select Section</option>
                        <?php foreach(array_keys($servicesBySection) as $sec): ?><option value="<?= $sec ?>"><?= $sec ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="form-group"><label>Service</label><select name="service_type" id="o-svc" class="form-control" required disabled></select></div>
                    <div class="form-group"><label>Amount (GHS)</label><input type="number" name="amount" id="o-amt" class="form-control" step="0.01" readonly required></div>
                    <div class="form-group"><label>Payment</label><select name="payment_mode" class="form-control" required><option>Cash</option><option>Mobile Money</option></select></div>
                    <div class="form-group"><label>Staff</label><input type="text" name="staff_name" class="form-control" required></div>
                    <button type="submit" class="btn">Register Client</button>
                </form>
            </div>
        </div>
    </main>

<script>
    const svcs = <?php echo json_encode($servicesBySection); ?>;
    const prices = <?php echo json_encode($servicePriceBySectionAndName); ?>;
    const massageTypesList = <?php echo json_encode($massage_types); ?>;

    function toggleForm(t) {
        document.getElementById('massage-form').style.display = t === 'massage' ? 'grid' : 'none';
        document.getElementById('others-form').style.display = t === 'others' ? 'grid' : 'none';
        document.querySelectorAll('.form-btn').forEach((b,i) => i === (t==='massage'?0:1) ? b.classList.add('active') : b.classList.remove('active'));
    }

    document.getElementById('o-sec').addEventListener('change', e => {
        let s = document.getElementById('o-svc');
        s.innerHTML = '<option value="">Select Service</option>';
        (svcs[e.target.value] || []).forEach(v => s.innerHTML += `<option value="${v}">${v}</option>`);
        s.disabled = false;
    });

    document.getElementById('o-svc').addEventListener('change', e => {
        let p = prices[document.getElementById('o-sec').value][e.target.value];
        if(p) document.getElementById('o-amt').value = p.toFixed(2);
    });

    function selectMassageCategory(cat, btn) {
        // Toggle active button style
        document.querySelectorAll('.tab-btn').forEach(b => {
            if (b === btn) {
                b.classList.add('active');
            } else {
                b.classList.remove('active');
            }
        });
        
        // Rebuild select options
        const mTypeSelect = document.getElementById('m-type');
        mTypeSelect.innerHTML = '<option value="">Select...</option>';
        massageTypesList.forEach(m => {
            if (m.category === cat) {
                mTypeSelect.innerHTML += `<option value="${m.name}">${m.name}</option>`;
            }
        });
        
        // Reset type, duration and price fields
        document.getElementById('m-duration').innerHTML = '<option value="">Select...</option>';
        document.getElementById('m-amt').value = '';
    }

    function updateMassagePrice() {
        const type = document.getElementById('m-type').value;
        const duration = document.getElementById('m-duration').value;
        const massage = massageTypesList.find(m => m.name === type);
        
        if (massage && duration) {
            let price = 0;
            if (duration === '60 min') price = massage.price_60;
            else if (duration === '90 min') price = massage.price_90;
            else if (duration === '120 min') price = massage.price_120;
            document.getElementById('m-amt').value = price.toFixed(2);
        } else {
            document.getElementById('m-amt').value = '';
        }
    }

    document.getElementById('m-type').addEventListener('change', e => {
        const type = e.target.value;
        const massage = massageTypesList.find(m => m.name === type);
        const mDurationSelect = document.getElementById('m-duration');
        
        mDurationSelect.innerHTML = '<option value="">Select...</option>';
        document.getElementById('m-amt').value = '';
        
        if (massage) {
            let optionsCount = 0;
            let lastOptionValue = '';
            
            if (massage.price_60 > 0) {
                mDurationSelect.innerHTML += '<option value="60 min">60 min</option>';
                optionsCount++;
                lastOptionValue = '60 min';
            }
            if (massage.price_90 > 0) {
                mDurationSelect.innerHTML += '<option value="90 min">90 min</option>';
                optionsCount++;
                lastOptionValue = '90 min';
            }
            if (massage.price_120 > 0) {
                mDurationSelect.innerHTML += '<option value="120 min">120 min</option>';
                optionsCount++;
                lastOptionValue = '120 min';
            }
            
            if (optionsCount === 1) {
                mDurationSelect.value = lastOptionValue;
                updateMassagePrice();
            }
        }
    });

    document.getElementById('m-duration').addEventListener('change', updateMassagePrice);

    // Initial category load
    document.addEventListener('DOMContentLoaded', () => {
        const defaultTab = document.querySelector('.tab-btn[data-category="Full Body Massage"]');
        if (defaultTab) {
            selectMassageCategory('Full Body Massage', defaultTab);
        }
    });

    toggleForm('massage');
</script>
</body>
</html>