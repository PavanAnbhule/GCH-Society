<?php
require_once "db.php";

$success = '';
$error = '';
$token = $_GET['token'] ?? '';

if (!$token) {
    die("<h3 style='color:red; text-align:center;'>❌ Invalid password reset link.</h3>");
}

/* -------------------------------------------------
   🧩 Step 1. Verify token validity
-------------------------------------------------- */
$stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token = ? LIMIT 1");
$stmt->execute([$token]);
$reset = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reset) {
    die("<h3 style='color:red; text-align:center;'>❌ Invalid or expired reset token.</h3>");
}

// Check expiration
if (new DateTime() > new DateTime($reset['expires_at'])) {
    // Expired token - delete it
    $pdo->prepare("DELETE FROM password_resets WHERE token = ?")->execute([$token]);
    die("<h3 style='color:red; text-align:center;'>❌ This reset link has expired.</h3>");
}

$user_id = $reset['user_id'];

/* -------------------------------------------------
   🧩 Step 2. Handle new password submission
-------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = trim($_POST['password']);
    $confirm = trim($_POST['confirm_password']);

    if (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        $hashed = password_hash($password, PASSWORD_DEFAULT);

        // Update user's password
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $user_id]);

        // Remove used token
        $pdo->prepare("DELETE FROM password_resets WHERE token = ?")->execute([$token]);

        $success = "✅ Your password has been updated successfully! You can now <a href='index.php'>login</a>.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Reset Password</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body {
      background-color: #f8f9fa;
      display: flex;
      align-items: center;
      justify-content: center;
      height: 100vh;
      margin: 0;
      padding: 10px;
    }
    .card {
      width: 100%;
      max-width: 420px;
      border-radius: 10px;
      padding: 30px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    h3 {
      text-align: center;
      margin-bottom: 20px;
      font-weight: bold;
    }
    .btn-primary {
      width: 100%;
    }
  </style>
</head>
<body>
  <div class="card bg-white">
    <h3>Reset Password</h3>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($success): ?>
      <div class="alert alert-success"><?= $success ?></div>
    <?php else: ?>
      <p class="text-muted text-center">Set a new password for your account.</p>
    <?php endif; ?>

    <?php if (!$success): ?>
    <form method="POST">
      <div class="mb-3">
        <label class="form-label">New Password</label>
        <input type="password" name="password" class="form-control" required minlength="6">
      </div>
      <div class="mb-3">
        <label class="form-label">Confirm Password</label>
        <input type="password" name="confirm_password" class="form-control" required minlength="6">
      </div>
      <button type="submit" class="btn btn-primary">Update Password</button>
    </form>
    <?php endif; ?>
  </div>
</body>
</html>
