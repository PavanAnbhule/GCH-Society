<?php
include 'db.php';
session_start();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST["name"]);
    $email = trim($_POST["email"]);
    $whatsapp = trim($_POST["whatsapp"]);
    $password = trim($_POST["password"]);

    if (!empty($name) && !empty($email) && !empty($password)) {
        // Hash password securely
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        try {
            $stmt = $pdo->prepare("INSERT INTO users (name, email, whatsapp, password, role) VALUES (?, ?, ?, ?, 'user')");
            if ($stmt->execute([$name, $email, $whatsapp, $hashed_password])) {
                echo "✅ Registration successful! <a href='login.php'>Click here to Login</a>";
            } else {
                echo "❌ Error occurred while registering!";
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { // duplicate email
                echo "⚠️ Email already registered!";
            } else {
                echo "Database error: " . $e->getMessage();
            }
        }
    } else {
        echo "⚠️ Please fill all required fields!";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register</title>
</head>
<body>
    <h2>User Registration</h2>
    <form method="POST">
        Name: <input type="text" name="name" required><br><br>
        Email: <input type="email" name="email" required><br><br>
        WhatsApp: <input type="text" name="whatsapp"><br><br>
        Password: <input type="password" name="password" required><br><br>
        <button type="submit">Register</button>
    </form>
</body>
</html>
