<?php
// API endpoint for fetching a single service by its ID

session_start();
header('Content-Type: application/json');
include '../db.php';

// Authenticate: Ensure the user is an admin
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    http_response_code(403); // Forbidden
    echo json_encode(['error' => 'Access denied. Admin privileges required.']);
    exit;
}

// Get the service ID from the URL's query parameter (e.g., ?id=5)
$service_id = $_GET['id'] ?? '';

// Validate the ID
if (empty($service_id) || !is_numeric($service_id)) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'A valid Service ID is required.']);
    exit;
}

// --- Database Query Logic ---
// Use a prepared statement to prevent SQL injection
$query_sql = "SELECT id, service_name, section, price, duration FROM services WHERE id = ?";
$stmt = $conn->prepare($query_sql);
$stmt->bind_param("i", $service_id); // "i" for integer
$stmt->execute();
$result = $stmt->get_result();

// Check if we found a service
if ($result->num_rows > 0) {
    // Fetch the single row of data
    $service = $result->fetch_assoc();
    http_response_code(200); // OK
    echo json_encode($service);
} else {
    // If no rows were returned, the service ID doesn't exist
    http_response_code(404); // Not Found
    echo json_encode(['error' => 'Service not found with the provided ID.']);
}

$stmt->close();
$conn->close();
?>