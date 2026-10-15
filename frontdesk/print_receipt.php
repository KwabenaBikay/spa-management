<?php
require_once __DIR__ . '/../includes/session.php';
include '../db.php';

if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['frontdesk', 'reception', 'admin', 'supervisor'])) {
    header("Location: ../login.php");
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id < 1) {
    header("Location: dashboard.php");
    exit;
}

$stmt = $conn->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();

if (!$client) {
    header("Location: dashboard.php");
    exit;
}

$service = trim((string)($client['service_type'] ?? ''));
if (!empty($client['massage_type'])) {
    $service .= ' (' . $client['massage_type'] . ')';
}

$issuedBy = $_SESSION['user']['name'] ?? ($_SESSION['user']['username'] ?? 'Front Desk');
$printedAt = date('d M Y, h:i A');
$visitDate = !empty($client['created_at']) ? date('d M Y, h:i A', strtotime($client['created_at'])) : $printedAt;
$amount = number_format((float)($client['amount'] ?? 0), 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title> </title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-blue: #0ea5e9;
            --ink: #111111;
            --ink-soft: #333333;
            --border: #e2e8f0;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body {
            font-family: 'Segoe UI', sans-serif;
            background: #f1f5f9;
            color: var(--ink);
            height: auto;
            min-height: 0;
        }
        body { padding: 24px 16px; }
        .toolbar {
            display: flex;
            justify-content: center;
            gap: 10px;
            padding: 0 0 18px;
        }
        .toolbar a, .toolbar button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border: 1px solid var(--border);
            background: #fff;
            color: var(--ink);
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
            cursor: pointer;
        }
        .toolbar .print-now {
            background: var(--brand-blue);
            border-color: var(--brand-blue);
            color: #fff;
        }
        .print-hint {
            width: 80mm;
            margin: 0 auto 16px;
            font-size: 12px;
            line-height: 1.4;
            color: #475569;
            text-align: center;
        }
        .sheet {
            width: 80mm;
            margin: 0 auto;
            background: #fff;
            padding: 3mm 5mm 8mm;
            border: 1px solid var(--border);
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
        }
        .brand {
            text-align: center;
            padding-bottom: 7px;
            border-bottom: 1px dashed #94a3b8;
        }
        .brand img {
            max-width: 168px;
            max-height: 68px;
            object-fit: contain;
            display: block;
            margin: 0 auto;
        }
        .brand h1 {
            font-size: 18px;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            margin-top: 4px;
            color: #000;
        }
        .receipt-title {
            text-align: center;
            font-size: 15px;
            font-weight: 800;
            letter-spacing: 1.6px;
            text-transform: uppercase;
            margin: 8px 0 7px;
            color: #000;
        }
        .meta {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            color: var(--ink-soft);
            margin-bottom: 7px;
        }
        .meta strong { color: #000; font-size: 15px; }
        .rows {
            border-top: 1px dashed #94a3b8;
            border-bottom: 1px dashed #94a3b8;
            padding: 3px 0;
        }
        .row {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            padding: 5px 0;
            font-size: 16px;
        }
        .row span:first-child { color: var(--ink-soft); }
        .row span:last-child { font-weight: 700; text-align: right; color: #111; }
        .total {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            padding: 10px 0 6px;
            font-size: 20px;
            font-weight: 800;
            color: #000;
        }
        .total span:last-child { color: #000; }
        .thanks {
            text-align: center;
            font-size: 14px;
            color: var(--ink-soft);
            margin-top: 10px;
            line-height: 1.35;
        }
        .thanks strong { display: block; color: #000; margin-bottom: 3px; font-size: 15px; }
        .footer-note {
            text-align: center;
            font-size: 12px;
            color: var(--ink-soft);
            margin-top: 8px;
        }
        @page {
            size: portrait;
            margin: 0;
        }
        @media print {
            html, body {
                width: auto;
                height: auto !important;
                min-height: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
            }
            .toolbar,
            .print-hint { display: none !important; }
            .sheet {
                width: 80mm;
                margin: 0;
                border: none;
                box-shadow: none;
                padding: 2mm 5mm 8mm;
            }
            .receipt-title,
            .total,
            .total span,
            .brand h1,
            .meta strong {
                color: #000 !important;
            }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" class="print-now" onclick="window.print()"><i class="fas fa-print"></i> Print Receipt</button>
        <a href="dashboard.php"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>
    <p class="print-hint">Keep <strong>Margins</strong> on None and <strong>Headers and footers</strong> off.</p>
    <div class="sheet">
        <div class="brand">
            <img src="../assets/images/The-Breeze-1.png" alt="The Breeze Spa">
            <h1>The Breeze Spa</h1>
        </div>

        <div class="receipt-title"><?= !empty($client['voided_at']) ? 'VOID' : 'Payment Receipt' ?></div>
        <div class="meta">
            <div>Receipt<br><strong><?= htmlspecialchars($client['client_code'] ?? '—') ?></strong></div>
            <div style="text-align:right;">Date<br><strong><?= htmlspecialchars($visitDate) ?></strong></div>
        </div>

        <div class="rows">
            <div class="row"><span>Client</span><span><?= htmlspecialchars($client['name'] ?? '—') ?></span></div>
            <div class="row"><span>Phone</span><span><?= htmlspecialchars($client['phone'] ?? '—') ?></span></div>
            <div class="row"><span>Section</span><span><?= htmlspecialchars($client['section'] ?? '—') ?></span></div>
            <div class="row"><span>Service</span><span><?= htmlspecialchars($service !== '' ? $service : '—') ?></span></div>
            <?php if (!empty($client['duration'])): ?>
            <div class="row"><span>Duration</span><span><?= htmlspecialchars($client['duration']) ?></span></div>
            <?php endif; ?>
            <div class="row"><span>Attendant</span><span><?= htmlspecialchars($client['staff_name'] ?? 'Unassigned') ?></span></div>
            <div class="row"><span>Payment</span><span><?= htmlspecialchars($client['payment_mode'] ?? '—') ?></span></div>
        </div>

        <div class="total">
            <span><?= !empty($client['voided_at']) ? 'Voided' : 'Amount Paid' ?></span>
            <span>GHS <?= !empty($client['voided_at']) ? '0.00' : number_format(client_net_amount($client), 2) ?></span>
        </div>
        <?php if (empty($client['voided_at']) && (float)($client['refund_amount'] ?? 0) > 0): ?>
        <div class="row"><span>Refund</span><span>GHS <?= number_format((float)$client['refund_amount'], 2) ?></span></div>
        <?php endif; ?>

        <div class="thanks">
            <strong>Thank you for visiting The Breeze</strong>
            Please keep this receipt for your records.
        </div>
        <div class="footer-note">Issued by <?= htmlspecialchars($issuedBy) ?> · Printed <?= htmlspecialchars($printedAt) ?></div>
    </div>

    <script>
        window.addEventListener('load', () => {
            document.title = ' ';
            setTimeout(() => window.print(), 250);
        });
    </script>
</body>
</html>
