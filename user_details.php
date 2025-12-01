<?php
require 'db.php';

// ✅ Load PHPMailer (Composer autoload + email config)
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/email/email_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$error = "";

// Validate user ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("❌ Invalid user ID.");
}
$user_id = (int) $_GET['id'];

// Fetch user details
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute(['id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("❌ User not found.");
}

/* ------------------------------------------------------------------
   Handle Add Investment Form Submission (unchanged behavior)
-------------------------------------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_amount'])) {
    $amount = floatval($_POST['amount']);
    if ($amount > 0) {
        $ins = $pdo->prepare("INSERT INTO investments (user_id, amount, created_at) VALUES (:user_id, :amount, NOW())");
        $ins->execute(['user_id' => $user_id, 'amount' => $amount]);

        // ✅ Send Email Notification (best effort)
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
                           . "Government Cancer Hospital Employee Society";

            $mail->send();
        } catch (Exception $e) {
            error_log("Email sending failed: " . $mail->ErrorInfo);
        }

        // Redirect to avoid form resubmission
        header("Location: user_details.php?id={$user_id}&msg=add_success");
        exit;
    } else {
        $error = "❌ Please enter a valid amount.";
    }
}

/* ------------------------------------------------------------------
   Handle Edit Amount (safe option A: edit a specific row)
   POST fields:
     - edit_type (investment | loan | recover)
     - selected_row (id)
     - edit_amount (new amount)
-------------------------------------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_submit'])) {
    $edit_type = $_POST['edit_type'] ?? '';
    $selected_row = isset($_POST['selected_row']) ? (int)$_POST['selected_row'] : 0;
    $edit_amount = isset($_POST['edit_amount']) ? floatval($_POST['edit_amount']) : 0.0;

    if (!in_array($edit_type, ['investment', 'loan', 'recover'])) {
        header("Location: user_details.php?id={$user_id}&edit_error=invalid_type");
        exit;
    }
    if ($selected_row <= 0 || $edit_amount < 0) {
        header("Location: user_details.php?id={$user_id}&edit_error=invalid_input");
        exit;
    }

    try {
        if ($edit_type === 'investment') {
            // Fetch previous row (to include previous amount and date in email)
            $s = $pdo->prepare("SELECT id, user_id, amount, created_at FROM investments WHERE id = :id AND user_id = :uid");
            $s->execute(['id' => $selected_row, 'uid' => $user_id]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                header("Location: user_details.php?id={$user_id}&edit_error=not_found");
                exit;
            }

            $previous_amount = (float)$row['amount'];
            $created_at = $row['created_at'];

            // Update the investment amount
            $u = $pdo->prepare("UPDATE investments SET amount = :amt WHERE id = :id AND user_id = :uid");
            $u->execute(['amt' => $edit_amount, 'id' => $selected_row, 'uid' => $user_id]);

            // Recalculate total investment (post-update)
            $tq = $pdo->prepare("SELECT IFNULL(SUM(amount),0) AS total FROM investments WHERE user_id = :id");
            $tq->execute(['id' => $user_id]);
            $total_investment = (float)$tq->fetchColumn();

            // Send confirmation email with previous amount and total investment
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
                $mail->Subject = "Your Investment transaction updated successfully";

                $body = "Hello <b>" . htmlspecialchars($user['first_name']) . "</b>,<br><br>"
                      . "Your Investment transaction has been updated successfully.<br><br>"
                      . "<strong>Transaction Details:</strong><br>"
                      . "• Investment ID: {$row['id']}<br>"
                      . "• Amount: Rs. " . number_format($previous_amount, 2) . "<br>"
                      . "• Date: " . htmlspecialchars($created_at) . "<br><br>"
                      . "Your updated total Investment is Rs. " . number_format($total_investment, 2) . ".<br><br>"
                      . "Thank you,<br>"
                      . "Government Cancer Hospital Employee Society";

                $mail->Body = $body;
                $mail->send();
            } catch (Exception $e) {
                error_log("Email sending failed (edit investment): " . $mail->ErrorInfo);
            }

            header("Location: user_details.php?id={$user_id}&edit_success=investment");
            exit;
        }

        if ($edit_type === 'loan') {
            // Fetch previous loan row from investment_calculations
            $s = $pdo->prepare("SELECT id, user_id, principal, interest_amount, total_value, created_at FROM investment_calculations WHERE id = :id AND user_id = :uid");
            $s->execute(['id' => $selected_row, 'uid' => $user_id]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                header("Location: user_details.php?id={$user_id}&edit_error=not_found");
                exit;
            }

            $previous_principal = (float)$row['principal'];
            $previous_interest = (float)$row['interest_amount'];
            $previous_total_value = (float)$row['total_value'];
            $created_at = $row['created_at'];

            // Update total_value only (safe)
            $u = $pdo->prepare("UPDATE investment_calculations SET total_value = :amt WHERE id = :id AND user_id = :uid");
            $u->execute(['amt' => $edit_amount, 'id' => $selected_row, 'uid' => $user_id]);

            // Recalculate remaining loan (post-update)
            $loanSumStmt = $pdo->prepare("SELECT COALESCE(SUM(total_value),0) FROM investment_calculations WHERE user_id = :id");
            $loanSumStmt->execute(['id' => $user_id]);
            $loan_sum = (float)$loanSumStmt->fetchColumn();

            $paidSumStmt = $pdo->prepare("SELECT COALESCE(SUM(receive),0) FROM payments WHERE user_id = :id");
            $paidSumStmt->execute(['id' => $user_id]);
            $paid_sum = (float)$paidSumStmt->fetchColumn();

            $remaining_loan = $loan_sum - $paid_sum;
            if ($remaining_loan < 0) $remaining_loan = 0.0;

            // Send confirmation email using loan row details and remaining loan
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
                $mail->Subject = "Your Loan transaction updated successfully";

                $body = "Hello <b>" . htmlspecialchars($user['first_name']) . "</b>,<br><br>"
                      . "Your Loan transaction has been updated successfully.<br><br>"
                      . "<strong>Transaction Details:</strong><br>"
                      . "• Loan Transaction ID: {$row['id']}<br>"
                      . "• Principal Amount: Rs. " . number_format($previous_principal, 2) . "<br>"
                      . "• Interest Amount: Rs. " . number_format($previous_interest, 2) . "<br>"
                      . "• Total Value (previous): Rs. " . number_format($previous_total_value, 2) . "<br>"
                      . "• Date: " . htmlspecialchars($created_at) . "<br><br>"
                      . "Your updated remaining loan amount is Rs. " . number_format($remaining_loan, 2) . ".<br><br>"
                      . "Thank you,<br>"
                      . "Government Cancer Hospital Employee Society";

                $mail->Body = $body;
                $mail->send();
            } catch (Exception $e) {
                error_log("Email sending failed (edit loan): " . $mail->ErrorInfo);
            }

            header("Location: user_details.php?id={$user_id}&edit_success=loan");
            exit;
        }

        if ($edit_type === 'recover') {
            // Fetch previous payments.receive row
            $s = $pdo->prepare("SELECT id, user_id, receive, created_at FROM payments WHERE id = :id AND user_id = :uid");
            $s->execute(['id' => $selected_row, 'uid' => $user_id]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                header("Location: user_details.php?id={$user_id}&edit_error=not_found");
                exit;
            }

            $previous_receive = (float)$row['receive'];
            $created_at = $row['created_at'];

            // Update the receive amount
            $u = $pdo->prepare("UPDATE payments SET receive = :amt WHERE id = :id AND user_id = :uid");
            $u->execute(['amt' => $edit_amount, 'id' => $selected_row, 'uid' => $user_id]);

            // Recalculate remaining loan (post-update)
            $loanSumStmt = $pdo->prepare("SELECT COALESCE(SUM(total_value),0) FROM investment_calculations WHERE user_id = :id");
            $loanSumStmt->execute(['id' => $user_id]);
            $loan_sum = (float)$loanSumStmt->fetchColumn();

            $paidSumStmt = $pdo->prepare("SELECT COALESCE(SUM(receive),0) FROM payments WHERE user_id = :id");
            $paidSumStmt->execute(['id' => $user_id]);
            $paid_sum = (float)$paidSumStmt->fetchColumn();

            $remaining_loan = $loan_sum - $paid_sum;
            if ($remaining_loan < 0) $remaining_loan = 0.0;

            // Send confirmation email using recover row details and remaining loan
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
                $mail->Subject = "Your Loan Recover transaction updated successfully";

                $body = "Hello <b>" . htmlspecialchars($user['first_name']) . "</b>,<br><br>"
                      . "Your Loan Recover transaction has been updated successfully.<br><br>"
                      . "<strong>Transaction Details:</strong><br>"
                      . "• Recover Transaction ID: {$row['id']}<br>"
                      . "• Amount: Rs. " . number_format($previous_receive, 2) . "<br>"
                      . "• Date: " . htmlspecialchars($created_at) . "<br><br>"
                      . "Your updated remaining loan amount is Rs. " . number_format($remaining_loan, 2) . ".<br><br>"
                      . "Thank you,<br>"
                      . "Government Cancer Hospital Employee Society";

                $mail->Body = $body;
                $mail->send();
            } catch (Exception $e) {
                error_log("Email sending failed (edit recover): " . $mail->ErrorInfo);
            }

            header("Location: user_details.php?id={$user_id}&edit_success=recover");
            exit;
        }

    } catch (Throwable $e) {
        error_log("Edit amount error: " . $e->getMessage());
        header("Location: user_details.php?id={$user_id}&edit_error=server");
        exit;
    }
}

/* ------------------------------------------------------------------
   Data: Investments, Loan Amount, Remaining Loan, Histories
-------------------------------------------------------------------*/

// Fetch investments (list)
$stmt = $pdo->prepare("SELECT id, amount, created_at FROM investments WHERE user_id = :id ORDER BY created_at DESC");
$stmt->execute(['id' => $user_id]);
$investments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Total investment
$stmt = $pdo->prepare("SELECT IFNULL(SUM(amount), 0) AS total FROM investments WHERE user_id = :id");
$stmt->execute(['id' => $user_id]);
$total_investment = (float)$stmt->fetchColumn();

/* Loan amount (SUM of total_value from investment_calculations for this user) */
$loan_amount = 0.0;
try {
    $ls = $pdo->prepare("SELECT COALESCE(SUM(total_value),0) AS loan_amount FROM investment_calculations WHERE user_id = :id");
    $ls->execute(['id' => $user_id]);
    $loan_amount = (float)($ls->fetch(PDO::FETCH_ASSOC)['loan_amount'] ?? 0);
} catch (Throwable $e) {
    $loan_amount = 0.0;
}

/* Total Loan Deposit from payments.receive (Receive Loan) */
$total_loan_deposit = 0.0;
try {
    $dp = $pdo->prepare("SELECT COALESCE(SUM(receive),0) AS t FROM payments WHERE user_id = :id");
    $dp->execute(['id' => $user_id]);
    $total_loan_deposit = (float)($dp->fetch(PDO::FETCH_ASSOC)['t'] ?? 0);
} catch (Throwable $e) {
    $total_loan_deposit = 0.0;
}

/* Remaining Loan Amount */
$remaining_loan_amount = $loan_amount - $total_loan_deposit;
if ($remaining_loan_amount < 0) { $remaining_loan_amount = 0.0; } // clamp at 0 if overpaid

/* Loan history rows for this user (investment_calculations) */
$loan_history = [];
try {
    $lh = $pdo->prepare("
        SELECT id, principal AS principal_amount, interest_amount, total_value, created_at
        FROM investment_calculations
        WHERE user_id = :id
        ORDER BY created_at DESC
    ");
    $lh->execute(['id' => $user_id]);
    $loan_history = $lh->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $loan_history = [];
}

/* Loan Recover history (Receive Loan) from payments.receive */
$loan_recover_history = [];
try {
    $lr = $pdo->prepare("
        SELECT id, receive AS recover_amount, created_at
        FROM payments
        WHERE user_id = :id AND receive IS NOT NULL AND receive > 0
        ORDER BY created_at DESC
    ");
    $lr->execute(['id' => $user_id]);
    $loan_recover_history = $lr->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $loan_recover_history = [];
}

/* For Edit dropdowns we need lists (IDs + readable labels) */
// investments list for edit (id, amount, date)
$investmentsForEdit = $investments; // already fetched

// loan calculations list
$loanCalcStmt = $pdo->prepare("SELECT id, principal, interest_amount, total_value, created_at FROM investment_calculations WHERE user_id = :id ORDER BY created_at DESC");
$loanCalcStmt->execute(['id' => $user_id]);
$loanCalculationsForEdit = $loanCalcStmt->fetchAll(PDO::FETCH_ASSOC);

// payments receive list
$paymentsStmt = $pdo->prepare("SELECT id, receive, created_at FROM payments WHERE user_id = :id AND receive IS NOT NULL AND receive > 0 ORDER BY created_at DESC");
$paymentsStmt->execute(['id' => $user_id]);
$paymentsForEdit = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>User Details - <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></title>
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

    /* Make table cells stay on a single line to encourage horizontal scroll on mobile */
    .table th, .table td {
      white-space: nowrap;
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
      font-size: 18px;
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
      .content { padding: 15px; }
      .table-scrollbox { overflow-x:auto; overflow-y:auto; max-height:400px; -webkit-overflow-scrolling: touch; }
    }

    @media (min-width: 769px) {
      .col-md-2.sidebar { padding-top: 20px; }
    }

    /* small helper */
    .muted-small { font-size: 0.9rem; color: #6c757d; }
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

      <?php if (isset($_GET['msg']) && $_GET['msg'] === 'add_success'): ?>
        <div class="alert alert-success">✅ Amount added successfully.</div>
      <?php endif; ?>

      <?php if (isset($_GET['edit_success'])): ?>
        <div class="alert alert-success">
          <?php
            $t = $_GET['edit_success'];
            if ($t === 'investment') echo "Investment entry updated successfully.";
            elseif ($t === 'loan') echo "Loan calculation entry updated successfully.";
            elseif ($t === 'recover') echo "Loan recover (payment) entry updated successfully.";
            else echo "Updated successfully.";
          ?>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['edit_error'])): ?>
        <div class="alert alert-danger">
          <?php
            $e = $_GET['edit_error'];
            if ($e === 'invalid_type') echo "Invalid edit type.";
            elseif ($e === 'invalid_input') echo "Invalid input provided.";
            elseif ($e === 'not_found') echo "Selected record not found or does not belong to user.";
            else echo "Server error while updating. Check logs.";
          ?>
        </div>
      <?php endif; ?>

      <div class="card p-3">
        <h5>👤 <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h5>
        <p><strong>Email:</strong> <?php echo htmlspecialchars($user['email']); ?></p>
        <p><strong>Contact:</strong> <?php echo htmlspecialchars($user['contact_number']); ?></p>
        <p><strong>Role:</strong> <?php echo htmlspecialchars($user['role']); ?></p>
        <p><strong>Joined:</strong> <?php echo htmlspecialchars($user['created_at']); ?></p>
        <p class="fw-bold text-success mb-1">💰 Total Investment: Rs. <?php echo number_format((float)$total_investment, 2); ?></p>
        <p class="fw-bold text-danger mb-1">🧾 Loan Amount: Rs. <?php echo number_format((float)$loan_amount, 2); ?></p>
        <p class="fw-bold text-primary">🧮 Remaining Loan Amount: Rs. <?php echo number_format((float)$remaining_loan_amount, 2); ?></p>

        <!-- Add Amount Form -->
        <form method="POST" class="row g-2 align-items-center mt-2">
          <div class="col-auto" style="flex:1;">
            <input type="number" name="amount" class="form-control" placeholder="Enter amount" step="0.01" required>
          </div>
          <div class="col-auto">
            <button type="submit" name="add_amount" class="btn btn-primary">Add</button>
          </div>
        </form>
        <?php if (!empty($error)) : ?>
          <p class="text-danger mt-2"><?php echo $error; ?></p>
        <?php endif; ?>

        <!-- ===== Edit Amount Section ===== -->
        <hr>
        <h6 class="mt-3">✏️ Edit Amount</h6>
        <p class="muted-small">Choose a type, pick the specific row to edit, enter new amount and confirm.</p>

        <form id="editForm" method="POST" onsubmit="return confirmEdit();">
          <input type="hidden" name="edit_submit" value="1">

          <div class="row g-2 align-items-center">
            <div class="col-md-4">
              <label class="form-label">Type</label>
              <select name="edit_type" id="edit_type" class="form-select" required>
                <option value="">-- Select Type --</option>
                <option value="investment">Investment</option>
                <option value="loan">Loan Amount</option>
                <option value="recover">Recover Loan Amount</option>
              </select>
            </div>

            <div class="col-md-5">
              <label class="form-label">Select Row</label>
              <!-- dynamic select; populated by JS from server-rendered JSON -->
              <select name="selected_row" id="selected_row" class="form-select" required>
                <option value="">-- Select a row --</option>
              </select>
              <div id="selectedRowInfo" class="muted-small mt-1"></div>
            </div>

            <div class="col-md-3">
              <label class="form-label">New Amount (Rs.)</label>
              <input type="number" name="edit_amount" id="edit_amount" class="form-control" step="0.01" min="0" required>
            </div>
          </div>

          <div class="mt-2">
            <button type="submit" class="btn btn-warning">Update Selected</button>
            <a href="user_details.php?id=<?php echo $user_id; ?>" class="btn btn-secondary">Cancel</a>
          </div>
        </form>
        <!-- End Edit section -->

      </div>

      <div class="card p-3">
        <h5>📑 Investment History</h5>
        <div class="table-responsive">
          <table class="table table-striped table-hover align-middle">
            <thead class="table-dark">
              <tr>
                <th>ID</th>
                <th>Amount (Rs.)</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($investments)) : ?>
                <?php foreach ($investments as $inv) : ?>
                  <tr>
                    <td><?php echo htmlspecialchars($inv['id']); ?></td>
                    <td><?php echo number_format((float)$inv['amount'], 2); ?></td>
                    <td><?php echo htmlspecialchars($inv['created_at']); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php else : ?>
                <tr>
                  <td colspan="3" class="text-center">No investments found</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Loan History -->
      <div class="card p-3">
        <h5>🧾 Loan History</h5>
        <div class="table-responsive table-scrollbox">
          <table class="table table-striped table-hover align-middle">
            <thead class="table-dark">
              <tr>
                <th>ID</th>
                <th>Principal Amount (Rs.)</th>
                <th>Interest Amount (Rs.)</th>
                <th>Total Value (Rs.)</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($loan_history)) : ?>
                <?php foreach ($loan_history as $lh) : ?>
                  <tr>
                    <td><?php echo htmlspecialchars($lh['id']); ?></td>
                    <td><?php echo number_format((float)$lh['principal_amount'], 2); ?></td>
                    <td><?php echo number_format((float)$lh['interest_amount'], 2); ?></td>
                    <td><strong><?php echo number_format((float)$lh['total_value'], 2); ?></strong></td>
                    <td><?php echo htmlspecialchars($lh['created_at']); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php else : ?>
                <tr>
                  <td colspan="5" class="text-center">No loan transactions found</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Loan Recover History -->
      <div class="card p-3">
        <h5>💳 Loan Recover History</h5>
        <div class="table-responsive table-scrollbox">
          <table class="table table-striped table-hover align-middle">
            <thead class="table-dark">
              <tr>
                <th>ID</th>
                <th>Recover Amount (Rs.)</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($loan_recover_history)) : ?>
                <?php foreach ($loan_recover_history as $rec) : ?>
                  <tr>
                    <td><?php echo htmlspecialchars($rec['id']); ?></td>
                    <td><?php echo number_format((float)$rec['recover_amount'], 2); ?></td>
                    <td><?php echo htmlspecialchars($rec['created_at']); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php else : ?>
                <tr>
                  <td colspan="2" class="text-center">No recover transactions found</td>
                </tr>
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

  // Server-side lists converted to JS arrays for client selection
  const investmentsList = <?php echo json_encode($investmentsForEdit, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
  const loanCalcList = <?php echo json_encode($loanCalculationsForEdit, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
  const paymentsList = <?php echo json_encode($paymentsForEdit, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;

  const editTypeEl = document.getElementById('edit_type');
  const selectedRowEl = document.getElementById('selected_row');
  const selectedRowInfo = document.getElementById('selectedRowInfo');

  // populate select based on chosen type
  editTypeEl.addEventListener('change', function() {
    const t = this.value;
    selectedRowEl.innerHTML = '<option value="">-- Select a row --</option>';
    selectedRowInfo.textContent = '';

    if (t === 'investment') {
      investmentsList.forEach(it => {
        const opt = document.createElement('option');
        opt.value = it.id;
        opt.text = `#${it.id} — Rs. ${Number(it.amount).toFixed(2)} — ${it.created_at}`;
        selectedRowEl.appendChild(opt);
      });
    } else if (t === 'loan') {
      loanCalcList.forEach(it => {
        const opt = document.createElement('option');
        opt.value = it.id;
        opt.text = `#${it.id} — Total: Rs. ${Number(it.total_value).toFixed(2)} — ${it.created_at}`;
        selectedRowEl.appendChild(opt);
      });
    } else if (t === 'recover') {
      paymentsList.forEach(it => {
        const opt = document.createElement('option');
        opt.value = it.id;
        opt.text = `#${it.id} — Rs. ${Number(it.receive).toFixed(2)} — ${it.created_at}`;
        selectedRowEl.appendChild(opt);
      });
    }
  });

  // show small info when row selected
  selectedRowEl.addEventListener('change', function() {
    const id = Number(this.value);
    selectedRowInfo.textContent = '';
    if (!id) return;
    const t = editTypeEl.value;
    if (t === 'investment') {
      const o = investmentsList.find(x=>Number(x.id)===id);
      if (o) selectedRowInfo.textContent = `Current amount: Rs. ${Number(o.amount).toFixed(2)} — Date: ${o.created_at}`;
    } else if (t === 'loan') {
      const o = loanCalcList.find(x=>Number(x.id)===id);
      if (o) selectedRowInfo.textContent = `Current total: Rs. ${Number(o.total_value).toFixed(2)} — Principal: Rs. ${Number(o.principal).toFixed(2)}`;
    } else if (t === 'recover') {
      const o = paymentsList.find(x=>Number(x.id)===id);
      if (o) selectedRowInfo.textContent = `Current receive: Rs. ${Number(o.receive).toFixed(2)} — Date: ${o.created_at}`;
    }
  });

  // confirm before submit
  function confirmEdit() {
    const type = editTypeEl.value;
    const sel = selectedRowEl.value;
    const amt = document.getElementById('edit_amount').value;

    if (!type || !sel || !amt) {
      alert('Please choose type, row and enter valid amount.');
      return false;
    }

    const typeLabel = (type === 'investment') ? 'Investment' : (type === 'loan' ? 'Loan Amount' : 'Recover Loan Amount');
    return confirm(`Are you sure you want to update ${typeLabel} (id #${sel}) to Rs. ${parseFloat(amt).toFixed(2)} ? This will modify the selected historical record.`);
  }
</script>
</body>
</html>
