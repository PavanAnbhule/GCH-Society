<?php
session_start();
include 'db.php';

// ✅ Load PHPMailer (Composer autoload + email config)
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/email/email_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$error = '';
$success = '';

/* -------------------------------------------------
   🔐 Handle Login
-------------------------------------------------- */
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login'])) {
    $email = trim($_POST["email"]);
    $password = trim($_POST["password"]);

    // ✅ Fetch only required fields
    $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, contact_number, role, password 
                           FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // ✅ Check hashed OR plain text password (for legacy rows)
        if (password_verify($password, $user["password"]) || $password === $user["password"]) {
            // ✅ Save user data in session
            $_SESSION["user_id"] = $user["id"];
            $_SESSION["first_name"] = $user["first_name"];
            $_SESSION["last_name"] = $user["last_name"];
            $_SESSION["email"] = $user["email"];
            $_SESSION["contact_number"] = $user["contact_number"];
            $_SESSION["role"] = $user["role"];

            // ✅ Redirect based on role
            if ($user["role"] === "admin") {
                header("Location: admin_dashboard.php");
            } elseif ($user["role"] === "user") {
                header("Location: user_dashboard.php");
            } else {
                $error = "❌ Invalid role assigned. Please contact admin.";
            }
            exit;
        } else {
            $error = "❌ Wrong password!";
        }
    } else {
        $error = "❌ Email not found in database!";
    }
}

/* -------------------------------------------------
   ✉️ Handle "Forgot password?" request
   - Creates password_resets table if missing
   - Stores a time-limited token (30 minutes)
   - Emails a reset link to the user
-------------------------------------------------- */
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['forgot_password'])) {
    $fp_email = trim($_POST['fp_email']);

    if (!filter_var($fp_email, FILTER_VALIDATE_EMAIL)) {
        $error = "❌ Please enter a valid email for password reset.";
    } else {
        // Check if user exists
        $u = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE email = ? LIMIT 1");
        $u->execute([$fp_email]);
        $usr = $u->fetch(PDO::FETCH_ASSOC);

        // Always act like it worked to avoid leaking which emails exist
        // But send the email only if user exists.
        try {
            // Create table if not exists (idempotent)
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS password_resets (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    token VARCHAR(128) NOT NULL,
                    expires_at DATETIME NOT NULL,
                    created_at DATETIME NOT NULL,
                    INDEX (user_id),
                    INDEX (token)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            if ($usr) {
                $token = bin2hex(random_bytes(32));
                $expiresAt = (new DateTime('+30 minutes'))->format('Y-m-d H:i:s');

                $ins = $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at, created_at) VALUES (?, ?, ?, NOW())");
                $ins->execute([$usr['id'], $token, $expiresAt]);

                // Build absolute reset URL
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $base   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
                $resetUrl = "{$scheme}://{$host}{$base}/reset_password.php?token={$token}";

                // Send reset email
                try {
                    $mail = new PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host       = SMTP_HOST;
                    $mail->SMTPAuth   = true;
                    $mail->Username   = SMTP_USER;
                    $mail->Password   = SMTP_PASS;
                    $mail->SMTPSecure = SMTP_SECURE;
                    $mail->Port       = SMTP_PORT;

                    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
                    $mail->addAddress($fp_email, trim(($usr['first_name'] ?? '').' '.($usr['last_name'] ?? '')));

                    $mail->isHTML(true);
                    $mail->Subject = "Password Reset Request";
                    $mail->Body    = "Hello,<br><br>"
                                   . "We received a request to reset your password. Click the link below to set a new password (valid for 30 minutes):<br>"
                                   . "<a href=\"{$resetUrl}\">Reset your password</a><br><br>"
                                   . "If you didn’t request this, you can safely ignore this email.";
                    $mail->AltBody = "Reset your password (valid for 30 minutes): {$resetUrl}";

                    $mail->send();
                } catch (Exception $e) {
                    // Log but don't reveal details to user
                    error_log("Password reset email failed: ".$e->getMessage());
                }
            }

            $success = "✅ If an account with that email exists, a reset link has been sent.";
        } catch (Throwable $t) {
            error_log("Forgot password error: ".$t->getMessage());
            $error = "❌ Could not process password reset right now. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>User Login</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"> <!-- ✅ Important for mobile -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <style>
    body {
      background-color: #f5f7fa;
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      margin: 0;
      padding: 16px;
    }
    .card {
      width: 100%;
      max-width: 420px; /* ✅ Responsive */
      border-radius: 12px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.1);
      padding: 30px;
      background: #fff;
      box-sizing: border-box;
    }
    h2 {
      text-align: center;
      margin-bottom: 20px;
      font-weight: bold;
    }
    .form-control {
      border-radius: 6px;
      padding: 10px;
    }
    .btn-primary {
      width: 100%;
      padding: 10px;
      border-radius: 6px;
      background-color: #3b82f6;
      border: none;
    }
    .btn-primary:hover {
      background-color: #2563eb;
    }
    .text-center {
      margin-top: 15px;
    }
    .actions {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 8px;
      margin-top: 10px;
      flex-wrap: wrap;
    }
    .link-btn {
      background: none;
      border: none;
      padding: 0;
      color: #2563eb;
      text-decoration: underline;
      cursor: pointer;
    }
    /* ✅ Extra responsive tweaks */
    @media (max-width: 400px) {
      .card {
        padding: 25px 20px;
        border-radius: 8px;
      }
      h2 {
        font-size: 20px;
        margin-bottom: 15px;
      }
      .btn-primary {
        font-size: 15px;
        padding: 9px;
      }
      .actions {
        flex-direction: column;
        align-items: stretch;
        gap: 6px;
      }
    }
  </style>
</head>
<body>
  <div class="card">
    <h2>Login</h2>

    <?php if (!empty($success)) : ?>
      <div class="alert alert-success text-center py-2"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)) : ?>
      <div class="alert alert-danger text-center py-2"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <input type="hidden" name="login" value="1">
      <div class="mb-3">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" required autocomplete="username">
      </div>
      <div class="mb-1">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" required autocomplete="current-password">
      </div>
      <div class="actions">
        <button type="submit" class="btn btn-primary mt-2">Login</button>
        <!-- 🔗 Forgot password trigger -->
        <button type="button" class="link-btn mt-2" data-bs-toggle="modal" data-bs-target="#forgotModal">
          Forgot password?
        </button>
      </div>
    </form>

    <p class="text-center">Don’t have an account? <a href="register.php">Register here</a></p>
  </div>

  <!-- 🔒 Forgot Password Modal -->
  <div class="modal fade" id="forgotModal" tabindex="-1" aria-labelledby="forgotModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <form class="modal-content" method="POST">
        <div class="modal-header">
          <h5 class="modal-title" id="forgotModalLabel">Reset your password</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="mb-2">Enter your registered email address. If it exists, we’ll email you a reset link (valid for 30 minutes).</p>
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="fp_email" class="form-control" required autocomplete="email">
          </div>
        </div>
        <div class="modal-footer">
          <input type="hidden" name="forgot_password" value="1">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Send reset link</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Bootstrap JS (for modal) -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
