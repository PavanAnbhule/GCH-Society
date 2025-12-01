<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

include 'db.php';

try {
    $uid = $_SESSION["user_id"];

    // Fetch all investment transactions (history)
    $stmt = $pdo->prepare("SELECT amount, created_at FROM investments WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$uid]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch total investment & count
    $totalStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) AS total_investment, COUNT(*) AS total_transactions FROM investments WHERE user_id = ?");
    $totalStmt->execute([$uid]);
    $totalRow = $totalStmt->fetch(PDO::FETCH_ASSOC);

    $totalInvestment   = (float)($totalRow['total_investment'] ?? 0);
    $totalTransactions = (int)($totalRow['total_transactions'] ?? 0);

    // Loan Amount = SUM(total_value) from investment_calculations for this user
    $loanAmtStmt = $pdo->prepare("SELECT COALESCE(SUM(total_value),0) AS loan_amount FROM investment_calculations WHERE user_id = ?");
    $loanAmtStmt->execute([$uid]);
    $loanAmtRow  = $loanAmtStmt->fetch(PDO::FETCH_ASSOC);
    $loanAmount  = (float)($loanAmtRow['loan_amount'] ?? 0);

    // Total Loan Deposit from payments.receive
    $depStmt = $pdo->prepare("SELECT COALESCE(SUM(receive),0) AS total_deposit FROM payments WHERE user_id = ?");
    $depStmt->execute([$uid]);
    $depRow = $depStmt->fetch(PDO::FETCH_ASSOC);
    $totalLoanDeposit = (float)($depRow['total_deposit'] ?? 0);

    // Remaining Loan Amount (clamped at 0)
    $remainingLoan = $loanAmount - $totalLoanDeposit;
    if ($remainingLoan < 0) { $remainingLoan = 0.0; }

    // Loan History: principal_amount, interest_amount, total_value, created_at
    $loanTxStmt = $pdo->prepare("
        SELECT principal AS principal_amount,
               interest_amount,
               total_value,
               created_at
        FROM investment_calculations
        WHERE user_id = ?
        ORDER BY created_at DESC
    ");
    $loanTxStmt->execute([$uid]);
    $loanTransactions = $loanTxStmt->fetchAll(PDO::FETCH_ASSOC);

    // Loan Recover History
    $recoverStmt = $pdo->prepare("
        SELECT receive AS recover_amount, created_at
        FROM payments
        WHERE user_id = ? AND receive IS NOT NULL AND receive > 0
        ORDER BY created_at DESC
    ");
    $recoverStmt->execute([$uid]);
    $recoverTransactions = $recoverStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Error fetching transactions: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Transactions</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> 
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">

    <style>
        body { background-color: #f8f9fa; }
        .content { padding: 20px; }
        .card {
          border-radius: 12px;
          box-shadow: 0 2px 6px rgba(0,0,0,0.1);
          background: #fff;
          padding: 20px;
          margin-bottom: 20px;
        }
        table {
          width: 100%;
          border-collapse: collapse;
        }
        table th, table td {
          padding: 10px;
          border: 1px solid #ddd;
          text-align: left;
          white-space: nowrap;
        }
        table th {
          background: #f1f1f1;
        }

        /* Responsive table container for mobile */
        .table-responsive {
          overflow-x: auto;
          overflow-y: auto;
          max-height: 400px;
          -webkit-overflow-scrolling: touch;
        }

        /* Sidebar base */
        .sidebar {
          background: #1e3a5f;
          color: #fff;
          min-height: 100vh;
          padding-top: 20px;
        }
        .sidebar a {
          color: #fff;
          display: block;
          padding: 12px 20px;
          text-decoration: none;
        }
        .sidebar a.active,
        .sidebar a:hover {
          background: #2d4b75;
        }

        /* Mobile header */
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
          font-size: 15px;
        }
        .menu-toggle {
          background: none;
          border: none;
          font-size: 22px;
          cursor: pointer;
        }

        /* Mobile adjustments */
        @media (max-width: 768px) {
          .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 240px;
            height: 100%;
            transform: translateX(-100%);
            transition: transform 0.3s ease;
            z-index: 1000;
            padding-top: 60px;
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

          .mobile-header { display: flex; }

          /* Ensure cards fit within screen width */
          .card {
            padding: 15px;
            overflow-x: hidden;
          }
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
    <div class="col-md-2 p-0">
      <div class="sidebar">
        <?php include 'sidebar.php'; ?>
      </div>
    </div>

    <!-- Main content -->
    <div class="col-md-10 content">
      <div class="top-heading mb-4"><h4>Your Transactions</h4></div>

      <div class="card mb-3">
        <h5>Total Investment: <span class="text-success">Rs. <?= number_format($totalInvestment, 2) ?></span></h5>
        <h6>Total Transactions: <span class="text-primary"><?= $totalTransactions ?></span></h6>
        <h5 class="mt-2">Loan Amount: <span class="text-danger">Rs. <?= number_format($loanAmount, 2) ?></span></h5>
        <h5 class="mt-2">Remaining Loan Amount: <span class="text-primary">Rs. <?= number_format($remainingLoan, 2) ?></span></h5>
      </div>

      <!-- Investment History -->
      <div class="card">
        <h5 class="mb-3">Investment History</h5>
        <div class="table-responsive">
          <table class="table table-bordered">
              <thead>
                  <tr>
                      <th>Amount</th>
                      <th>Date</th>
                  </tr>
              </thead>
              <tbody>
              <?php if (!empty($transactions)): ?>
                  <?php foreach ($transactions as $t): ?>
                      <tr>
                          <td>Rs.<?= number_format((float)$t['amount'], 2) ?></td>
                          <td><?= htmlspecialchars($t['created_at']) ?></td>
                      </tr>
                  <?php endforeach; ?>
              <?php else: ?>
                  <tr><td colspan="2">No transactions found.</td></tr>
              <?php endif; ?>
              </tbody>
          </table>
        </div>
      </div>

      <!-- Loan History -->
      <div class="card mt-3">
        <h5 class="mb-3">Loan History</h5>
        <div class="table-responsive">
          <table class="table table-bordered align-middle">
              <thead>
                  <tr>
                      <th>Principal Amount</th>
                      <th>Interest Amount</th>
                      <th>Total Value</th>
                      <th>Date</th>
                  </tr>
              </thead>
              <tbody>
              <?php if (!empty($loanTransactions)): ?>
                  <?php foreach ($loanTransactions as $lt): ?>
                      <tr>
                          <td>Rs.<?= number_format((float)$lt['principal_amount'], 2) ?></td>
                          <td>Rs.<?= number_format((float)$lt['interest_amount'], 2) ?></td>
                          <td><strong>Rs.<?= number_format((float)$lt['total_value'], 2) ?></strong></td>
                          <td><?= htmlspecialchars($lt['created_at']) ?></td>
                      </tr>
                  <?php endforeach; ?>
              <?php else: ?>
                  <tr><td colspan="4">No loan transactions found.</td></tr>
              <?php endif; ?>
              </tbody>
          </table>
        </div>
      </div>

      <!-- Loan Recover History -->
      <div class="card mt-3">
        <h5 class="mb-3">Loan Recover History</h5>
        <div class="table-responsive">
          <table class="table table-bordered align-middle">
              <thead>
                  <tr>
                      <th>Recover Amount</th>
                      <th>Date</th>
                  </tr>
              </thead>
              <tbody>
              <?php if (!empty($recoverTransactions)): ?>
                  <?php foreach ($recoverTransactions as $rt): ?>
                      <tr>
                          <td>Rs.<?= number_format((float)$rt['recover_amount'], 2) ?></td>
                          <td><?= htmlspecialchars($rt['created_at']) ?></td>
                      </tr>
                  <?php endforeach; ?>
              <?php else: ?>
                  <tr><td colspan="2">No recover transactions found.</td></tr>
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

  if (menuToggle) {
    menuToggle.addEventListener('click', () => {
      sidebar.classList.toggle('active');
      overlay.classList.toggle('active');
    });
  }

  if (overlay) {
    overlay.addEventListener('click', () => {
      sidebar.classList.remove('active');
      overlay.classList.remove('active');
    });
  }
</script>
</body>
</html>
