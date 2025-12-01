<?php
$host = "127.0.0.1";  // safer than 'localhost' on Windows
$dbname = "investment";
$username = "root";
$password = ""; // default in XAMPP

try {
    $pdo = new PDO("mysql:host=$host;port=3306;dbname=$dbname;charset=utf8mb4", $username, $password);
    echo "✅ DB connection success!";
} catch (PDOException $e) {
    die("❌ DB connection failed: " . $e->getMessage());
}
?>
