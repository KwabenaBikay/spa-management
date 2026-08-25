<?php
// API endpoint for adding a new service

session_start();
header('Content-Type: application/json');
include '../db.php';

// Check if the request method is POST. We only want to add data via POST.
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

// Get the data from the POST request (sent from a form)
$name = trim($_POST['service_name'] ?? '');
$section = $_POST['section'] ?? '';
$price = $_POST['price'] ?? '';
$duration = isset($_POST['duration']) ? trim($_POST['duration']) : '';

// --- Validation Logic (copied from your original file) ---
if (empty($name) || empty($section) || empty($price)) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Service Name, Section, and Price are required.']);
    exit;
}

if ($section === 'Massage' && $duration === '') {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Duration is required for Massage services.']);
    exit;
}

// For non-massage sections, treat empty duration as NULL for the database
$durationValue = ($section !== 'Massage' && $duration === '') ? null : $duration;

// --- Database Insertion Logic ---
$insert_sql = "INSERT INTO services (service_name, section, price, duration) VALUES (?, ?, ?, ?)";
$stmt = $conn->prepare($insert_sql);

// Bind the parameters to the SQL query
$stmt->bind_param("ssds", $name, $section, $price, $durationValue);

// Execute the query and send a JSON response
if ($stmt->execute()) {
    http_response_code(201); // 201 Created - a success status for creating new resources
    echo json_encode([
        'success' => true,
        'message' => 'Service added successfully!',
        'service_id' => $conn->insert_id // Optionally return the ID of the new service
    ]);
} else {
    http_response_code(500); // Internal Server Error
    echo json_encode([
        'success' => false,
        'error' => 'Error adding service to the database.'
    ]);
}

$stmt->close();
$conn->close();

?>