<?php
include '../db.php';

// Handle delete action
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $delete_sql = "DELETE FROM clients WHERE id = ?";
    $stmt = $conn->prepare($delete_sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $message = "Client record deleted successfully.";
}

// Handle search
$search = "";
if (isset($_GET['search'])) {
    $search = $_GET['search'];
    $sql = "SELECT * FROM clients WHERE name LIKE ? OR phone LIKE ? ORDER BY created_at DESC";
    $stmt = $conn->prepare($sql);
    $like = "%$search%";
    $stmt->bind_param("ss", $like, $like);
} else {
    $sql = "SELECT * FROM clients ORDER BY created_at DESC";
    $stmt = $conn->prepare($sql);
}

$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reception - View Clients</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: "Segoe UI", sans-serif;
            background-color: #1e90ff;
            color: #222;
        }

        .main-container {
    width: 80vw;
    margin: 30px auto;
    padding: 30px;
    background: #ffffff;
    border-radius: 20px;
    backdrop-filter: blur(6px);
    box-shadow: 0 6px 24px rgba(0,0,0,0.1);
}

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(0,0,0,0.15);
        }

        .topbar h2 {
            font-size: 26px;
            margin: 0;
        }

        .topbar form {
            display: flex;
            gap: 10px;
        }

        .topbar input[type="text"] {
            padding: 10px 14px;
            border-radius: 10px;
            border: none;
            width: 260px;
            background-color: rgba(255, 255, 255, 0.8);
            color: #222;
        }

        .topbar button {
            padding: 10px 18px;
            border: none;
            background-color: #1e90ff;
            color: white;
            border-radius: 10px;
            cursor: pointer;
        }

        .topbar button:hover {
            background-color: #187bcd;
        }

        .back-btn {
            margin-bottom: 20px;
            display: inline-block;
            background-color: #ccc;
            color: #222;
            padding: 8px 16px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 500;
        }

        .back-btn:hover {
            background-color: #bbb;
        }

        .success-msg {
            color: green;
            margin-bottom: 15px;
            font-weight: 500;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            background: rgba(255, 255, 255, 0.98);
            border-collapse: collapse;
            color: #222;
            font-size: 15px;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        th, td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }

        th {
            background-color: rgba(200, 200, 200, 0.4);
        }

        td a {
            color: #444;
            text-decoration: none;
            font-weight: 500;
        }

        .btn-danger {
            background-color: crimson;
            color: white;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 13px;
            text-decoration: none;
        }

        .btn-danger:hover {
            background-color: darkred;
        }

        @media screen and (max-width: 768px) {
            .topbar {
                flex-direction: column;
                align-items: flex-start;
            }

            .topbar form {
                width: 100%;
            }

            .topbar input[type="text"], .topbar button {
                width: 100%;
            }

            table {
                font-size: 13px;
            }
        }
    </style>
</head>
<body>

<div class="main-container">

    <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back</a>

    <div class="topbar">
        <h2>Client Records</h2>
        <form method="get" action="">
            <input type="text" name="search" placeholder="Search by name or phone" value="<?= htmlspecialchars($search) ?>">
            <button type="submit">Search</button>
        </form>
    </div>

    <?php if (isset($message)) echo "<p class='success-msg'>$message</p>"; ?>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Service</th>
                    <th>Section</th>
                    <th>Amount</th>
                    <th>Staff</th>
                    <th>Code</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['name']) ?></td>
                        <td><?= htmlspecialchars($row['phone']) ?></td>
                        <td><?= htmlspecialchars($row['service_type']) ?></td>
                        <td><?= htmlspecialchars($row['section']) ?></td>
                        <td>GHS <?= number_format($row['amount'], 2) ?></td>
                        <td><?= htmlspecialchars($row['staff_name']) ?></td>
                        <td><?= htmlspecialchars($row['client_code']) ?></td>
                        <td>
                            <a href="edit_client.php?id=<?= $row['id'] ?>">Edit</a> |
                            <a class="btn-danger" href="?delete=<?= $row['id'] ?>" onclick="return confirm('Delete this client?')">Delete</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

</div>

</body>
</html>
