<?php
include '../db.php';

if (!isset($_GET['id'])) {
    die("Client ID not provided.");
}

$id = $_GET['id'];

// Fetch existing client
$sql = "SELECT * FROM clients WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Client not found.");
}

$client = $result->fetch_assoc();

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = $_POST['name'];
    $phone = preg_replace('/\D+/', '', $_POST['phone']);
    if (strlen($phone) !== 10) {
        $error = "Phone must be exactly 10 digits.";
    }
    $service_type = $_POST['service_type'];
    $amount = $_POST['amount'];
    $duration = $_POST['duration'];
    $payment_mode = $_POST['payment_mode'];
    $massage_type = $_POST['massage_type'];
    $staff_name = $_POST['staff_name'];
    $section = $_POST['section'];

    if (empty($error)) {
        $update_sql = "UPDATE clients SET name=?, phone=?, service_type=?, amount=?, duration=?, payment_mode=?, massage_type=?, staff_name=?, section=? WHERE id=?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("sssssssssi", $name, $phone, $service_type, $amount, $duration, $payment_mode, $massage_type, $staff_name, $section, $id);
        if ($stmt->execute()) {
            $success = "Client record updated successfully!";
            // Refresh client info
            $client = array_merge($client, $_POST);
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
    <title>Edit Client</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body {
            font-family: "Segoe UI", sans-serif;
            margin: 0;
            padding: 40px 20px;
            background-color: #1e90ff;
        }
        .container {
            max-width: 700px;
            margin: auto;
            background: #ffffff;
            border-radius: 20px;
            padding: 30px;
            backdrop-filter: blur(6px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
            color: #333;
        }
        h2 {
            margin-bottom: 20px;
            color: #222;
        }
        .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 15px; }
        input, select, button {
            width: 100%;
            padding: 12px;
            margin-bottom: 0;
            border: none;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.6);
            font-size: 15px;
        }
        button {
            width: 160px;
            margin: 10px 0 0 0;
            display: inline-block;
            padding: 12px;
            font-size: 15px;
            font-weight: 600;
            background: #1e90ff;
            border: none;
            border-radius: 20px;
            color: white;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            transition: background 0.3s ease;
            grid-column: 1 / -1;
        }
        button:hover {
            background: #187bcd;
        }
        .back-btn {
            display: inline-block;
            margin-bottom: 20px;
            padding: 8px 16px;
            background: #ccc;
            color: #222;
            border-radius: 8px;
            text-decoration: none;
        }
        .back-btn:hover {
            background: #bbb;
        }
        .message {
            margin-bottom: 20px;
            font-weight: bold;
        }
    </style>
</head>
<body>
<div class="container">
    <a class="back-btn" href="view_clients.php">← Back</a>
    <h2>Edit Client Details</h2>

    <?php if (isset($success)) echo "<p style='color:lightgreen;'>$success</p>"; ?>
    <?php if (isset($error)) echo "<p style='color:salmon;'>$error</p>"; ?>

    <form method="post" class="form-grid">
        <input type="text" name="name" value="<?= htmlspecialchars($client['name'] ?? '') ?>" required>
        <input type="tel" name="phone" value="<?= htmlspecialchars($client['phone'] ?? '') ?>" required pattern="^[0-9]{10}$" title="Enter exactly 10 digits" maxlength="10" inputmode="numeric">
        <input type="text" name="service_type" value="<?= htmlspecialchars($client['service_type'] ?? '') ?>" required>
        <input type="number" name="amount" value="<?= htmlspecialchars($client['amount'] ?? '') ?>" required>

        <input id="duration-input" type="text" name="duration" value="<?= htmlspecialchars($client['duration'] ?? '') ?>">

        <select name="payment_mode" id="payment-mode" required>
            <option value="" <?= empty($client['payment_mode']) ? 'selected' : '' ?>>Payment Mode</option>
            <option value="Cash" <?= ($client['payment_mode'] ?? '') === 'Cash' ? 'selected' : '' ?>>Cash</option>
            <option value="Mobile Money" <?= ($client['payment_mode'] ?? '') === 'Mobile Money' ? 'selected' : '' ?>>Mobile Money</option>
        </select>

        <input id="massage-type-input" type="text" name="massage_type" value="<?= htmlspecialchars($client['massage_type'] ?? '') ?>" placeholder="Massage Type (Massage only)">
        <input type="text" name="staff_name" value="<?= htmlspecialchars($client['staff_name'] ?? '') ?>" required>

        <select name="section" id="section-select" required>
            <?php
            $sections = ['Massage', 'Hair Barbering', 'Hair Salon', 'Facials', 'Nails & Manicure'];
            foreach ($sections as $sec) {
                $selected = ($client['section'] ?? '') === $sec ? 'selected' : '';
                echo "<option value=\"$sec\" $selected>$sec</option>";
            }
            ?>
        </select>

        <button type="submit">Update Client</button>
    </form>
    <script>
      (function(){
        var sectionSel = document.getElementById('section-select');
        var durationIn = document.getElementById('duration-input');
        var massageTypeIn = document.getElementById('massage-type-input');
        function applyReq(){
          var isMassage = sectionSel && sectionSel.value === 'Massage';
          if (durationIn) {
            if (isMassage) { durationIn.setAttribute('required','required'); durationIn.placeholder='Duration (required for Massage)'; }
            else { durationIn.removeAttribute('required'); durationIn.placeholder='Duration (optional)'; }
          }
          if (massageTypeIn) {
            if (isMassage) { massageTypeIn.setAttribute('required','required'); }
            else { massageTypeIn.removeAttribute('required'); }
          }
        }
        if (sectionSel) {
          sectionSel.addEventListener('change', applyReq);
          applyReq();
        }
      })();
    </script>
</div>
</body>
</html>
