<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'reception') {
    header("Location: ../login.php");
    exit;
}
include '../db.php';

// Ensure audit_logs table exists for tracking actions
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

// Fetch massage types for dropdown
$massage_types_result = $conn->query("SELECT * FROM massage_types ORDER BY name");
// Build massage type -> price map for autofill
$massagePriceByName = [];
if ($res = $conn->query("SELECT name, price FROM massage_types")) {
    while ($row = $res->fetch_assoc()) {
        $massagePriceByName[$row['name']] = (float)$row['price'];
    }
}

// Prefetch services grouped by section for Other Services dependent dropdown (robust to varying schemas)
$servicesBySection = [];
$servicePriceBySectionAndName = [];
try {
    $services_query = $conn->query("SELECT * FROM services");
    if ($services_query) {
        $rows = [];
        while ($row = $services_query->fetch_assoc()) { $rows[] = $row; }
        if (!empty($rows)) {
            $columns = array_keys($rows[0]);
            $nameCandidates = ['name','service_name','service','title'];
            $sectionCandidates = ['section','service_section','category','type'];
            $priceCandidates = ['price','amount','cost','rate'];
            $nameKey = null; $sectionKey = null;
            $priceKey = null;
            foreach ($nameCandidates as $cand) { if (in_array($cand, $columns, true)) { $nameKey = $cand; break; } }
            foreach ($sectionCandidates as $cand) { if (in_array($cand, $columns, true)) { $sectionKey = $cand; break; } }
            foreach ($priceCandidates as $cand) { if (in_array($cand, $columns, true)) { $priceKey = $cand; break; } }
            foreach ($rows as $svc) {
                $sec = $sectionKey && isset($svc[$sectionKey]) ? $svc[$sectionKey] : 'Other';
                $nm = $nameKey && isset($svc[$nameKey]) ? $svc[$nameKey] : null;
                $pr = $priceKey && isset($svc[$priceKey]) ? (float)$svc[$priceKey] : null;
                if ($nm !== null && $nm !== '') {
                    if (!isset($servicesBySection[$sec])) { $servicesBySection[$sec] = []; }
                    $servicesBySection[$sec][] = $nm;
                    if (!isset($servicePriceBySectionAndName[$sec])) { $servicePriceBySectionAndName[$sec] = []; }
                    $servicePriceBySectionAndName[$sec][$nm] = $pr;
                }
            }
        }
    }
} catch (Throwable $e) {
    // Leave $servicesBySection empty if table/query not available
}

$success = $error = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = $_POST['name'];
    $phone = preg_replace('/\D+/', '', $_POST['phone']);
    if (strlen($phone) !== 10) {
        $error = "Phone must be exactly 10 digits.";
    }
    $service_type = $_POST['service_type'];
    $amount = $_POST['amount'];
    $payment_mode = $_POST['payment_mode'];
    $staff_name = $_POST['staff_name'];
    $section = $_POST['section'];
    $client_code = "CL-" . rand(10000, 99999);

    $duration = $_POST['form_type'] === 'massage' ? $_POST['duration'] : null;
    $massage_type = $_POST['form_type'] === 'massage' ? $_POST['massage_type'] : null;

    $sql = "INSERT INTO clients (name, phone, service_type, amount, duration, payment_mode, massage_type, staff_name, section, client_code)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    if (empty($error)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssssssss", $name, $phone, $service_type, $amount, $duration, $payment_mode, $massage_type, $staff_name, $section, $client_code);
        if ($stmt->execute()) {
            $inserted_id = $conn->insert_id;
            $success = "Client added! Code: $client_code";
            // Audit log create
            $after = [
              'id' => $inserted_id,
              'name' => $name,
              'phone' => $phone,
              'service_type' => $service_type,
              'amount' => $amount,
              'duration' => $duration,
              'payment_mode' => $payment_mode,
              'massage_type' => $massage_type,
              'staff_name' => $staff_name,
              'section' => $section,
              'client_code' => $client_code
            ];
            $userId = (int)($_SESSION['user']['id'] ?? 0);
            $username = $_SESSION['user']['username'] ?? '';
            $role = $_SESSION['user']['role'] ?? '';
            if ($log = $conn->prepare("INSERT INTO audit_logs (user_id, username, role, action, entity, entity_id, before_data, after_data, created_at) VALUES (?,?,?,?,?,?,?,?, NOW())")) {
              $action = 'create'; $entity = 'clients';
              $beforeJson = null; $afterJson = json_encode($after);
              $log->bind_param('issssiss', $userId, $username, $role, $action, $entity, $inserted_id, $beforeJson, $afterJson);
              $log->execute();
            }
        } else {
            $error = "Error: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Add Client | Reception</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
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
      box-shadow: 0 8px 20px rgba(0,0,0,0.1);
      color: #222;
    }
    h2 {
      margin-bottom: 20px;
      color: #222;
    }
   .btn-group {
  display: flex;
  justify-content: center;
  gap: 8px;
  margin-bottom: 30px;
  background: transparent;
  border: none;
  border-radius: 0;
  padding: 0;
  box-shadow: none;
}

.form-btn {
  width: 160px;
  padding: 12px 20px;
  border: 1px solid #d0d7de;
  border-radius: 12px;
  font-weight: 600;
  font-size: 15px;
  background: #ffffff;
  color: #000000;
  cursor: pointer;
  transition: all 0.2s ease;
  position: relative;
  z-index: 1;
}

.form-btn:hover {
  background: #f2f6fb;
  border-color: #c0c7cf;
}

.form-btn.active {
  background: #1e90ff;
  color: #ffffff;
  border-color: #1e90ff;
}
    form {
      display: none;
      margin-top: 20px;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 15px;
    }
    input, select, button {
      width: 100%;
      padding: 12px;
      margin-bottom: 15px;
      border: none;
      border-radius: 10px;
      background: rgba(255, 255, 255, 0.9);
      font-size: 15px;
    }
    form button {
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
form button:hover {
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
  <style>
    @media (max-width: 640px) {
      form { grid-template-columns: 1fr; }
      form button { width: 100%; }
    }
  </style>
</head>
<body>
<div class="container">
  <a class="back-btn" href="dashboard.php">← Back</a>
  <h2>Add Client</h2>

  <?php if ($success): ?><p class="message" style="color: green;"><?= $success ?></p><?php endif; ?>
  <?php if ($error): ?><p class="message" style="color: red;"><?= $error ?></p><?php endif; ?>

  <div class="btn-group">
    <button class="form-btn active" onclick="toggleForm('massage')">Massage Client</button>
    <button class="form-btn" onclick="toggleForm('others')">Other Services</button>
  </div>

  <!-- Massage Form -->
  <form method="post" id="massage-form">
    <input type="hidden" name="form_type" value="massage">
    <input type="hidden" name="service_type" value="Massage">
    <input type="hidden" name="section" value="Massage">
    <input type="text" name="name" placeholder="Client Name" required>
    <input type="tel" name="phone" placeholder="Phone Number (10 digits)" required pattern="^[0-9]{10}$" title="Enter exactly 10 digits" maxlength="10" inputmode="numeric">
    <select name="massage_type" id="massage-type" required>
      <option value="">Select Massage Type</option>
      <?php while ($row = $massage_types_result->fetch_assoc()): ?>
        <option value="<?= $row['name'] ?>"><?= $row['name'] ?></option>
      <?php endwhile; ?>
    </select>
    <input type="text" name="duration" placeholder="Duration (e.g., 30 mins)" required>
    <input type="number" name="amount" id="massage-amount" placeholder="Amount (GHS)" required>
    <select name="payment_mode" required>
      <option value="">Payment Mode</option>
      <option value="Cash">Cash</option>
      <option value="Mobile Money">Mobile Money</option>
    </select>
    <input type="text" name="staff_name" placeholder="Attendant" required>
    <button type="submit">Submit</button>
  </form>

  <!-- Other Services Form -->
  <form method="post" id="others-form">
    <input type="hidden" name="form_type" value="others">
    <input type="text" name="name" placeholder="Client Name" required>
    <input type="tel" name="phone" placeholder="Phone Number (10 digits)" required pattern="^[0-9]{10}$" title="Enter exactly 10 digits" maxlength="10" inputmode="numeric">
    <select name="section" id="others-section" required>
      <option value="">Select Section</option>
      <option value="Hair Barbering">Hair Barbering</option>
      <option value="Hair Salon">Hair Salon</option>
      <option value="Facials">Facials</option>
      <option value="Nails & Manicure">Nails & Manicure</option>
    </select>
    <select name="service_type" id="others-service-type" required disabled>
      <option value="">Service Type</option>
    </select>
    <input type="number" name="amount" id="others-amount" placeholder="Amount (GHS)" required>
    <select name="payment_mode" required>
      <option value="">Payment Mode</option>
      <option value="Cash">Cash</option>
      <option value="Mobile Money">Mobile Money</option>
    </select>
    <input type="text" name="staff_name" placeholder="Attendant" required>
    <button type="submit">Submit</button>
  </form>
</div>

<script>
  const massageBtn = document.querySelectorAll(".form-btn")[0];
  const othersBtn = document.querySelectorAll(".form-btn")[1];
  const massageForm = document.getElementById("massage-form");
  const othersForm = document.getElementById("others-form");
  const othersSection = document.getElementById("others-section");
  const othersServiceType = document.getElementById("others-service-type");
  const massageType = document.getElementById('massage-type');
  const massageAmount = document.getElementById('massage-amount');
  const othersAmount = document.getElementById('others-amount');

  const servicesBySection = <?php echo json_encode($servicesBySection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  const servicePriceBySectionAndName = <?php echo json_encode($servicePriceBySectionAndName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  const massagePriceByName = <?php echo json_encode($massagePriceByName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

  function populateServiceTypesForSection(section) {
    while (othersServiceType.firstChild) {
      othersServiceType.removeChild(othersServiceType.firstChild);
    }
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Service Type';
    othersServiceType.appendChild(placeholder);

    const list = servicesBySection[section] || [];
    list.forEach(function(name) {
      const opt = document.createElement('option');
      opt.value = name;
      opt.textContent = name;
      othersServiceType.appendChild(opt);
    });

    othersServiceType.disabled = list.length === 0;
  }

  function toggleForm(type) {
    if (type === "massage") {
      massageForm.style.display = "grid";
      othersForm.style.display = "none";
      massageBtn.classList.add("active");
      othersBtn.classList.remove("active");
    } else {
      massageForm.style.display = "none";
      othersForm.style.display = "grid";
      massageBtn.classList.remove("active");
      othersBtn.classList.add("active");
      // Reset dependent dropdown when switching to Others
      othersSection && (othersSection.value = '');
      populateServiceTypesForSection('');
    }
  }

  // Wire section change to populate service types
  if (othersSection) {
    othersSection.addEventListener('change', function(e) {
      populateServiceTypesForSection(e.target.value);
      if (othersAmount) { othersAmount.value = ''; }
    });
  }

  // When service type changes for Others, set amount if known
  if (othersServiceType) {
    othersServiceType.addEventListener('change', function(e) {
      var section = othersSection ? othersSection.value : '';
      var service = e.target.value;
      var price = (servicePriceBySectionAndName[section] && servicePriceBySectionAndName[section][service] != null)
        ? servicePriceBySectionAndName[section][service]
        : '';
      if (othersAmount) {
        var hasPrice = price !== '' && price != null;
        othersAmount.value = hasPrice ? price : '';
        if (hasPrice) {
          othersAmount.setAttribute('readonly','readonly');
        } else {
          othersAmount.removeAttribute('readonly');
        }
      }
    });
  }

  // When massage type changes, set amount if price known
  if (massageType) {
    massageType.addEventListener('change', function(e) {
      var t = e.target.value;
      var price = massagePriceByName[t] != null ? massagePriceByName[t] : '';
      if (massageAmount) {
        var hasPrice = price !== '' && price != null;
        massageAmount.value = hasPrice ? price : '';
        if (hasPrice) {
          massageAmount.setAttribute('readonly','readonly');
        } else {
          massageAmount.removeAttribute('readonly');
        }
      }
    });
  }

  // Show default on load
  toggleForm('massage');
</script>
</body>
</html>
