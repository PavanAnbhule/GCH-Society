<?php
require_once "db.php";

// ✅ Load PHPMailer (Composer autoload + email config)
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/email/email_config.php';
$message = ""; // To store success or error message

// Initialize form values
$first_name = $last_name = $email = $contact_number = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $first_name = trim($_POST["first_name"]);
    $last_name = trim($_POST["last_name"]);
    $email = trim($_POST["email"]);
    $contact_number = trim($_POST["contact_number"]);
    $password = trim($_POST["password"]);
    $confirm_password = trim($_POST["confirm_password"]);

    // Validate
    if ($password !== $confirm_password) {
        $message = "<div class='message error'>⚠️ Passwords do not match!</div>";
    } else {
        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Insert into database
        $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, email, contact_number, password, role) 
                               VALUES (?, ?, ?, ?, ?, 'user')");
        try {
            $stmt->execute([$first_name, $last_name, $email, $contact_number, $hashed_password]);
            $message = "<div class='message success'>✅ Registration successful! <a href='index.php'>Login Here</a></div>";

            // ✅ Send registration email
            $subject = "Registration Successful";
            $body = "
                Hello <b>{$first_name} {$last_name}</b>,<br><br>
                Your registration is successfully completed.<br><br>
                ✅ <b>Login ID:</b> {$email}<br>
                ✅ <b>Password:</b> {$password}<br><br>
                Thank you,<br>
                Government Cancer Hospital Employee Society
            ";
            sendEmail($email, $subject, $body);

            // Clear form values after successful registration
            $first_name = $last_name = $email = $contact_number = "";
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { // Duplicate email
                $message = "<div class='message error'>⚠️ Email already registered! <a href='index.php'>Login Here</a></div>";
            } else {
                $message = "<div class='message error'>❌ Error: " . $e->getMessage() . "</div>";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>User Registration</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> <!-- ✅ Important for mobile -->
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: 0;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            background: #fff;
            padding: 25px 30px;
            border-radius: 12px;
            box-shadow: 0 6px 15px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 350px; /* ✅ Responsive instead of fixed */
            text-align: center;
            box-sizing: border-box;
        }
        .container h2 {
            margin-bottom: 15px;
            color: #333;
        }
        .form-group {
            text-align: left;
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            font-size: 14px;
            margin-bottom: 6px;
            color: #444;
        }
        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #bbb;
            border-radius: 8px;
            font-size: 14px;
            box-sizing: border-box;
        }
        button {
            width: 100%;
            padding: 12px;
            background: #5788d2ff;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            color: white;
            cursor: pointer;
            transition: 0.3s;
        }
        button:hover {
            background: #0B5ED7;
        }
        .message {
            margin: 10px auto 15px auto;
            padding: 10px;
            border-radius: 6px;
            width: 100%;
            font-size: 14px;
        }
        .message.success {
            background: #e0f9e0;
            color: #0B5ED7;
            border: 1px solid #0B5ED7;
        }
        .message.error {
            background: #ffe0e0;
            color: #a94442;
            border: 1px solid #a94442;
        }
        .login-option {
            margin-top: 12px;
            font-size: 14px;
        }
        .login-option a {
            color: #007BFF;
            text-decoration: none;
        }
        .login-option a:hover {
            text-decoration: underline;
        }

        /* ✅ Extra responsive tweaks */
        @media (max-width: 400px) {
            .container {
                padding: 20px 15px;
                border-radius: 8px;
            }
            .form-group label {
                font-size: 13px;
            }
            button {
                font-size: 15px;
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>User Registration</h2>

        <!-- Show message inside the box -->
        <?php if (!empty($message)) echo $message; ?>

        <form method="POST">
            <div class="form-group">
                <label>First Name</label>
                <input type="text" name="first_name" value="<?php echo htmlspecialchars($first_name); ?>" required>
            </div>
            <div class="form-group">
                <label>Last Name</label>
                <input type="text" name="last_name" value="<?php echo htmlspecialchars($last_name); ?>" required>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
            </div>
            <div class="form-group">
                <label>Contact Number</label>
                <input type="text" name="contact_number" value="<?php echo htmlspecialchars($contact_number); ?>" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <div class="form-group">
                <label>Confirm Password</label>
                <input type="password" name="confirm_password" required>
            </div>
            <button type="submit">Register</button>

            <!-- Already registered option -->
            <div class="login-option">
                Already registered? <a href="index.php">Login here</a>
            </div>
        </form>
    </div>
</body>
</html>
