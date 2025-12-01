<?php
require_once "db.php";

// --- Validate and fetch ID ---
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    die("<div class='alert alert-danger text-center mt-5'>❌ Invalid Payment ID.</div>");
}

// --- Fetch selected payment ---
$stmt = $pdo->prepare("SELECT * FROM payments WHERE id = :id");
$stmt->execute(['id' => $id]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$payment) {
    die("<div class='alert alert-warning text-center mt-5'>⚠️ No record found for this Payment ID.</div>");
}

// --- Calculate totals for this client ---
$balanceStmt = $pdo->prepare("SELECT 
                                COALESCE(SUM(amount),0) as total_amount,
                                COALESCE(SUM(receive),0) as total_receive
                              FROM payments WHERE mobile = :mobile");
$balanceStmt->execute(['mobile' => $payment['mobile']]);
$balanceRow = $balanceStmt->fetch(PDO::FETCH_ASSOC);

$totalPay     = $balanceRow['total_amount'];
$totalReceive = $balanceRow['total_receive'];

// --- Handle Pay/Receive submission ---
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transaction_type'])) {
    $amount = floatval($_POST['amount']);
    $type = $_POST['transaction_type'];

    if ($amount > 0) {
        if ($type === 'pay') {
            // Insert into amount column
            $insert = $pdo->prepare("INSERT INTO payments (full_name, mobile, amount, created_at) 
                                     VALUES (:full_name, :mobile, :amount, NOW())");
            $insert->execute([
                'full_name' => $payment['full_name'],
                'mobile'    => $payment['mobile'],
                'amount'    => $amount
            ]);
        } elseif ($type === 'receive') {
            // Insert into receive column
            $insert = $pdo->prepare("INSERT INTO payments (full_name, mobile, receive, created_at) 
                                     VALUES (:full_name, :mobile, :receive, NOW())");
            $insert->execute([
                'full_name' => $payment['full_name'],
                'mobile'    => $payment['mobile'],
                'receive'   => $amount
            ]);
        }

        // Redirect to refresh page and prevent resubmission
        header("Location: client.php?id=" . $id);
        exit;
    }
}

// --- Fetch all payments of this client (by mobile) ---
$allStmt = $pdo->prepare("SELECT * FROM payments WHERE mobile = :mobile ORDER BY created_at DESC");
$allStmt->execute(['mobile' => $payment['mobile']]);
$allPayments = $allStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Client Details</title>
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
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      margin-bottom: 20px;
    }

    /* ✅ Custom button color */
    .btn-custom {
      background-color: #0B5ED7;
      color: #fff;
      border: none;
    }
    .btn-custom:hover {
      background-color: #0949a5;
      color: #fff;
    }

    /* ✅ Mobile header */
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
      font-size: 16px;
    }
    .menu-toggle {
      background: none;
      border: none;
      font-size: 22px;
      cursor: pointer;
    }

    /* ✅ Mobile adjustments */
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
      <a href="add_user.php">➕ Add User</a>
      <a href="pay.php">💰 Pay</a>
      <a href="logout.php">🚪 Logout</a>
    </div>

    <!-- Main content -->
    <div class="col-md-10 content">
      <?php if (!empty($message)) echo $message; ?>

      <!-- Single Payment Details -->
      <div class="card p-4">
        <h3 class="mb-3 text-center">💳 Payment Details</h3>
        <table class="table table-bordered">
          <tr>
            <th>ID</th>
            <td><?= htmlspecialchars($payment['id']) ?></td>
          </tr>
          <tr>
            <th>Full Name</th>
            <td><?= htmlspecialchars($payment['full_name']) ?></td>
          </tr>
          <tr>
            <th>Mobile</th>
            <td><?= htmlspecialchars($payment['mobile']) ?></td>
          </tr>
          <tr>
            <th>Total Pay</th>
            <td>Rs. <?= number_format($totalPay, 2) ?></td>
          </tr>
          <tr>
            <th>Total Receive</th>
            <td>Rs. <?= number_format($totalReceive, 2) ?></td>
          </tr>
        </table>

        <!-- ✅ Pay/Receive Form -->
        <form method="POST" class="row g-2 mt-3">
          <div class="col-md-6">
            <input type="number" step="0.01" name="amount" class="form-control" placeholder="Enter Amount" required>
          </div>
          <div class="col-md-3">
            <button type="submit" name="transaction_type" value="receive" class="btn btn-custom w-100">Receive</button>
          </div>
          <div class="col-md-3">
            <button type="submit" name="transaction_type" value="pay" class="btn btn-custom w-100">Pay</button>
          </div>
        </form>
      </div>

      <!-- All Payments of Client -->
      <div class="card p-4">
        <h3 class="mb-3">📜 All Payments by <?= htmlspecialchars($payment['full_name']) ?></h3>
        <div class="table-responsive">
          <table class="table table-striped table-hover">
            <thead class="table-dark">
              <tr>
                <th>ID</th>
                <th>Pay (Amount)</th>
                <th>Receive</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($allPayments as $row): ?>
                <tr>
                  <td><?= htmlspecialchars($row['id']) ?></td>
                  <td><?= $row['amount'] > 0 ? 'Rs. '.number_format($row['amount'], 2) : '-' ?></td>
                  <td><?= $row['receive'] > 0 ? 'Rs. '.number_format($row['receive'], 2) : '-' ?></td>
                  <td><?= htmlspecialchars($row['created_at']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="text-center mt-3">
        <a href="pay.php" class="btn btn-secondary">⬅ Back to Payments</a>
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
