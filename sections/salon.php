<?php
session_start();
include '../db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'salon') {
    header("Location: ../login.php");
    exit;
}

// Auto-provision status column if missing
$conn->query("ALTER TABLE clients ADD COLUMN IF NOT EXISTS status VARCHAR(50) DEFAULT 'Pending'");

// Handle status update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['status'], $_POST['client_id'])) {
    $status = $_POST['status'];
    $client_id = (int)$_POST['client_id'];
    $stmt = $conn->prepare("UPDATE clients SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $status, $client_id);
    $stmt->execute();
    header("Location: salon.php");
    exit;
}

$result = $conn->query("SELECT * FROM clients WHERE section = 'Hair Salon' ORDER BY created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Salon Dashboard | The Breeze Spa</title>
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
        body { background-color: var(--bg-body); color: var(--text-main); display: flex; min-height: 100vh; font-size: 16px; }

        /* Sidebar */
        .sidebar { width: var(--sidebar-width); background-color: var(--brand-dark); color: #ffffff; position: fixed; height: 100vh; z-index: 100; }
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
        .menu-item { display: flex; align-items: center; padding: 16px 24px; color: #cbd5e1; text-decoration: none; border-left: 4px solid transparent; }
        .menu-item.active { background: #1e293b; color: #fff; border-left-color: var(--brand-blue); }
        .logout-btn { padding: 16px 24px; color: #ef4444; text-decoration: none; font-weight: 600; display: block; border-top: 1px solid #1e293b; }

        /* Main Content */
        .main-content { flex: 1; margin-left: var(--sidebar-width); padding: 30px; }
        .topbar { background: var(--bg-card); padding: 20px 30px; border: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        
        .flat-card { background: var(--bg-card); border: 1px solid var(--border-color); padding: 30px; }
        .flat-card-header { border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 20px; }
        
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; padding: 14px; text-align: left; text-transform: uppercase; font-size: 13px; color: var(--text-muted); border-bottom: 2px solid var(--border-color); }
        td { padding: 16px 14px; border-bottom: 1px solid var(--border-color); font-size: 15px; }
        
        select { padding: 8px; border: 1px solid var(--border-color); border-radius: 2px; background: #f8fafc; cursor: pointer; }
        .badge { padding: 4px 10px; font-size: 12px; font-weight: 600; border-radius: 2px; text-transform: uppercase; border: 1px solid; }
        .bg-pending { background: #fef3c7; color: #92400e; border-color: #fde68a; }
        .bg-inprogress { background: #e0f2fe; color: #0284c7; border-color: #bae6fd; }
        .bg-done { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-header">
        <img src="../assets/images/The-Breeze-1.png" alt="The Breeze Spa Logo" class="sidebar-logo">
    </div>
    <nav>
        <a href="salon.php" class="menu-item active"><i class="fas fa-home" style="margin-right:12px;"></i> Dashboard</a>
    </nav>
    <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt" style="margin-right:12px;"></i> Logout</a>
</aside>

<main class="main-content">
    <header class="topbar">
        <h2>Welcome, <?= htmlspecialchars($_SESSION['user']['name']) ?></h2>
        <span style="color: var(--text-muted);"><?= date("F j, Y") ?></span>
    </header>

    <div class="flat-card">
        <div class="flat-card-header"><h3>Assigned Clients – Hair Salon</h3></div>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Service</th>
                        <th>Staff</th>
                        <th>Client Code</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="worker-table-body">
                    <?php if ($result && $result->num_rows > 0): while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($row['name']) ?></strong></td>
                        <td><?= htmlspecialchars($row['phone']) ?></td>
                        <td><?= htmlspecialchars($row['service_type']) ?></td>
                        <td><?= htmlspecialchars($row['staff_name']) ?></td>
                        <td><code><?= htmlspecialchars($row['client_code']) ?></code></td>
                        <td>
                            <form method="post">
                                <input type="hidden" name="client_id" value="<?= $row['id'] ?>">
                                <select name="status" onchange="this.form.submit()">
                                    <option value="Pending" <?= ($row['status']??'Pending') == 'Pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="In Progress" <?= ($row['status']??'') == 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                                    <option value="Done" <?= ($row['status']??'') == 'Done' ? 'selected' : '' ?>>Done</option>
                                </select>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="6" style="text-align:center; color:var(--text-muted);">No assigned clients found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
    <style>
        /* Real-time Toast Notifications */
        #toast-container {
            position: fixed;
            bottom: 30px;
            right: 30px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            z-index: 1000;
            pointer-events: none;
        }
        .toast-notification {
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(10px);
            color: #ffffff;
            padding: 16px 24px;
            border-radius: 4px;
            border-left: 4px solid #0ea5e9;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -4px rgba(0, 0, 0, 0.3);
            display: flex;
            align-items: center;
            gap: 15px;
            transform: translateX(120%);
            transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            max-width: 380px;
            pointer-events: auto;
            text-align: left;
        }
        .toast-notification.show {
            transform: translateX(0);
        }
        .toast-icon {
            font-size: 24px;
            color: #0ea5e9;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .toast-content h4 {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 2px;
            color: #ffffff;
        }
        .toast-content p {
            font-size: 13px;
            color: #cbd5e1;
            line-height: 1.4;
        }
        .toast-close {
            margin-left: auto;
            cursor: pointer;
            color: #94a3b8;
            background: none;
            border: none;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 4px;
        }
        .toast-close:hover {
            color: #ffffff;
        }
    </style>
    <div id="toast-container"></div>
    <script>
        let clientIds = new Set([
            <?php 
            if ($result && $result->num_rows > 0) {
                $result->data_seek(0);
                while ($row = $result->fetch_assoc()) {
                    echo (int)$row['id'] . ",";
                }
                $result->data_seek(0);
            }
            ?>
        ]);

        function playAlertSound() {
            // Try playing custom MP3 file first
            const audio = new Audio('../assets/sounds/notification.mp3');
            audio.volume = 0.5;
            audio.play().catch(() => {
                // Fallback: Louder rising chime (C5 to E5)
                try {
                    const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                    
                    // Tone 1: C5
                    const osc1 = audioCtx.createOscillator();
                    const gain1 = audioCtx.createGain();
                    osc1.connect(gain1);
                    gain1.connect(audioCtx.destination);
                    osc1.type = 'sine';
                    osc1.frequency.setValueAtTime(523.25, audioCtx.currentTime); 
                    gain1.gain.setValueAtTime(0.3, audioCtx.currentTime);
                    osc1.start();
                    osc1.stop(audioCtx.currentTime + 0.15);
                    
                    // Tone 2: E5 (played slightly later)
                    setTimeout(() => {
                        const osc2 = audioCtx.createOscillator();
                        const gain2 = audioCtx.createGain();
                        osc2.connect(gain2);
                        gain2.connect(audioCtx.destination);
                        osc2.type = 'sine';
                        osc2.frequency.setValueAtTime(659.25, audioCtx.currentTime);
                        gain2.gain.setValueAtTime(0.3, audioCtx.currentTime);
                        osc2.start();
                        osc2.stop(audioCtx.currentTime + 0.2);
                    }, 150);
                } catch(e) {}
            });
        }

        function showNewClientToast(clientName, serviceName) {
            const container = document.getElementById('toast-container') || document.body;
            const toast = document.createElement('div');
            toast.className = 'toast-notification';
            toast.innerHTML = `
                <div class="toast-icon"><i class="fas fa-user-plus"></i></div>
                <div class="toast-content">
                    <h4>New Client Assigned!</h4>
                    <p><strong>${clientName}</strong> is waiting for ${serviceName}.</p>
                </div>
                <button class="toast-close" onclick="this.parentElement.classList.remove('show'); setTimeout(() => this.parentElement.remove(), 400);"><i class="fas fa-times"></i></button>
            `;
            container.appendChild(toast);
            
            playAlertSound();

            setTimeout(() => toast.classList.add('show'), 100);
            
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.classList.remove('show');
                    setTimeout(() => toast.remove(), 400);
                }
            }, 5000);
        }

        function generateRowHtml(client) {
            const status = client.status || 'Pending';
            return `
                <tr>
                    <td><strong>${client.name}</strong></td>
                    <td>${client.phone || ''}</td>
                    <td>${client.service_type || ''}</td>
                    <td>${client.staff_name || 'Unassigned'}</td>
                    <td><code>${client.client_code}</code></td>
                    <td>
                        <form method="post">
                            <input type="hidden" name="client_id" value="${client.id}">
                            <select name="status" onchange="this.form.submit()">
                                <option value="Pending" ${status === 'Pending' ? 'selected' : ''}>Pending</option>
                                <option value="In Progress" ${status === 'In Progress' ? 'selected' : ''}>In Progress</option>
                                <option value="Done" ${status === 'Done' ? 'selected' : ''}>Done</option>
                            </select>
                        </form>
                    </td>
                </tr>
            `;
        }

        async function pollQueueStatus() {
            try {
                const response = await fetch('../api/get_section_queue.php');
                if (!response.ok) return;
                const clients = await response.json();
                
                const tbody = document.getElementById('worker-table-body');
                if (!tbody) return;
                
                if (clients.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; color:var(--text-muted);">No assigned clients found.</td></tr>`;
                    clientIds.clear();
                    return;
                }
                
                let html = '';
                for (const client of clients) {
                    const clientId = parseInt(client.id);
                    
                    if (!clientIds.has(clientId)) {
                        clientIds.add(clientId);
                        const serviceDisplay = client.massage_type ? `${client.service_type} (${client.massage_type})` : client.service_type;
                        showNewClientToast(client.name, serviceDisplay);
                    }
                    
                    html += generateRowHtml(client);
                }
                tbody.innerHTML = html;
            } catch (err) {
                console.error('Error polling queue status:', err);
            }
        }

        setInterval(pollQueueStatus, 5000);
    </script>
</body>
</html>