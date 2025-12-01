<?php
require_once "db.php";

// ✅ Load PHPMailer (Composer autoload + email config)
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/email/email_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$success = '';
$error = '';
$search_query = '';
$searched_users = [];

/* -----------------------------------------------
   ADD SINGLE USER
------------------------------------------------ */
if (isset($_POST['add_user'])) {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $contact_number = trim($_POST['contact_number']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($first_name) || empty($last_name) || empty($email) || empty($contact_number) || empty($password) || empty($confirm_password)) {
        $error = "❌ All fields are required!";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "❌ Invalid email address!";
    } elseif ($password !== $confirm_password) {
        $error = "❌ Passwords do not match!";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->rowCount() > 0) {
            $error = "❌ Email already exists!";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, email, contact_number, password, role, created_at) 
                                   VALUES (:first_name, :last_name, :email, :contact_number, :password, 'user', NOW())");
            $stmt->execute([
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'contact_number' => $contact_number,
                'password' => $hashed_password
            ]);

            // ✅ Send Email Notification
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
                $mail->addAddress($email, $first_name . ' ' . $last_name);

                $mail->isHTML(true);
                $mail->Subject = "Registration Successful";
                $mail->Body    = "Hello <b>" . htmlspecialchars($first_name) . " " . htmlspecialchars($last_name) . "</b>,<br><br>"
                               . "✅ Your registration was successful.<br>"
                               . "Your Login ID (Email): <b>" . htmlspecialchars($email) . "</b><br>"
                               . "Your Password: <b>" . htmlspecialchars($password) . "</b><br><br>"
                               . "Thank you,<br> Government Cancer Hospital Employee Society";

                $mail->send();
            } catch (Exception $e) {
                error_log("Email sending failed: {$mail->ErrorInfo}");
            }

            $success = "✅ User added successfully and email sent!";
        }
    }
}

/* -----------------------------------------------
   BULK UPLOAD USERS
------------------------------------------------ */
if (isset($_POST['upload_csv'])) {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
        $file = $_FILES['csv_file']['tmp_name'];
        if (($handle = fopen($file, "r")) !== FALSE) {
            $row = 0;
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $row++;
                if ($row == 1) continue;

                list($first_name, $last_name, $email, $contact_number, $password, $confirm_password) = $data;
                $first_name = trim($first_name);
                $last_name = trim($last_name);
                $email = trim($email);
                $contact_number = trim($contact_number);
                $password = trim($password);
                $confirm_password = trim($confirm_password);

                if (empty($first_name) || empty($last_name) || empty($email) || empty($contact_number) || empty($password) || empty($confirm_password)) {
                    continue;
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
                if ($password !== $confirm_password) continue;

                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->rowCount() > 0) continue;

                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, email, contact_number, password, role, created_at) 
                                       VALUES (:first_name, :last_name, :email, :contact_number, :password, 'user', NOW())");
                $stmt->execute([
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'email' => $email,
                    'contact_number' => $contact_number,
                    'password' => $hashed_password
                ]);
            }
            fclose($handle);
            $success = "✅ Bulk upload completed!";
        } else {
            $error = "❌ Unable to read CSV file!";
        }
    } else {
        $error = "❌ Please upload a valid CSV file!";
    }
}

/* -----------------------------------------------
   REMOVE USER SECTION
------------------------------------------------ */

// ✅ Search Users
if (isset($_POST['search_user'])) {
    $search_query = trim($_POST['search_query']);
    if ($search_query !== '') {
        $like = '%' . $search_query . '%';
        $stmt = $pdo->prepare("
            SELECT id, first_name, last_name, email, contact_number, role
            FROM users
            WHERE first_name LIKE :q OR last_name LIKE :q OR email LIKE :q OR contact_number LIKE :q
            ORDER BY id DESC
        ");
        $stmt->execute(['q' => $like]);
        $searched_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ✅ Remove User (check role first)
if (isset($_POST['remove_user_id'])) {
    $uid = (int)$_POST['remove_user_id'];

    // Fetch user role
    $role_stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $role_stmt->execute([$uid]);
    $user_role = $role_stmt->fetchColumn();

    if ($user_role === 'admin') {
        $error = "❌ Your role is admin. Admin users cannot be removed.";
    } else {
        // Check remaining loan
        $loan_total = (float)$pdo->query("SELECT COALESCE(SUM(total_value),0) FROM investment_calculations WHERE user_id = $uid")->fetchColumn();
        $recovered = (float)$pdo->query("SELECT COALESCE(SUM(receive),0) FROM payments WHERE user_id = $uid")->fetchColumn();
        $remaining = $loan_total - $recovered;

        if ($remaining <= 0) {
            // Delete all user data
            $pdo->prepare("DELETE FROM investments WHERE user_id = ?")->execute([$uid]);
            $pdo->prepare("DELETE FROM investment_calculations WHERE user_id = ?")->execute([$uid]);
            $pdo->prepare("DELETE FROM payments WHERE user_id = ?")->execute([$uid]);
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
            $success = "✅ User and all related data deleted successfully!";
        } else {
            $error = "❌ Cannot delete user. Remaining Loan Amount: Rs. " . number_format($remaining, 2);
        }
    }
}

/* -----------------------------------------------
   FETCH ALL USERS (for table)
------------------------------------------------ */
$stmt = $pdo->query("
    SELECT u.id, u.first_name, u.last_name, u.email, u.contact_number,
           IFNULL(SUM(i.amount), 0) AS total_investment
    FROM users u
    LEFT JOIN investments i ON u.id = i.user_id
    GROUP BY u.id, u.first_name, u.last_name, u.email, u.contact_number
    ORDER BY u.id DESC
");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Add User</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"> 
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <style>
    body { background-color: #f8f9fa; }
    .sidebar {
      height: 100vh;
      background: #1e3a5f;
      color: #fff;
      padding-top: 20px;
      transition: transform 0.3s ease;
    }
    .sidebar a {
      color: #fff;
      display: block;
      padding: 12px 20px;
      text-decoration: none;
    }
    .sidebar a:hover { background: #2d4b75; }
    .content { padding: 20px; }
    .card {
      border-radius: 12px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.1);
      margin-bottom: 20px;
    }
    .mobile-header {
      display: none;
      background: #fff;
      padding: 10px 15px;
      border-bottom: 1px solid #ddd;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .mobile-header h4 { margin: 0; font-weight: bold; font-size: 16px; }
    .menu-toggle { background: none; border: none; font-size: 22px; cursor: pointer; }

    @media (max-width: 768px) {
      .sidebar {
        position: fixed; top: 0; left: 0; width: 220px;
        transform: translateX(-100%); z-index: 1000;
      }
      .sidebar.active { transform: translateX(0); }
      .overlay {
        display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.5); z-index: 900;
      }
      .overlay.active { display: block; }
      .col-md-10.content { margin-left: 0 !important; }
      .mobile-header { display: flex; }
    }
  </style>
</head>
<body>

<!-- ✅ Mobile top bar -->
<div class="mobile-header d-md-none">
  <h4>शासकीय कर्करोग रुग्णालय कर्मचारी सहकारी पतसंस्था, छत्रपती संभाजीनगर.</h4>
  <button class="menu-toggle">☰</button>
</div>

<div class="overlay"></div>
<div class="container-fluid">
  <div class="row">
    <!-- Sidebar -->
    <div class="col-md-2 sidebar">
      <h4 class="text-center mb-4">Menu</h4>
      <a href="admin_dashboard.php">📊 Dashboard</a>
      <a href="view_users.php">👥 View All Users</a>
      <a href="add_user.php" class="bg-dark">➕ Add User</a>
      <a href="pay.php">💰 Pay</a>
      <a href="logout.php">🚪 Logout</a>
    </div>

    <!-- Main content -->
    <div class="col-md-10 content">
      <div class="card p-4">
        <h3 class="mb-4">➕ Add New User</h3>

        <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

        <form method="POST">
          <div class="row">
            <div class="col-md-6 mb-3"><label>First Name</label><input type="text" name="first_name" class="form-control" required></div>
            <div class="col-md-6 mb-3"><label>Last Name</label><input type="text" name="last_name" class="form-control" required></div>
            <div class="col-md-6 mb-3"><label>Email</label><input type="email" name="email" class="form-control" required></div>
            <div class="col-md-6 mb-3"><label>Contact Number</label><input type="text" name="contact_number" class="form-control" required></div>
            <div class="col-md-6 mb-3"><label>Password</label><input type="password" name="password" class="form-control" required></div>
            <div class="col-md-6 mb-3"><label>Confirm Password</label><input type="password" name="confirm_password" class="form-control" required></div>
          </div>
          <div class="text-center"><button type="submit" name="add_user" class="btn btn-primary">Add User</button></div>
        </form>
      </div>

      <!-- Bulk Upload -->
      <div class="card p-4">
        <h3 class="mb-4">📂 Bulk Upload Users</h3>
        <p>Download the CSV template, fill in user details, and upload it.</p>
        <a href="download_template.php" class="btn btn-success mb-3">⬇ Download Template</a>
        <form method="POST" enctype="multipart/form-data">
          <div class="mb-3"><input type="file" name="csv_file" accept=".csv" class="form-control" required></div>
          <button type="submit" name="upload_csv" class="btn btn-primary">Upload CSV</button>
        </form>
      </div>

      <!-- Remove Users -->
      <div class="card p-4">
        <h3 class="mb-4 text-danger">🗑️ Remove Users</h3>
        <form method="POST" class="mb-3">
          <div class="input-group">
            <input type="text" name="search_query" value="<?= htmlspecialchars($search_query) ?>" class="form-control" placeholder="Search by name, email or contact number">
            <button type="submit" name="search_user" class="btn btn-outline-danger">Search</button>
          </div>
        </form>

        <?php if (!empty($searched_users)): ?>
          <div class="table-responsive">
            <table class="table table-striped table-hover">
              <thead class="table-dark">
                <tr>
                  <th>ID</th>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Contact</th>
                  <th>Role</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($searched_users as $u): ?>
                  <tr>
                    <td><?= $u['id'] ?></td>
                    <td><?= htmlspecialchars($u['first_name'].' '.$u['last_name']) ?></td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td><?= htmlspecialchars($u['contact_number']) ?></td>
                    <td><?= htmlspecialchars($u['role']) ?></td>
                    <td>
                      <form method="POST" onsubmit="return confirm('Are you sure you want to remove this user?');">
                        <input type="hidden" name="remove_user_id" value="<?= $u['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Remove</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php elseif ($search_query): ?>
          <p class="text-muted">No users found matching “<?= htmlspecialchars($search_query) ?>”.</p>
        <?php endif; ?>
      </div>

      <!-- Users Table -->
      <div class="card p-4">
        <h3 class="mb-4">👥 All Users</h3>
        <div class="table-responsive">
          <table class="table table-striped table-hover">
            <thead class="table-dark">
              <tr>
                <th>ID</th>
                <th>Full Name</th>
                <th>Email</th>
                <th>Contact Number</th>
                <th>Total Investment</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($users as $user): ?>
                <tr>
                  <td><a href="user_details.php?id=<?= $user['id'] ?>"><?= $user['id'] ?></a></td>
                  <td><?= htmlspecialchars($user['first_name']." ".$user['last_name']) ?></td>
                  <td><?= htmlspecialchars($user['email']) ?></td>
                  <td><?= htmlspecialchars($user['contact_number']) ?></td>
                  <td>₹<?= number_format($user['total_investment'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
const menuToggle = document.querySelector('.menu-toggle');
const sidebar = document.querySelector('.sidebar');
const overlay = document.querySelector('.overlay');

menuToggle.addEventListener('click', () => {
  sidebar.classList.toggle('active');
  overlay.classList.toggle('active');
});
overlay.addEventListener('click', () => {
  sidebar.classList.remove('active');
  overlay.classList.remove('active');
});
</script>
</body>
</html>
