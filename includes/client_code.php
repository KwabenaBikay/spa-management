<?php
// includes/client_code.php — Clean, sequential & unique client code generator (e.g. CL-001)

/**
 * Generates the next sequential client code (e.g. CL-001, CL-002, ..., CL-1000).
 */
function generate_next_client_code(mysqli $conn): string
{
    $maxNum = 0;
    // Find the highest numeric value among codes like 'CL-001', 'CL-042', etc.
    $res = $conn->query("SELECT MAX(CAST(SUBSTRING(client_code, 4) AS UNSIGNED)) AS max_code 
                         FROM clients 
                         WHERE client_code LIKE 'CL-%' AND client_code REGEXP '^CL-[0-9]+$'");
    if ($res && ($row = $res->fetch_assoc())) {
        $maxNum = (int)($row['max_code'] ?? 0);
    }
    
    $nextNum = $maxNum + 1;
    return sprintf('CL-%03d', $nextNum);
}

/**
 * Ensures schema has UNIQUE constraint on client_code and migrates old random codes.
 */
function ensure_client_code_schema(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    // 1. Check if there are any old random 5-digit client codes (e.g. CL-21500)
    $check = $conn->query("SELECT id FROM clients WHERE client_code REGEXP '^CL-[0-9]{5}$' LIMIT 1");
    if ($check && $check->num_rows > 0) {
        // Renumber existing clients in chronological order (id ASC) starting from 1 (CL-001)
        $allRes = $conn->query("SELECT id FROM clients ORDER BY id ASC");
        if ($allRes) {
            $seq = 1;
            while ($row = $allRes->fetch_assoc()) {
                $newCode = sprintf('CL-%03d', $seq);
                $stmt = $conn->prepare("UPDATE clients SET client_code = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('si', $newCode, $row['id']);
                    $stmt->execute();
                }
                $seq++;
            }
        }
    }

    // 2. Ensure UNIQUE index on client_code
    $idxCheck = $conn->query("SHOW INDEX FROM clients WHERE Key_name = 'idx_client_code'");
    if ($idxCheck && $idxCheck->num_rows === 0) {
        // Clean any possible duplicate codes before adding unique index
        $conn->query("ALTER TABLE clients ADD UNIQUE KEY idx_client_code (client_code)");
    }
}
