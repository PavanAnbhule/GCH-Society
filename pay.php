<?php
require_once "db.php";
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/email/email_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* -----------------------------------------------
   Flash messages for bulk add section
------------------------------------------------ */
$flash_success = '';
$flash_error   = '';

/* -----------------------------------------------
   📥 Bulk Add: Download template / Upload filled file
   (Emails sent on upload)
------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    try {
        if ($_POST['bulk_action'] === 'download_template') {
            // Build CSV with all users, prefill User ID + Name
            $users = $pdo->query("SELECT id, first_name, last_name FROM users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

            $filename = 'bulk_add_template_'.date('Ymd_His').'.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="'.$filename.'"');

            $out = fopen('php://output', 'w');
            fputcsv($out, ['User ID', 'Name', 'Subscription', 'Principal_Amount', 'Interest', 'Other']);

            foreach ($users as $u) {
                $uid = (int)$u['id'];
                $name = trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? ''));
                fputcsv($out, [$uid, $name, 0, 0, 0, 0]);
            }
            fclose($out);
            exit;
        }

        if ($_POST['bulk_action'] === 'upload_bulk') {
            if (!isset($_FILES['bulk_file']) || $_FILES['bulk_file']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Please choose a CSV file to upload.');
            }

            $tmp = $_FILES['bulk_file']['tmp_name'];
            $fh  = fopen($tmp, 'r');
            if (!$fh) throw new RuntimeException('Unable to read uploaded file.');

            // Read header to map columns
            $header = fgetcsv($fh);
            if (!$header) throw new RuntimeException('CSV appears to be empty.');

            // Normalize header names (trim + lower)
            $map = [];
            foreach ($header as $i => $col) {
                $key = strtolower(trim($col));
                $map[$key] = $i;
            }

            $required = ['user id','name','subscription','principal_amount','interest','other'];
            foreach ($required as $req) {
                if (!array_key_exists($req, $map)) {
                    throw new RuntimeException("CSV missing required column: {$req}");
                }
            }

            // Prepare statements
            $insInv = $pdo->prepare("INSERT INTO investments (user_id, amount, created_at) VALUES (:uid, :amt, NOW())");
            $insPay = $pdo->prepare("INSERT INTO payments (user_id, receive, other_amount, created_at) VALUES (:uid, :rcv, :other, NOW())");
            $getUser = $pdo->prepare("SELECT email, first_name, last_name FROM users WHERE id = ?");

            $pdo->beginTransaction();

            $invCount = 0;
            $payCount = 0;

            while (($row = fgetcsv($fh)) !== false) {
                $uid   = (int)($row[$map['user id']] ?? 0);
                if ($uid <= 0) { continue; }

                // Values
                $sub   = (float)($row[$map['subscription']] ?? 0);
                $prin  = (float)($row[$map['principal_amount']] ?? 0);
                $intr  = (float)($row[$map['interest']] ?? 0);
                $other = (float)($row[$map['other']] ?? 0);
                $receive = max(0, $prin + $intr);

                // Fetch user info
                $getUser->execute([$uid]);
                $user = $getUser->fetch(PDO::FETCH_ASSOC);
                $email = trim($user['email'] ?? '');
                $name  = trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? ''));

                // 1) Subscription -> investments.amount
                if ($sub > 0) {
                    $insInv->execute(['uid' => $uid, 'amt' => $sub]);
                    $invCount++;
                }

                // 2) (Principal_Amount + Interest) / Other -> payments
                if ($receive > 0 || $other > 0) {
                    $insPay->execute(['uid' => $uid, 'rcv' => $receive, 'other' => $other]);
                    $payCount++;
                }

                // 3) Emails (if user has an email)
                if ($email !== '') {
                    // Compute updated remaining balance AFTER the insert above
                    $loanTotal = (float)$pdo->query("SELECT COALESCE(SUM(total_value),0) FROM investment_calculations WHERE user_id = {$uid}")->fetchColumn();
                    $recovered = (float)$pdo->query("SELECT COALESCE(SUM(receive),0) FROM payments WHERE user_id = {$uid}")->fetchColumn();
                    $remaining = $loanTotal - $recovered;
                    if ($remaining < 0) $remaining = 0.0;

                    // Loan email (if any loan receive added)
                    if ($receive > 0) {
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
                            $mail->addAddress($email, $name);
                            $mail->isHTML(true);
                            $mail->Subject = "Loan Amount Debited Successfully";
                            $mail->Body    = "Dear {$name},<br><br>"
                                           . "Your loan amount of <b>Rs. ".number_format($receive,2)."</b> is debited successfully.<br>"
                                           . "Your remaining loan balance is <b>Rs. ".number_format($remaining,2)."</b>.<br><br>"
                                           . "Regards,<br>GCH Team";
                            $mail->AltBody = "Dear {$name}, Your loan amount Rs. ".number_format($receive,2)." is debited successfully. Remaining balance Rs. ".number_format($remaining,2).".";
                            $mail->send();
                        } catch (Exception $e) {
                            error_log("Loan debit email failed for UID {$uid}: ".$e->getMessage());
                        }
                    }

                    // Investment email (if any subscription added)
                    if ($sub > 0) {
                        try {
                            $mail2 = new PHPMailer(true);
                            $mail2->isSMTP();
                            $mail2->Host       = SMTP_HOST;
                            $mail2->SMTPAuth   = true;
                            $mail2->Username   = SMTP_USER;
                            $mail2->Password   = SMTP_PASS;
                            $mail2->SMTPSecure = SMTP_SECURE;
                            $mail2->Port       = SMTP_PORT;
                            $mail2->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
                            $mail2->addAddress($email, $name);
                            $mail2->isHTML(true);
                            $mail2->Subject = "Investment Added Successfully";
                            $mail2->Body    = "Dear {$name},<br><br>"
                                             . "Your investment of <b>Rs. ".number_format($sub,2)."</b> has been added successfully.<br><br>"
                                             . "Regards,<br>GCH Team";
                            $mail2->AltBody = "Dear {$name}, Your investment of Rs. ".number_format($sub,2)." has been added successfully.";
                            $mail2->send();
                        } catch (Exception $e) {
                            error_log("Investment email failed for UID {$uid}: ".$e->getMessage());
                        }
                    }
                }
            }

            $pdo->commit();
            fclose($fh);

            $flash_success = "✅ Bulk upload complete. Inserted: {$invCount} investment rows, {$payCount} payment rows.";
            header("Location: pay.php?msg=" . urlencode($flash_success));
            exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $flash_error = "❌ " . $e->getMessage();
    }
}

// Show message passed after redirect
if (isset($_GET['msg'])) {
    $flash_success = $_GET['msg'];
}

/* -------------------------------
   ✅ Fetch all required totals
--------------------------------*/

// Total Investments
$total_investment = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM investments")->fetchColumn();

// Total Loan Amount
$total_loan = (float)$pdo->query("SELECT COALESCE(SUM(total_value),0) FROM investment_calculations")->fetchColumn();

// Total Interest Amount
$total_interest = (float)$pdo->query("SELECT COALESCE(SUM(interest_amount),0) FROM investment_calculations")->fetchColumn();

// Total Recovered Loan Amount
$total_recovered = (float)$pdo->query("SELECT COALESCE(SUM(receive),0) FROM payments")->fetchColumn();

// Remaining Loan Amount
$remaining_loan = max(0, $total_loan - $total_recovered);

/* -------------------------------
   ✅ Users search section
--------------------------------*/
$user_q = isset($_GET['user_q']) ? trim($_GET['user_q']) : '';
$users = [];

try {
    if ($user_q !== '') {
        $like = '%' . $user_q . '%';
        $stmtU = $pdo->prepare("
            SELECT id, first_name, last_name, email, contact_number
            FROM users
            WHERE first_name LIKE :q
               OR last_name LIKE :q
               OR CONCAT(first_name, ' ', last_name) LIKE :q
               OR email LIKE :q
               OR contact_number LIKE :q
            ORDER BY id DESC
            LIMIT 5
        ");
        $stmtU->execute(['q' => $like]);
        $users = $stmtU->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmtU = $pdo->query("
            SELECT id, first_name, last_name, email, contact_number
            FROM users
            ORDER BY id DESC
            LIMIT 5
        ");
        $users = $stmtU->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $t) {
    $users = [];
}

/* -------------------------------
   ✅ Statement rows (per-user aggregates)
--------------------------------*/
$statement_rows = [];
try {
    $stmt = $pdo->query("
        SELECT
            u.id AS user_id,
            u.first_name,
            u.last_name,
            COALESCE(inv.sum_amount, 0)          AS investment,
            COALESCE(loan.sum_principal, 0)      AS loan_principal,
            COALESCE(loan.sum_interest, 0)       AS interest_amount,
            COALESCE(loan.sum_total, 0)          AS total_loan_amount,
            COALESCE(pay.sum_receive, 0)         AS recovered
        FROM users u
        LEFT JOIN (
            SELECT user_id, SUM(amount) AS sum_amount
            FROM investments
            GROUP BY user_id
        ) inv ON inv.user_id = u.id
        LEFT JOIN (
            SELECT user_id,
                   SUM(principal)       AS sum_principal,
                   SUM(interest_amount) AS sum_interest,
                   SUM(total_value)     AS sum_total
            FROM investment_calculations
            GROUP BY user_id
        ) loan ON loan.user_id = u.id
        LEFT JOIN (
            SELECT user_id, SUM(receive) AS sum_receive
            FROM payments
            GROUP BY user_id
        ) pay ON pay.user_id = u.id
        ORDER BY u.id DESC
    ");
    $statement_rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $t) {
    $statement_rows = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>💰 Pay / Receive</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"> 
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <style>
    body { background-color: #f8f9fa; }

    /* Sidebar */
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

    /* Equal card sizing */
    .summary-card {
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      height: 160px;
    }
    .summary-card h6 { font-size: 15px; font-weight: 600; color: #333; }
    .summary-card h4 { font-size: 20px; font-weight: bold; color: #1e3a5f; }

    /* Add Amount Section */
    .add-amount-card { padding: 20px; }

    /* Search & Statement tables: scrollable on mobile */
    .table-wrapper { overflow-x: auto; overflow-y: auto; max-height: 420px; -webkit-overflow-scrolling: touch; }
    .table-wrapper table th, .table-wrapper table td { white-space: nowrap; }

    .mobile-header {
      display: none;
      background: #fff;
      padding: 10px 15px;
      border-bottom: 1px solid #ddd;
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
      .col-md-2.sidebar { position: fixed; height: 100%; }
      .col-md-10.content { margin-left: 0 !important; }
      .mobile-header { display: flex; }
    }
  </style>
</head>
<body>

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
      <a href="pay.php" class="bg-dark">💰 Pay / Receive</a>
      <a href="logout.php">🚪 Logout</a>
    </div>

    <!-- Main content -->
    <div class="col-md-10 content">
      <!-- Top Summary Cards -->
      <div class="row mb-4 g-3">
        <div class="col-md-2 col-sm-6 col-6">
          <div class="card summary-card text-center">
            <h6>Total Investments</h6>
            <h4>Rs. <?= number_format($total_investment, 2) ?></h4>
          </div>
        </div>
        <div class="col-md-2 col-sm-6 col-6">
          <div class="card summary-card text-center">
            <h6>Total Loan Amount</h6>
            <h4>Rs. <?= number_format($total_loan, 2) ?></h4>
          </div>
        </div>
        <div class="col-md-2 col-sm-6 col-6">
          <div class="card summary-card text-center">
            <h6>Total Interest Amount</h6>
            <h4>Rs. <?= number_format($total_interest, 2) ?></h4>
          </div>
        </div>
        <div class="col-md-3 col-sm-6 col-6">
          <div class="card summary-card text-center">
            <h6>Total Recovered Loan Amount</h6>
            <h4>Rs. <?= number_format($total_recovered, 2) ?></h4>
          </div>
        </div>
        <div class="col-md-3 col-sm-6 col-6">
          <div class="card summary-card text-center">
            <h6>Remaining Loan Amount</h6>
            <h4>Rs. <?= number_format($remaining_loan, 2) ?></h4>
          </div>
        </div>
      </div>

      <!-- ✅ Add Amount (Bulk) -->
      <div class="card add-amount-card mb-4">
        <h3 class="mb-3">➕ Add Amount (Bulk)</h3>

        <?php if (!empty($flash_success)): ?>
          <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
        <?php endif; ?>
        <?php if (!empty($flash_error)): ?>
          <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
        <?php endif; ?>

        <div class="row g-3">
          <div class="col-md-6">
            <form method="post">
              <input type="hidden" name="bulk_action" value="download_template">
              <button type="submit" class="btn btn-outline-primary w-100">⬇️ Download Template (CSV)</button>
            </form>
          </div>
          <div class="col-md-6">
            <form method="post" enctype="multipart/form-data">
              <input type="hidden" name="bulk_action" value="upload_bulk">
              <div class="input-group">
                <input type="file" name="bulk_file" accept=".csv" class="form-control" required>
                <button class="btn btn-success" type="submit">⬆️ Upload &amp; Import</button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- 🔎 Search Registered Users -->
      <div class="card search-card">
        <h3 class="mb-3">🔎 Search Registered Users</h3>
        <form method="GET" class="row g-3 mb-3">
          <div class="col-md-9">
            <input
              type="text"
              name="user_q"
              value="<?= htmlspecialchars($user_q) ?>"
              class="form-control"
              placeholder="Search by name, email, or contact number"
            >
          </div>
          <div class="col-md-3 text-md-start text-center">
            <button type="submit" class="btn btn-primary w-100">Search</button>
          </div>
        </form>

        <div class="table-wrapper">
          <table class="table table-striped table-hover">
            <thead class="table-dark">
              <tr>
                <th>User ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Contact Number</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($users)): ?>
                <?php foreach ($users as $u): ?>
                  <tr>
                    <td><a href="investdetails.php?id=<?= (int)$u['id'] ?>"><?= (int)$u['id'] ?></a></td>
                    <td><?= htmlspecialchars(trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? ''))) ?></td>
                    <td><?= htmlspecialchars($u['email'] ?? '') ?></td>
                    <td><?= htmlspecialchars($u['contact_number'] ?? '') ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="4" class="text-center">No users found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- 📄 Statement Section -->
      <div class="card statement-card mt-3">
        <h3 class="mb-3">📄 Statement</h3>
        <div class="table-wrapper">
          <table class="table table-striped table-hover align-middle">
            <thead class="table-dark">
              <tr>
                <th>User ID</th>
                <th>Name</th>
                <th>Investment</th>
                <th>Principal Amount</th><!-- 🔁 CHANGED from 'Loan' to 'Principal Amount' -->
                <th>Interest</th>
                <th>Total Loan Amount</th>
                <th>Recovered</th>
                <th>Balance</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($statement_rows)): ?>
                <?php foreach ($statement_rows as $row):
                  $balance = (float)$row['total_loan_amount'] - (float)$row['recovered'];
                  if ($balance < 0) $balance = 0.0;
                  $fullName = trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? ''));
                ?>
                  <tr>
                    <td><?= (int)$row['user_id'] ?></td>
                    <td><?= htmlspecialchars($fullName) ?></td>
                    <td>Rs. <?= number_format((float)$row['investment'], 2) ?></td>
                    <td>Rs. <?= number_format((float)$row['loan_principal'], 2) ?></td>
                    <td>Rs. <?= number_format((float)$row['interest_amount'], 2) ?></td>
                    <td><strong>Rs. <?= number_format((float)$row['total_loan_amount'], 2) ?></strong></td>
                    <td>Rs. <?= number_format((float)$row['recovered'], 2) ?></td>
                    <td class="<?= $balance > 0 ? 'text-danger' : 'text-success' ?>">
                      <strong>Rs. <?= number_format($balance, 2) ?></strong>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="8" class="text-center">No data available.</td></tr>
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
