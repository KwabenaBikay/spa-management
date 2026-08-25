<?php
// API endpoint for deleting a service

session_start();
header('Content-Type: application/json');
include '../db.php';

// We'll use POST for simplicity to send the ID of the service to delete.
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

// Get the service ID from the POST request
$service_id = $_POST['service_id'] ?? '';

// Validate the ID
if (empty($service_id) || !is_numeric($service_id)) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'A valid Service ID is required.']);
    exit;
}

// --- Database Deletion Logic ---
$delete_sql = "DELETE FROM services WHERE id = ?";
$stmt = $conn->prepare($delete_sql);
$stmt->bind_param("i", $service_id); // "i" for integer

// Execute the query and send a JSON response
if ($stmt->execute()) {
    // Check if any row was actually deleted
    if ($stmt->affected_rows > 0) {
        http_response_code(200); // OK
        echo json_encode([
            'success' => true,
            'message' => 'Service deleted successfully!'
        ]);
    } else {
        // This happens if the ID doesn't exist in the database
        http_response_code(404); // Not Found
        echo json_encode([
            'success' => false,
            'error' => 'Service not found with the provided ID.'
        ]);
    }
} else {
    http_response_code(500); // Internal Server Error
    echo json_encode([
        'success' => false,
        'error' => 'Error deleting service from the database.'
    ]);
}

$stmt->close();
$conn->close();

?>