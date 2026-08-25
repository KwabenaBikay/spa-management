<?php
session_start();
include '../db.php';

// RBAC: Allow Admin and Supervisor
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin', 'supervisor'])) {
    header("Location: login.php");
    exit;
}

$user_role = $_SESSION['user']['role'];

// Add new service
if (isset($_POST['add_service']) && in_array($user_role, ['admin', 'supervisor'])) {
    $name = trim($_POST['service_name']);
    $section = $_POST['section'];
    $price = $_POST['price'];
    $duration = trim($_POST['duration'] ?? ''); 

    $insert_service = "INSERT INTO services (service_name, section, price, duration) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($insert_service);
    $stmt->bind_param("ssds", $name, $section, $price, $duration);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = "Service added successfully!";
    } else {
        $_SESSION['error'] = "Error adding service.";
    }
    header("Location: services_management.php");
    exit;
}

// Delete service
if (isset($_POST['delete_service']) && in_array($user_role, ['admin', 'supervisor'])) {
    $id = $_POST['service_id'];
    $stmt = $conn->prepare("DELETE FROM services WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['message'] = "Service deleted successfully!";
    } else {
        $_SESSION['error'] = "Error deleting service.";
    }
    header("Location: services_management.php");
    exit;
}

// --- SEARCH & PAGINATION LOGIC ---
$search = trim($_GET['search'] ?? '');
$search_param = "%$search%";

$limit = 7; 
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Combine standard services and massage types (rendered as 3 options: 60m, 90m, 120m)
$sql_union = "
    SELECT id, service_name, section, price, duration, 'service' AS type FROM services
    UNION ALL
    SELECT id, CONCAT(name, ' (60 min)') AS service_name, 'Massage' AS section, price_60 AS price, '60 min' AS duration, 'massage' AS type FROM massage_types WHERE price_60 > 0
    UNION ALL
    SELECT id, CONCAT(name, ' (90 min)') AS service_name, 'Massage' AS section, price_90 AS price, '90 min' AS duration, 'massage' AS type FROM massage_types WHERE price_90 > 0
    UNION ALL
    SELECT id, CONCAT(name, ' (120 min)') AS service_name, 'Massage' AS section, price_120 AS price, '120 min' AS duration, 'massage' AS type FROM massage_types WHERE price_120 > 0
";

if ($search !== '') {
    // Count union search results
    $sql_search_union = "
        SELECT id, service_name, section, price, duration, 'service' AS type FROM services WHERE service_name LIKE ? OR section LIKE ?
        UNION ALL
        SELECT id, CONCAT(name, ' (60 min)') AS service_name, 'Massage' AS section, price_60 AS price, '60 min' AS duration, 'massage' AS type FROM massage_types WHERE name LIKE ? AND price_60 > 0
        UNION ALL
        SELECT id, CONCAT(name, ' (90 min)') AS service_name, 'Massage' AS section, price_90 AS price, '90 min' AS duration, 'massage' AS type FROM massage_types WHERE name LIKE ? AND price_90 > 0
        UNION ALL
        SELECT id, CONCAT(name, ' (120 min)') AS service_name, 'Massage' AS section, price_120 AS price, '120 min' AS duration, 'massage' AS type FROM massage_types WHERE name LIKE ? AND price_120 > 0
    ";
    
    $stmt_total = $conn->prepare("SELECT COUNT(*) AS total FROM ($sql_search_union) temp");
    $stmt_total->bind_param("sssss", $search_param, $search_param, $search_param, $search_param, $search_param);
    $stmt_total->execute();
    $total_rows = $stmt_total->get_result()->fetch_assoc()['total'];

    $stmt_services = $conn->prepare("SELECT * FROM ($sql_search_union) temp ORDER BY section, service_name LIMIT ? OFFSET ?");
    $stmt_services->bind_param("sssssii", $search_param, $search_param, $search_param, $search_param, $search_param, $limit, $offset);
    $stmt_services->execute();
    $services_result = $stmt_services->get_result();
} else {
    $total_rows = $conn->query("SELECT COUNT(*) AS total FROM ($sql_union) temp")->fetch_assoc()['total'];
    $services_result = $conn->query("$sql_union ORDER BY section, service_name LIMIT $limit OFFSET $offset");
}

$total_pages = ceil($total_rows / $limit);
$search_query = ($search !== '') ? '&search=' . urlencode($search) : '';

$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Services Management | The Breeze Spa</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
  <style>
    :root {
        --brand-blue: #0ea5e9; --brand-dark: #0f172a; --bg-body: #f1f5f9; --bg-card: #ffffff;
        --text-main: #1e293b; --text-muted: #64748b; --border-color: #e2e8f0;
        --sidebar-width: 250px; --topbar-height: 60px;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
    body { background-color: var(--bg-body); color: var(--text-main); display: flex; min-height: 100vh; overflow-x: hidden; font-size: 15px; }

    .sidebar { width: var(--sidebar-width); background-color: var(--brand-dark); color: #ffffff; position: fixed; height: 100vh; left: 0; top: 0; display: flex; flex-direction: column; z-index: 100; border-right: 1px solid #1e293b; }
    .sidebar-header { padding: 20px; border-bottom: 1px solid #1e293b; display: flex; align-items: center; gap: 15px; }
    .sidebar-logo { width: 90%; max-height: 80px; object-fit: contain; }
    .user-profile { padding: 20px; border-bottom: 1px solid #1e293b; }
    .user-profile h4 { color: #ffffff; font-size: 15px; font-weight: 600; margin-bottom: 4px; }
    .user-profile p { color: #94a3b8; font-size: 12px; text-transform: uppercase; }
    .sidebar-menu { padding: 20px 0; flex: 1; }
    .menu-item { display: flex; align-items: center; padding: 12px 24px; color: #cbd5e1; text-decoration: none; font-size: 14px; font-weight: 500; border-left: 3px solid transparent; transition: all 0.2s; }
    .menu-item i { width: 24px; font-size: 16px; margin-right: 10px; color: #64748b; }
    .menu-item:hover, .menu-item.active { background-color: #1e293b; color: #ffffff; border-left-color: var(--brand-blue); }
    .menu-item:hover i, .menu-item.active i { color: var(--brand-blue); }
    .logout-btn { display: flex; align-items: center; padding: 15px 24px; color: #ef4444; text-decoration: none; font-size: 14px; font-weight: 600; border-top: 1px solid #1e293b; background-color: #0b1221; }
    .logout-btn i { margin-right: 10px; }
    .logout-btn:hover { background-color: #ef4444; color: #ffffff; }

    .main-content { flex: 1; margin-left: var(--sidebar-width); display: flex; flex-direction: column; min-height: 100vh; }
    .topbar { height: var(--topbar-height); background-color: var(--bg-card); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 30px; position: sticky; top: 0; z-index: 90; }
    .topbar h2 { font-size: 18px; font-weight: 600; color: var(--text-main); }
    .content-wrapper { padding: 30px; flex: 1; max-width: 1200px; margin: 0 auto; width: 100%;}

    .alert { padding: 15px 20px; border-radius: 2px; margin-bottom: 25px; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 10px; border-left: 4px solid; }
    .alert-success { background: #d1fae5; color: #065f46; border-left-color: #10b981; }
    .alert-error { background: #fee2e2; color: #991b1b; border-left-color: #dc2626; }

    .flat-card { background: var(--bg-card); border: 1px solid var(--border-color); padding: 30px; margin-bottom: 30px; border-radius: 0; }
    .flat-card-header { border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
    .flat-card-header h3 { font-size: 16px; font-weight: 600; color: var(--text-main); text-transform: uppercase; letter-spacing: 0.5px; margin: 0; }

    .form-row { display: flex; flex-wrap: wrap; gap: 15px; align-items: center; }
    .form-control { flex: 1; min-width: 180px; padding: 10px 14px; border: 1px solid var(--border-color); border-radius: 2px; font-size: 14px; background: #f8fafc; outline: none; transition: all 0.2s; }
    .form-control:focus { border-color: var(--brand-blue); background: #ffffff; }
    
    .btn { padding: 10px 18px; font-size: 14px; font-weight: 600; border: none; border-radius: 2px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; transition: all 0.2s; }
    .btn-primary { background: var(--brand-blue); color: #fff; }
    .btn-primary:hover { background: #0284c7; }
    .btn-danger { background: #fef2f2; color: #dc2626; border: 1px solid #fca5a5; }
    .btn-danger:hover { background: #fee2e2; }
    .btn-outline { background: #f8fafc; border: 1px solid var(--border-color); color: var(--text-main); }
    .btn-sm { padding: 6px 12px; font-size: 13px; }

    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { padding: 14px; text-align: left; border-bottom: 1px solid var(--border-color); }
    th { background: #f8fafc; font-weight: 600; color: var(--text-muted); text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px; }
    
    .section-badge { display: inline-block; padding: 4px 10px; font-size: 11px; font-weight: 600; text-transform: uppercase; border-radius: 2px; background: #f1f5f9; color: var(--text-main); border: 1px solid var(--border-color); }
    .price-tag { font-weight: 600; color: #10b981; }

    .pagination { display: flex; justify-content: flex-end; align-items: center; margin-top: 25px; gap: 5px; }
    .page-link { padding: 6px 12px; background: var(--bg-card); border: 1px solid var(--border-color); color: var(--text-main); text-decoration: none; border-radius: 2px; font-size: 13px; font-weight: 500; transition: all 0.2s; }
    .page-link:hover { border-color: var(--brand-blue); color: var(--brand-blue); }
    .page-link.active { background: var(--brand-blue); color: #fff; border-color: var(--brand-blue); }
    .page-info { margin-right: 15px; font-size: 13px; color: var(--text-muted); }

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
            <p><i class="fas fa-shield-alt" style="margin-right: 5px; font-size: 10px;"></i> <?= htmlspecialchars($user_role) ?></p>
        </div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item"><i class="fas fa-square-poll-vertical"></i> Dashboard Overview</a>
            <a href="staff_management.php" class="menu-item"><i class="fas fa-users-rectangle"></i> Staff Roster</a>
            <a href="services_management.php" class="menu-item active"><i class="fas fa-list-ul"></i> Services & Pricing</a>
            
            <?php if ($user_role === 'admin' || $user_role === 'supervisor'): ?>
            <a href="massage_management.php" class="menu-item"><i class="fas fa-spa"></i> Massage Categories</a>
            <?php endif; ?>
            
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
        <h2>Services Catalog</h2>
    </header>

    <div class="content-wrapper">
      <?php if ($message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php if (in_array($user_role, ['admin', 'supervisor'])): ?>
      <div class="flat-card">
        <div class="flat-card-header"><h3>Add New Service</h3></div>
        <form method="post">
          <div class="form-row">
            <input type="text" name="service_name" placeholder="Service Name" class="form-control" required>
            <select name="section" class="form-control" required>
              <option value="" disabled selected>Select Section</option>
              <option value="Massage">Massage</option>
              <option value="Pedicure & Manicure">Pedicure & Manicure</option>
              <option value="Barbering">Barbering</option>
              <option value="Hair Salon">Hair Salon</option>
              <option value="Facials">Facials</option>
              <option value="Make-Up services">Make-Up services</option>
            </select>
            <input type="number" name="price" placeholder="Price (GHS)" class="form-control" step="0.01" required>
            <input type="text" name="duration" placeholder="Duration (Optional)" class="form-control">
            <button type="submit" name="add_service" class="btn btn-primary"><i class="fas fa-plus"></i> Add</button>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <div class="flat-card">
        <div class="flat-card-header">
            <h3>Active Services</h3>
            <form method="get" style="display: flex; gap: 10px;">
                <input type="text" name="search" placeholder="Search service or section..." value="<?= htmlspecialchars($search) ?>" class="form-control" style="width: 250px; padding: 8px 12px;">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Query</button>
                <?php if ($search !== ''): ?>
                    <a href="services_management.php" class="btn btn-outline btn-sm" title="Clear Search"><i class="fas fa-times"></i></a>
                <?php endif; ?>
            </form>
        </div>
        <div style="overflow-x: auto;">
          <table>
            <thead>
              <tr>
                <th>Service Name</th>
                <th>Section</th>
                <th>Price (GHS)</th>
                <th>Duration</th>
                <?php if (in_array($user_role, ['admin', 'supervisor'])): ?><th>Actions</th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php if ($services_result && $services_result->num_rows > 0): ?>
                  <?php while ($service = $services_result->fetch_assoc()): ?>
                  <tr>
                    <td style="font-weight: 500; color: var(--text-main);"><?= htmlspecialchars($service['service_name']) ?></td>
                    <td><span class="section-badge"><?= htmlspecialchars($service['section']) ?></span></td>
                    <td class="price-tag"><?= number_format($service['price'], 2) ?></td>
                    <td>
                        <?php if(!empty($service['duration'])): ?>
                            <i class="far fa-clock" style="color:var(--text-muted); margin-right:5px;"></i> <?= htmlspecialchars($service['duration']) ?>
                        <?php else: ?>
                            <span style="color:var(--text-muted); font-size:13px; font-style:italic;">N/A</span>
                        <?php endif; ?>
                    </td>
                    
                    <?php if (in_array($user_role, ['admin', 'supervisor'])): ?>
                    <td style="display: flex; gap: 8px;">
                      <?php if (($service['type'] ?? 'service') === 'service'): ?>
                        <a href="edit_service.php?id=<?= $service['id'] ?>" class="btn btn-outline btn-sm" title="Edit Service"><i class="fas fa-pen"></i></a>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this service permanently?')">
                          <input type="hidden" name="service_id" value="<?= $service['id'] ?>">
                          <button type="submit" name="delete_service" class="btn btn-danger btn-sm" title="Delete Service"><i class="fas fa-trash"></i></button>
                        </form>
                      <?php else: ?>
                        <a href="edit_massage.php?id=<?= $service['id'] ?>" class="btn btn-outline btn-sm" title="Edit Massage"><i class="fas fa-pen"></i></a>
                        <form method="post" action="massage_management.php" style="display:inline;" onsubmit="return confirm('Delete this massage type permanently?')">
                          <input type="hidden" name="massage_id" value="<?= $service['id'] ?>">
                          <button type="submit" name="delete_massage" class="btn btn-danger btn-sm" title="Delete Massage"><i class="fas fa-trash"></i></button>
                        </form>
                      <?php endif; ?>
                    </td>
                    <?php endif; ?>
                  </tr>
                  <?php endwhile; ?>
              <?php else: ?>
                  <tr>
                      <td colspan="<?= in_array($user_role, ['admin', 'supervisor']) ? '5' : '4' ?>" style="text-align: center; padding: 40px; color: var(--text-muted);">
                          <i class="fas fa-search" style="font-size: 24px; margin-bottom: 10px; display: block;"></i>
                          No services found matching your criteria.
                      </td>
                  </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <span class="page-info">Showing page <?= $page ?> of <?= $total_pages ?></span>
            
            <?php if($page > 1): ?>
                <a href="?page=1<?= $search_query ?>" class="page-link" title="First Page"><i class="fas fa-angle-double-left"></i></a>
                <a href="?page=<?= $page - 1 ?><?= $search_query ?>" class="page-link" title="Previous Page"><i class="fas fa-chevron-left"></i></a>
            <?php endif; ?>

            <?php
            $max_visible_pages = 5;
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $start_page + $max_visible_pages - 1);
            if ($end_page - $start_page + 1 < $max_visible_pages) {
                $start_page = max(1, $end_page - $max_visible_pages + 1);
            }
            for ($i = $start_page; $i <= $end_page; $i++): ?>
                <a href="?page=<?= $i ?><?= $search_query ?>" class="page-link <?= ($i == $page) ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>

            <?php if($page < $total_pages): ?>
                <a href="?page=<?= $page + 1 ?><?= $search_query ?>" class="page-link" title="Next Page"><i class="fas fa-chevron-right"></i></a>
                <a href="?page=<?= $total_pages ?><?= $search_query ?>" class="page-link" title="Last Page"><i class="fas fa-angle-double-right"></i></a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

      </div>
    </div>
  </main>
</body>
</html>