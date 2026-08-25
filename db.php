<?php
// db.php — Connect to MySQL Database
date_default_timezone_set('UTC');

// LOCAL DEVELOPMENT SETTINGS (XAMPP)
$host = 'localhost';
$user = 'root';      // Default user for XAMPP
$pass = '';          // Default has no password
$dbname = 'spa_db';

// INFINITYFREE PRODUCTION SETTINGS (Uncomment these for deployment)
// $host = 'sql.infinityfree.com';  // Your InfinityFree MySQL host
// $user = 'your_infinityfree_username';  // Your InfinityFree MySQL username
// $pass = 'your_infinityfree_password';  // Your InfinityFree MySQL password
// $dbname = 'your_infinityfree_database_name';  // Your InfinityFree database name

// Create connection
$conn = new mysqli($host, $user, $pass, $dbname,3306);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Optional: Uncomment to check success
// echo "Connected successfully";
?>
