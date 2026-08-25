<?php
session_start();
include '../db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'reception') {
    header("Location: ../login.php");
    exit;
}

// Fetch counts from database by section
$sections = ['Massage', 'Hair Barbering', 'Hair Salon', 'Facials', 'Nails & Manicure'];
$section_counts = [];

foreach ($sections as $section) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM clients WHERE section = ?");
    $stmt->bind_param("s", $section);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $section_counts[$section] = $result['count'];
}

$total_result = $conn->query("SELECT COUNT(*) as total FROM clients");
$total_clients = $total_result->fetch_assoc()['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Reception Dashboard | Spa Management</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
      background-color: #1e90ff;
      font-family: "Segoe UI", sans-serif;
    }

    .sidebar {
      width: 240px;
      background: rgba(30, 144, 255, 0.7);
      backdrop-filter: blur(16px) saturate(140%);
      -webkit-backdrop-filter: blur(16px) saturate(140%);
      border-right: 1px solid rgba(255, 255, 255, 0.28);
      color: white;
      position: fixed;
      top: 0;
      left: 0;
      height: 100vh;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
      z-index: 100;
    }
    .sidebar h2 { font-size: 20px; padding: 20px; }
    .sidebar a {
      padding: 14px 20px;
      color: white;
      text-decoration: none;
      display: block;
      transition: 0.3s;
    }
    .sidebar a:hover, .sidebar a.active {
      background-color: rgba(255, 255, 255, 0.15);
    }
    .logout-btn {
      margin: 20px;
      background-color: crimson;
      padding: 10px;
      text-align: center;
      border-radius: 8px;
    }

    .main-content {
      margin-left: 240px;
      width: calc(100% - 240px);
      padding: 30px;
    }

    .topbar {
      background: rgba(255, 255, 255, 0.15);
      backdrop-filter: blur(18px) saturate(140%);
      -webkit-backdrop-filter: blur(18px) saturate(140%);
      border-bottom: 1px solid rgba(255, 255, 255, 0.28);
      color: #ffffff;
      padding: 15px 20px;
      border-radius: 12px;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 30px;
      position: sticky;
      top: 0;
      z-index: 90;
    }

    .topbar h2 { font-size: 22px; margin: 0; }

    .card-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 25px;
      margin-bottom: 40px;
    }

    .card, .chart-container {
      background: #ffffff;
      backdrop-filter: blur(20px);
      padding: 25px;
      border-radius: 16px;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.07);
      transition: transform 0.2s ease;
    }

    .card:hover {
      transform: translateY(-3px);
    }

    .card i {
      font-size: 32px;
      color: #1e90ff;
      margin-bottom: 12px;
    }

    .card h3 {
      font-size: 18px;
      margin: 5px 0;
    }

    .card p {
      font-size: 20px;
      font-weight: bold;
      color: #222;
    }

    .card a {
      display: inline-block;
      margin-top: 8px;
      color: #ffffff;
      background: rgba(255,255,255,0.25);
      padding: 6px 10px;
      border-radius: 8px;
      text-decoration: none;
      font-weight: 500;
    }

    .card a:hover {
      text-decoration: underline;
    }


    .chart-container {
      padding: 30px;
      max-width: 100%;
      margin-top: 30px;
      height: 500px;
    }

    canvas {
      width: 100% !important;
      height: 100% !important;
    }

    @media (max-width: 768px) {
      .sidebar {
        width: 100%;
        height: auto;
        position: relative;
        flex-direction: row;
      }
      .main-content {
        margin-left: 0;
        width: 100%;
      }
    }
  </style>
</head>
<body>

<!-- Sidebar -->
<aside class="sidebar">
  <div>
    <h2>Reception</h2>
    <a href="dashboard.php" class="active"><i class="fas fa-home"></i> Dashboard</a>
    <a href="add_client.php"><i class="fas fa-user-plus"></i> Add Client</a>
    <a href="view_clients.php"><i class="fas fa-users"></i> View Clients</a>
  </div>
  <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
</aside>

<!-- Main Content -->
<main class="main-content">
  <div class="topbar">
    <h2>Welcome, <?= htmlspecialchars($_SESSION['user']['name']) ?>!</h2>
    <span><?= date("F j, Y") ?></span>
  </div>

  <!-- Feature Cards -->
  <div class="card-grid">
    <div class="card">
      <i class="fas fa-user-plus"></i>
      <h3>Add Client</h3>
      <a href="add_client.php">Go to Form</a>
    </div>
    <div class="card">
      <i class="fas fa-list"></i>
      <h3>View Clients</h3>
      <a href="view_clients.php">Open List</a>
    </div>
    <div class="card">
      <i class="fas fa-edit"></i>
      <h3>Edit Client</h3>
      <a href="view_clients.php">Search & Edit</a>
    </div>
  </div>

  <!-- Analytics Chart -->
  <div class="chart-container">
    <canvas id="clientChart"></canvas>
  </div>
</main>

<script>
  const ctx = document.getElementById('clientChart').getContext('2d');
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: <?= json_encode(array_keys($section_counts)) ?>,
      datasets: [{
        label: 'Number of Clients',
        data: <?= json_encode(array_values($section_counts)) ?>,
        backgroundColor: '#1e90ff',
        borderColor: '#1e90ff',
        borderWidth: 2,
        borderRadius: 8,
        borderSkipped: false,
        barThickness: 50
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { 
          display: false 
        },
        title: {
          display: true,
          text: 'Client Visits by Section',
          color: '#2d3436',
          font: {
            size: 20,
            weight: 'bold'
          },
          padding: {
            top: 10,
            bottom: 20
          }
        }
      },
      scales: {
        x: {
          grid: {
            display: false
          },
          ticks: {
            color: '#666',
            font: {
              size: 12,
              weight: '500'
            }
          }
        },
        y: {
          beginAtZero: true,
          grid: {
            color: 'rgba(0,0,0,0.1)',
            drawBorder: false
          },
          ticks: { 
            stepSize: 1,
            color: '#666',
            font: {
              size: 12,
              weight: '500'
            }
          }
        }
      },
      elements: {
        bar: {
          borderWidth: 0
        }
      }
    }
  });
</script>
</body>
</html>
