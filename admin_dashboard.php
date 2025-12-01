<?php
// Include DB config
require_once "db.php";

// ✅ Load PHPMailer (Composer autoload + email config)
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/email/email_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// --- Bulk Add Investments ---
if (isset($_POST['upload_csv']) && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    if (($handle = fopen($file, "r")) !== FALSE) {
        $rowCount = 0;
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if ($rowCount == 0) { $rowCount++; continue; } // Skip header
            $user_id = (int)$data[0];
            $amount = floatval($data[3]);
            if ($user_id > 0 && $amount > 0) {
                // Insert investment
                $stmt = $pdo->prepare("INSERT INTO investments (user_id, amount, created_at) VALUES (:user_id, :amount, NOW())");
                $stmt->execute(['user_id' => $user_id, 'amount' => $amount]);

                // Fetch user email
                $userStmt = $pdo->prepare("SELECT first_name, last_name, email FROM users WHERE id = :id");
                $userStmt->execute(['id' => $user_id]);
                $user = $userStmt->fetch(PDO::FETCH_ASSOC);

                if ($user && !empty($user['email'])) {
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
                        $mail->addAddress($user['email'], $user['first_name'] . ' ' . $user['last_name']);

                        $mail->isHTML(true);
                        $mail->Subject = "Investment Added Successfully";
                        $mail->Body    = "Hello <b>" . htmlspecialchars($user['first_name']) . "</b>,<br><br>"
                                       . "Your investment of <b>Rs. " . number_format($amount, 2) . "</b> has been added successfully.<br><br>"
                                       . "Thank you,<br>"
                                       . " Government Cancer Hospital Employee Society";

                        $mail->send();
                    } catch (Exception $e) {
                        error_log("Email sending failed (Bulk Upload): {$mail->ErrorInfo}");
                    }
                }
            }
            $rowCount++;
        }
        fclose($handle);

        // ✅ IMPORTANT: Prevent form resubmission popup
        header("Location: admin_dashboard.php?upload=success");
        exit;

    } else {
        header("Location: admin_dashboard.php?upload=error");
        exit;
    }
}

// --- Download Template ---
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="investment_template.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['id','first_name','email','amount']);
    $users = $pdo->query("SELECT id, first_name, email FROM users")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as $user) {
        fputcsv($output, [$user['id'], $user['first_name'], $user['email'], '']);
    }
    fclose($output);
    exit;
}

// --- Stats ---
$statsQuery = $pdo->query("
    SELECT 
        (SELECT SUM(amount) FROM investments) AS total_investment,
        (SELECT COUNT(*) FROM users) AS total_users
");
$stats = $statsQuery->fetch(PDO::FETCH_ASSOC);

// --- Users for dropdown ---
$usersQuery = $pdo->query("SELECT id, first_name, last_name FROM users ORDER BY first_name ASC");
$usersList = $usersQuery->fetchAll(PDO::FETCH_ASSOC);

// --- Filter ---
$filterUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if ($filterUserId > 0) {
    $historyQuery = $pdo->prepare("
        SELECT i.id AS investment_id, u.first_name, u.last_name, i.amount, i.created_at
        FROM investments i
        INNER JOIN users u ON i.user_id = u.id
        WHERE u.id = ?
        ORDER BY i.created_at DESC
    ");
    $historyQuery->execute([$filterUserId]);
} else {
    $historyQuery = $pdo->query("
        SELECT i.id AS investment_id, u.first_name, u.last_name, i.amount, i.created_at
        FROM investments i
        INNER JOIN users u ON i.user_id = u.id
        ORDER BY i.created_at DESC
    ");
}
$investments = $historyQuery->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Dashboard</title>
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
    .mobile-header h4 {
      margin: 0;
      font-weight: bold;
      font-size: 18px;
    }
    .menu-toggle {
      background: none;
      border: none;
      font-size: 22px;
      cursor: pointer;
    }
    @media (max-width: 768px) {
      .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        width: 220px;
        transform: translateX(-100%);
        z-index: 1000;
      }
      .sidebar.active { transform: translateX(0); }
      .overlay {
        display: none;
        position: fixed;
        top: 0; left: 0;
        width: 100%; height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 900;
      }
      .overlay.active { display: block; }
      .col-md-2.sidebar { position: fixed; height: 100%; }
      .col-md-10.content { margin-left: 0 !important; }

      .mobile-header { display: flex; }
    }
  </style>
</head>
<body>

<!-- Mobile top bar -->
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
      <a href="add_user.php">➕ Add User</a>
      <a href="pay.php">💰 Pay</a>
      <a href="logout.php">🚪 Logout</a>
    </div>

    <!-- Main content -->
    <div class="col-md-10 content">
      <div class="row mb-4">
        <div class="col-md-6">
          <div class="card p-4 text-center">
            <h5>Total Investment</h5>
            <h3>Rs. <?= number_format($stats['total_investment'] ?? 0, 2) ?></h3>
          </div>
        </div>
        <div class="col-md-6">
          <div class="card p-4 text-center">
            <h5>Total Users</h5>
            <h3><?= $stats['total_users'] ?? 0 ?></h3>
          </div>
        </div>
      </div>

      <div class="card p-4">
        <h5 class="mb-3">Investment's</h5>

        <!-- Bulk Add -->
        <div class="mb-3 p-3 card">
            <form method="POST" enctype="multipart/form-data" class="row g-2 align-items-center">
                <div class="col-auto">
                    <a href="?download_template=1" class="btn btn-success">📥 Download Template</a>
                </div>
                <div class="col-auto">
                    <input type="file" name="csv_file" accept=".csv" class="form-control" required>
                </div>
                <div class="col-auto">
                    <button type="submit" name="upload_csv" class="btn btn-primary">⬆ Upload & Add Amount</button>
                </div>
            </form>

            <?php if (isset($_GET['upload']) && $_GET['upload'] == "success") : ?>
                <p class="text-success mt-2">✅ Investments added successfully and emails sent!</p>
            <?php endif; ?>

            <?php if (isset($_GET['upload']) && $_GET['upload'] == "error") : ?>
                <p class="text-danger mt-2">❌ Unable to read CSV file!</p>
            <?php endif; ?>

        </div>

        <!-- Filter -->
        <form method="GET" class="mb-3">
          <div class="row">
            <div class="col-md-6">
              <select name="user_id" class="form-select" onchange="this.form.submit()">
                <option value="0">-- All Users --</option>
                <?php foreach ($usersList as $u): ?>
                  <option value="<?= $u['id'] ?>" <?= ($filterUserId == $u['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($u['first_name'] . " " . $u['last_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <button type="submit" class="btn btn-primary">Filter</button>
              <a href="admin_dashboard.php" class="btn btn-secondary">Reset</a>
            </div>
          </div>
        </form>

        <!-- Table -->
        <div class="table-responsive">
          <table class="table table-striped">
            <thead>
              <tr>
                <th>Investment ID</th>
                <th>User Name</th>
                <th>Amount</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              <?php if (count($investments) > 0): ?>
                <?php foreach ($investments as $row): ?>
                  <tr>
                    <td><?= htmlspecialchars($row['investment_id']) ?></td>
                    <td><?= htmlspecialchars($row['first_name'] . " " . $row['last_name']) ?></td>
                    <td>Rs. <?= number_format($row['amount'], 2) ?></td>
                    <td><?= htmlspecialchars($row['created_at']) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="4" class="text-center">No investments found</td></tr>
              <?php endif; ?>
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
