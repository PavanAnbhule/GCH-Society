<?php
// investdetails.php — per-user loan add + per-user cards + email on add + receive loan (debit)
declare(strict_types=1);
session_start();
require_once "db.php";

/* ---- PHPMailer (for email notifications) ---- */
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/email/email_config.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* ------------------ Prefill from user id if present ------------------ */
$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$prefill_employee_name = '';
$user_email = '';
$prefill_principal = 0.0; // default if no prior amount

if ($user_id > 0) {
    try {
        // Get user name + email
        $u = $pdo->prepare("SELECT first_name, last_name, email FROM users WHERE id = :id LIMIT 1");
        $u->execute(['id' => $user_id]);
        if ($row = $u->fetch(PDO::FETCH_ASSOC)) {
            $prefill_employee_name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            $user_email = (string)($row['email'] ?? '');
        }

        // Prefill principal with user's previous investments sum
        $inv = $pdo->prepare("SELECT COALESCE(SUM(amount),0) AS prev_amount FROM investments WHERE user_id = :id");
        $inv->execute(['id' => $user_id]);
        $prefill_principal = (float)($inv->fetch(PDO::FETCH_ASSOC)['prev_amount'] ?? 0);
    } catch (Throwable $e) {
        // ignore
    }
}

/* ------------------ Helpers ------------------ */
function compute_interest(float $P, float $R, float $time, string $unit, string $itype): array {
    // Convert to years
    if ($unit === 'days')      { $T = $time / 365; }
    elseif ($unit === 'months'){ $T = $time / 12;  }
    else                       { $T = $time;       }

    $interest = ($itype === 'compound')
        ? $P * (pow(1 + $R/100, $T) - 1)
        : ($P * $R * $T) / 100;

    $total = $P + $interest;
    return [$T, $interest, $total];
}

function fetch_user_totals(PDO $pdo, int $uid): array {
    $res = [
        'total_investment'   => 0.0,
        'total_loan_amount'  => 0.0,
        'total_loan_deposit' => 0.0,
        'remaining_loan'     => 0.0,
    ];

    if ($uid <= 0) return $res;

    try {
        $q1 = $pdo->prepare("SELECT COALESCE(SUM(amount),0) AS t FROM investments WHERE user_id = :uid");
        $q1->execute(['uid' => $uid]);
        $res['total_investment'] = (float)($q1->fetch(PDO::FETCH_ASSOC)['t'] ?? 0);

        $q2 = $pdo->prepare("SELECT COALESCE(SUM(total_value),0) AS t FROM investment_calculations WHERE user_id = :uid");
        $q2->execute(['uid' => $uid]);
        $res['total_loan_amount'] = (float)($q2->fetch(PDO::FETCH_ASSOC)['t'] ?? 0);

        // deposit = SUM(payments.receive)
        $q3 = $pdo->prepare("SELECT COALESCE(SUM(receive),0) AS t FROM payments WHERE user_id = :uid");
        $q3->execute(['uid' => $uid]);
        $res['total_loan_deposit'] = (float)($q3->fetch(PDO::FETCH_ASSOC)['t'] ?? 0);

        $res['remaining_loan'] = $res['total_loan_amount'] - $res['total_loan_deposit'];
    } catch (Throwable $e) {}

    return $res;
}

/* ------------------ Flash messages ------------------ */
$flash_success = '';
$flash_error   = '';

/* ------------------ Add Loan handler (+ email: credited) ------------------ */
if (isset($_POST['action']) && $_POST['action'] === 'save_db') {
    try {
        $employee_name = trim($_POST['employee_name'] ?? '');
        $P      = (float)($_POST['principal'] ?? 0);
        $R      = (float)($_POST['annual_rate'] ?? 0);
        $time   = (float)($_POST['period_value'] ?? 0);
        $unit   = $_POST['period_unit'] ?? 'months';         // days|months|years
        $itype  = $_POST['interest_type'] ?? 'simple';       // simple|compound
        $uid    = (int)($_POST['user_id'] ?? 0);

        if ($employee_name === '' || $P < 0 || $R < 0 || $time <= 0 || !in_array($unit, ['days','months','years'], true) || !in_array($itype, ['simple','compound'], true)) {
            throw new RuntimeException('Please fill all fields correctly.');
        }

        [, $interest, $total] = compute_interest($P, $R, $time, $unit, $itype);

        // Insert loan calculation (credit)
        $stmt = $pdo->prepare("
            INSERT INTO investment_calculations
            (user_id, employee_name, principal, annual_rate, period_unit, period_value, interest_type, interest_amount, total_value, created_at)
            VALUES (:user_id, :employee_name, :principal, :annual_rate, :period_unit, :period_value, :interest_type, :interest_amount, :total_value, NOW())
        ");
        $stmt->execute([
            'user_id'        => $uid > 0 ? $uid : null,
            'employee_name'  => $employee_name,
            'principal'      => $P,
            'annual_rate'    => $R,
            'period_unit'    => $unit,
            'period_value'   => (int)$time,
            'interest_type'  => $itype,
            'interest_amount'=> $interest,
            'total_value'    => $total
        ]);

        $flash_success = "✅ Loan added for “" . htmlspecialchars($employee_name) . "”.";

        // Email: credited
        if ($uid > 0 && !empty($user_email)) {
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
                $mail->addAddress($user_email, $employee_name);

                $mail->isHTML(true);
                $mail->Subject = "Loan Amount Credited";
                $amtTxt = "Rs. " . number_format($total, 2);
                $mail->Body    = "Hello <b>" . htmlspecialchars($employee_name) . "</b>,<br><br>"
                               . "Your Loan amount <b>{$amtTxt}</b> is credited to your GCH account.<br><br>"
                               . "Thank you,<br>"
                               . "Government Cancer Hospital Employee Society";
                $mail->AltBody = "Hello {$employee_name},\n\nYour Loan amount {$amtTxt} is credited to your GCH account.\n\nThank you,\nGovernment Cancer Hospital Employee Society";
                $mail->send();
            } catch (Exception $e) {
                error_log("Loan credit email failed: " . $e->getMessage());
            }
        }
    } catch (Throwable $t) {
        $flash_error = "❌ " . $t->getMessage();
    }
}

/* ------------------ Receive Loan handler (+ email: debited) ------------------ */
if (isset($_POST['action']) && $_POST['action'] === 'receive_loan') {
    try {
        $uid = (int)($_POST['user_id'] ?? 0);
        $receive_amount = (float)($_POST['receive_amount'] ?? 0);

        if ($uid <= 0 || $receive_amount <= 0) {
            throw new RuntimeException('Please enter a valid receive amount.');
        }

        // Save into payments.receive
        $p = $pdo->prepare("INSERT INTO payments (user_id, receive, created_at) VALUES (:uid, :receive, NOW())");
        $p->execute(['uid' => $uid, 'receive' => $receive_amount]);

        // Recompute totals to get remaining balance AFTER this receive
        $totals = fetch_user_totals($pdo, $uid);
        $remaining_after = (float)$totals['remaining_loan'];

        $flash_success = "✅ Received Rs. " . number_format($receive_amount, 2) . " against the loan. Remaining balance: Rs. " . number_format($remaining_after, 2) . ".";

        // Email: debited confirmation
        if (!empty($user_email) && !empty($prefill_employee_name)) {
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
                $mail->addAddress($user_email, $prefill_employee_name);

                $mail->isHTML(true);
                $mail->Subject = "Loan Amount Debited Successfully";
                $amtTxt = "Rs. " . number_format($receive_amount, 2);
                $remainTxt = "Rs. " . number_format($remaining_after, 2);
                $mail->Body    = "Hello <b>" . htmlspecialchars($prefill_employee_name) . "</b>,<br><br>"
                               . "Your loan amount <b>{$amtTxt}</b> is debited successfully. Your remaining loan balance is <b>{$remainTxt}</b>.<br><br>"
                               . "Thank you,<br>"
                               . "Government Cancer Hospital Employee Society";
                $mail->AltBody = "Hello {$prefill_employee_name},\n\nYour loan amount {$amtTxt} is debited successfully. Your remaining loan balance is {$remainTxt}.\n\nThank you,\nGovernment Cancer Hospital Employee Society";
                $mail->send();
            } catch (Exception $e) {
                error_log("Loan debit email failed: " . $e->getMessage());
            }
        }

    } catch (Throwable $t) {
        $flash_error = "❌ " . $t->getMessage();
    }
}

/* ------------------ Per-user Top cards (compute last to reflect any POST) ------------------ */
$totals = fetch_user_totals($pdo, $user_id);
$u_total_investment   = $totals['total_investment'];
$u_total_loan_amount  = $totals['total_loan_amount'];
$u_total_loan_deposit = $totals['total_loan_deposit'];
$u_remaining_loan     = $totals['remaining_loan'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>🧾 Add Loan</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    body{background:#f8f9fa;}
    .sidebar{height:100vh;background:#1e3a5f;color:#fff;padding-top:20px;transition:transform .3s ease;}
    .sidebar a{color:#fff;display:block;padding:12px 20px;text-decoration:none;}
    .sidebar a:hover{background:#2d4b75;}
    .content{padding:20px;}
    .card{border-radius:12px;box-shadow:0 2px 6px rgba(0,0,0,.1);margin-bottom:20px;}
    .mobile-header{display:none;background:#fff;padding:10px 15px;border-bottom:1px solid #ddd;display:flex;align-items:center;justify-content:space-between;}
    .mobile-header h4{margin:0;font-weight:bold;font-size:16px;}
    .menu-toggle{background:none;border:none;font-size:22px;cursor:pointer;}
    @media (max-width:768px){
      .sidebar{position:fixed;top:0;left:0;width:220px;transform:translateX(-100%);z-index:1000;}
      .sidebar.active{transform:translateX(0);}
      .overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:900;}
      .overlay.active{display:block;}
      .col-md-2.sidebar{position:fixed;height:100%;}
      .col-md-10.content{margin-left:0!important;}
      .mobile-header{display:flex;}
    }
    .calc-card{padding:30px;}
    .calc-result{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.1);padding:25px;text-align:center;}
    .calc-result .rowline{display:flex;justify-content:space-between;padding:.6rem 0;border-top:1px solid #eee;}
    .calc-result .rowline:last-child{border-bottom:1px solid #eee;}
  </style>
</head>
<body>

<!-- Mobile top bar -->
<div class="mobile-header d-md-none">
  <h4>शासकीय कर्करोग रुग्णालय कर्मचारी सहकारी पतसंस्था</h4>
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
      <a href="pay.php">💰 Pay / Receive</a>
      <a href="logout.php">🚪 Logout</a>
    </div>

    <!-- Main content -->
    <div class="col-md-10 content">
      <!-- Cards (per-user) -->
      <div class="row mb-4">
        <div class="col-md-3"><div class="card p-4 text-center"><h5>Total Investment</h5><h3>₹<?=number_format($u_total_investment,2)?></h3></div></div>
        <div class="col-md-3"><div class="card p-4 text-center"><h5>Total Loan Amount</h5><h3>₹<?=number_format($u_total_loan_amount,2)?></h3></div></div>
        <div class="col-md-3"><div class="card p-4 text-center"><h5>Total Loan Deposit</h5><h3>₹<?=number_format($u_total_loan_deposit,2)?></h3></div></div>
        <div class="col-md-3"><div class="card p-4 text-center"><h5>Remaining Loan Amount</h5><h3>₹<?=number_format($u_remaining_loan,2)?></h3></div></div>
      </div>

      <?php if ($flash_success): ?><div class="alert alert-success"><?= $flash_success ?></div><?php endif; ?>
      <?php if ($flash_error):   ?><div class="alert alert-danger"><?= $flash_error   ?></div><?php endif; ?>

      <?php if ($user_id): ?>
        <div class="alert alert-info">Viewing Loan Details for <strong>User ID #<?= (int)$user_id ?></strong> <?= $prefill_employee_name ? '('.htmlspecialchars($prefill_employee_name).')' : '' ?></div>
      <?php else: ?>
        <div class="alert alert-warning">No user selected. Append <code>?id=USER_ID</code> in the URL.</div>
      <?php endif; ?>

      <!-- ======================= Receive Loan UI ======================= -->
      <div class="card p-4 mb-3">
        <h3 class="mb-3">✅ Receive Loan</h3>
        <form method="post" onsubmit="return confirmReceive();">
          <input type="hidden" name="user_id" value="<?= (int)$user_id ?>">
          <div class="row g-3 align-items-end">
            <div class="col-md-4">
              <label class="form-label">Amount to Receive (₹)</label>
              <input type="number" class="form-control" name="receive_amount" id="receive_amount" min="0.01" step="0.01" required>
            </div>
            <div class="col-md-3">
              <button type="submit" name="action" value="receive_loan" class="btn btn-success w-100">Receive</button>
            </div>
          </div>
        </form>
        <div class="small text-muted mt-2">This will increase <b>Total Loan Deposit</b> and reduce <b>Remaining Loan Amount</b>.</div>
      </div>
      <!-- =================================================================== -->

      <!-- Add Loan UI -->
      <div class="card calc-card">
        <h3 class="mb-4">🧾 Add Loan</h3>
        <div class="row g-4">
          <!-- Left form -->
          <div class="col-md-6">
            <!-- One form (Save to DB). Submit asks for confirmation -->
            <form id="calcForm" method="post" onsubmit="return handleSubmitConfirm();">
              <input type="hidden" name="user_id" value="<?= (int)$user_id ?>">

              <div class="mb-3">
                <label class="form-label d-block">Simple/Compound Interest</label>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" name="interest_type" id="simple" value="simple" checked>
                  <label class="form-check-label" for="simple">Simple Interest</label>
                </div>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" name="interest_type" id="compound" value="compound">
                  <label class="form-check-label" for="compound">Compound Interest</label>
                </div>
              </div>

              <div class="mb-3">
                <label class="form-label">Employee Name</label>
                <input type="text" class="form-control" name="employee_name" id="employee_name" placeholder="e.g., Ram Pande" value="<?= htmlspecialchars($prefill_employee_name) ?>">
              </div>

              <div class="mb-3">
                <label class="form-label">Principal Amount (₹)</label>
                <input
                  type="number"
                  class="form-control"
                  name="principal"
                  id="principal"
                  value="<?= number_format($prefill_principal, 2, '.', '') ?>"
                  min="0"
                  step="0.01"
                  required
                >
              </div>

              <div class="mb-3">
                <label class="form-label">Annual rate (%)</label>
                <input type="number" class="form-control" name="annual_rate" id="rate" value="3" min="0" step="0.01" required>
              </div>

              <div class="mb-3">
                <label class="form-label">Period Unit</label>
                <select class="form-select" name="period_unit" id="periodUnit">
                  <option value="days">Days</option>
                  <option value="months" selected>Months</option>
                  <option value="years">Years</option>
                </select>
              </div>

              <div class="mb-3">
                <label class="form-label">Period Value</label>
                <input type="number" class="form-control" name="period_value" id="time" value="1" min="1" step="1" required>
              </div>

              <div class="d-grid gap-3">
                <!-- Primary: Calculate + confirm + save -->
                <button
                  type="submit"
                  name="action"
                  value="save_db"
                  class="btn btn-primary w-100"
                  onclick="calculateInterest();"
                  title="Calculate and add the loan"
                >
                  submit
                </button>
              </div>
            </form>
          </div>

          <!-- Right results -->
          <div class="col-md-6">
            <div class="calc-result">
              <div style="height:200px;">
                <canvas id="chartGauge"></canvas>
              </div>

              <div class="mt-4 text-start">
                <div class="rowline"><strong>Interest Earned</strong><span>₹<span id="interestEarned">0.00</span></span></div>
                <div class="rowline"><strong>Principal Amount</strong><span>₹<span id="principalAmount">0.00</span></span></div>
                <div class="rowline"><strong>Total Value</strong><span>₹<span id="totalValue">0.00</span></span></div>
              </div>
            </div>
          </div>
        </div>
      </div> <!-- /card -->
    </div>
  </div>
</div>

<script>
/* Sidebar toggles (mobile) */
const menuToggle=document.querySelector('.menu-toggle');
const sidebar=document.querySelector('.sidebar');
const overlay=document.querySelector('.overlay');
if(menuToggle){menuToggle.addEventListener('click',()=>{sidebar.classList.toggle('active');overlay.classList.toggle('active');});}
if(overlay){overlay.addEventListener('click',()=>{sidebar.classList.remove('active');overlay.classList.remove('active');});}

/* Chart.js semicircle doughnut */
let chart;
function drawChart(principal, interest){
  const ctx=document.getElementById('chartGauge').getContext('2d');
  if(chart) chart.destroy();
  chart=new Chart(ctx,{
    type:'doughnut',
    data:{
      labels:['Principal','Interest'],
      datasets:[{
        data:[principal, interest],
        backgroundColor:['#2ecc71','#a5d6f9'],
        cutout:'80%'
      }]
    },
    options:{
      rotation:-90,
      circumference:180,
      plugins:{legend:{display:true,position:'bottom'}},
      responsive:true,
      maintainAspectRatio:false
    }
  });
}

/* Validation + Calculate (for Add Loan) */
function validateInputs(){
  const P=parseFloat(document.getElementById('principal').value);
  const R=parseFloat(document.getElementById('rate').value);
  const T=parseFloat(document.getElementById('time').value);
  const name=document.getElementById('employee_name').value.trim();
  if(!name){
    alert('Please enter Employee Name.');
    return false;
  }
  if(isNaN(P)||P<0||isNaN(R)||R<0||isNaN(T)||T<=0){
    alert('Please enter valid positive numbers (principal can be 0).');
    return false;
  }
  return true;
}

function yearsFrom(unit, time){
  if(unit==='days') return time/365;
  if(unit==='months') return time/12;
  return time;
}

function calculateInterest(){
  if(!validateInputs()) return false;

  const itype=document.querySelector('input[name="interest_type"]:checked').value;
  const P=parseFloat(document.getElementById('principal').value);
  const R=parseFloat(document.getElementById('rate').value);
  const time=parseFloat(document.getElementById('time').value);
  const unit=document.getElementById('periodUnit').value;

  const Ty=yearsFrom(unit, time);
  let interest=0;
  if(itype==='compound') interest=P*(Math.pow(1+R/100, Ty)-1);
  else interest=(P*R*Ty)/100;

  const total=P+interest;

  document.getElementById('interestEarned').innerText=interest.toFixed(2);
  document.getElementById('principalAmount').innerText=P.toFixed(2);
  document.getElementById('totalValue').innerText=total.toFixed(2);

  drawChart(P, interest);
  return true;
}

/* Confirm before submitting the Add Loan (save_db) */
function handleSubmitConfirm(){
  const active = document.activeElement;
  const isSave = active && active.name === 'action' && active.value === 'save_db';
  if(isSave){
    if(!validateInputs()) return false;
    const ok = confirm('Are you sure you want to add this loan to the user account?');
    return ok;
  }
  return true;
}

/* Confirm before receiving loan (debit) */
function confirmReceive(){
  const amt = parseFloat(document.getElementById('receive_amount').value);
  if(isNaN(amt) || amt <= 0){
    alert('Please enter a valid receive amount.');
    return false;
  }
  return confirm('Receive ₹' + amt.toFixed(2) + ' against this loan?');
}

/* First render (uses server-side prefilled principal value) */
calculateInterest();
</script>
</body>
</html>
