<?php
session_start();
require 'db.php';

$email = $password = "";
$email_err = $password_err = $login_err = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (empty(trim($_POST["email"]))) {
        $email_err = "Please enter your email.";
    } else {
        $email = trim($_POST["email"]);
    }

    if (empty(trim($_POST["password"]))) {
        $password_err = "Please enter your password.";
    } else {
        $password = trim($_POST["password"]);
    }

    if (empty($email_err) && empty($password_err)) {
        $stmt = $pdo->prepare("SELECT id, name, email, password FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->rowCount() == 1) {
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (password_verify($password, $user['password'])) {
                // Password correct, create session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];

                // Redirect to a protected page or welcome page
                header("Location: welcome.php");
                exit;
            } else {
                $login_err = "Invalid email or password.";
            }
        } else {
            $login_err = "Invalid email or password.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Login</title>
<link rel="stylesheet" href="style.css" />
</head>
<body>
<div class="container">
    <div class="header"><h2>Login</h2></div>
    <?php
    if (!empty($login_err)) {
        echo '<p class="error">' . $login_err . '</p>';
    }
    ?>
    <form action="login.php" method="post" autocomplete="on">
        <input type="email" name="email" placeholder="Email" value="<?php echo htmlspecialchars($email); ?>" required />
        <span class="error"><?php echo $email_err; ?></span>

        <input type="password" name="password" placeholder="Password" required />
        <span class="error"><?php echo $password_err; ?></span>

        <button type="submit">Login</button>
    </form>

    <!-- New option added here -->
    <p class="register-option">
        If you are not registered, <a href="user_register.php">Register here</a>.
    </p>
</div>
</body>
</html>
