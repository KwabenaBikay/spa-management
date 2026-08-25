<?php
// Script to add sample massage types to the database
// Run this once to populate the massage_types table

include '../db.php';

// Sample massage types
$massage_types = [
    ['name' => 'Royal Thai', 'description' => 'Traditional Thai massage with stretching and pressure points'],
    ['name' => 'Swedish Massage', 'description' => 'Relaxing massage with long strokes and kneading'],
    ['name' => 'Deep Tissue', 'description' => 'Intensive massage targeting deep muscle layers'],
    ['name' => 'Hot Stone', 'description' => 'Therapeutic massage using heated stones'],
    ['name' => 'Aromatherapy', 'description' => 'Massage with essential oils for relaxation'],
    ['name' => 'Sports Massage', 'description' => 'Massage designed for athletes and active individuals'],
    ['name' => 'Reflexology', 'description' => 'Foot massage targeting pressure points'],
    ['name' => 'Couples Massage', 'description' => 'Romantic massage for two people']
];

// Check if massage_types table is empty
$check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM massage_types");
$check_stmt->execute();
$result = $check_stmt->get_result();
$count = $result->fetch_assoc()['count'];

if ($count == 0) {
    // Insert sample massage types
    $insert_stmt = $conn->prepare("INSERT INTO massage_types (name, description) VALUES (?, ?)");
    
    foreach ($massage_types as $type) {
        $insert_stmt->bind_param("ss", $type['name'], $type['description']);
        $insert_stmt->execute();
    }
    
    echo "Sample massage types have been successfully added to the database!";
    echo "<br>You can now delete this file.";
} else {
    echo "Massage types table already has data. No changes made.";
}
?> 