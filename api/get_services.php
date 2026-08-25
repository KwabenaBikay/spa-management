<?php
header("Access-Control-Allow-Origin: *"); // <-- ADD THIS LINE

// Our first API endpoint to get all services

// Start session to check for authentication
session_start();

// Set the content type header to JSON. This tells the browser to expect JSON data.
header('Content-Type: application/json');

// Include the database connection. The '../' goes up one directory level.
include '../db.php';

// STEP 1: AUTHENTICATION
// Secure the endpoint. Only admins can access this data.
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    // If not an admin, send a 403 Forbidden status code and a JSON error message.
    http_response_code(403);
    echo json_encode(['error' => 'Access denied. Admin privileges required.']);
    exit; // Stop execution
}

// STEP 2: DATABASE QUERY
// This is the same query from your original services_management.php file.
$query = "SELECT id, service_name, section, price, duration FROM services ORDER BY section, service_name";
$result = $conn->query($query);

// Check if the query was successful
if (!$result) {
    http_response_code(500); // Internal Server Error
    echo json_encode(['error' => 'Database query failed: ' . $conn->error]);
    exit;
}

// STEP 3: FETCH DATA and PREPARE FOR JSON
// Create an empty array to hold our services
$services = [];

// Loop through the results from the database, just like in your original file.
// fetch_assoc() gets one row at a time as an associative array.
while ($row = $result->fetch_assoc()) {
    // Add each row to our $services array.
    $services[] = $row;
}

// STEP 4: OUTPUT THE JSON
// Use json_encode() to convert the PHP array into a JSON string and echo it.
echo json_encode($services);

// Close the database connection
$conn->close();

?>