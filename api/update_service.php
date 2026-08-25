<?php
// API endpoint for updating an existing service

session_start();
header('Content-Type: application/json');
include '../db.php';

// Check for POST request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'Invalid request method. Please use POST.']);
    exit;
}

// Authenticate: Ensure the user is an admin
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    http_response_code(403); // Forbidden
    echo json_encode(['error' => 'Access denied. Admin privileges required.']);
    exit;
}

// --- Get Data from the POST request ---
$id = $_POST['service_id'] ?? '';
$name = trim($_POST['service_name'] ?? '');
$section = $_POST['section'] ?? '';
$price = $_POST['price'] ?? '';
$duration = isset($_POST['duration']) ? trim($_POST['duration']) : '';

// --- Validation ---
if (empty($id) || !is_numeric($id) || empty($name) || empty($section) || empty($price)) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Service ID, Name, Section, and Price are required.']);
    exit;
}

if ($section === 'Massage' && $duration === '') {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Duration is required for Massage services.']);
    exit;
}
$durationValue = ($section !== 'Massage' && $duration === '') ? null : $duration;


// --- Database Update Logic ---
$update_sql = "UPDATE services SET service_name = ?, section = ?, price = ?, duration = ? WHERE id = ?";
$stmt = $conn->prepare($update_sql);

// Bind the parameters. Note the types and order: s(string), s, d(double), s, i(integer)
$stmt->bind_param("ssdsi", $name, $section, $price, $durationValue, $id);

if ($stmt->execute()) {
    // Check if any row was actually updated
    if ($stmt->affected_rows > 0) {
        http_response_code(200); // OK
        echo json_encode([
            'success' => true,
            'message' => 'Service updated successfully!'
        ]);
    } else {
        // This happens if the data submitted was the same as the existing data, or the ID was not found.
        http_response_code(200); // Still OK, but we can send a different message
        echo json_encode([
            'success' => true, // Technically not an error
            'message' => 'No changes were made to the service. The data might be the same or the ID was not found.'
        ]);
    }
} else {
    http_response_code(500); // Internal Server Error
    echo json_encode([
        'success' => false,
        'error' => 'Error updating service in the database.'
    ]);
}

$stmt->close();
$conn->close();
?>