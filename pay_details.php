<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require_once "db.php";

// --- Fetch total investment ---
$invQuery = $pdo->query("SELECT SUM(amount) AS total_investment FROM investments");
$invRow = $invQuery->fetch(PDO::FETCH_ASSOC);
$total_investment = $invRow['total_investment'] ?? 0;

// --- Fetch total payments ---
$payQuery = $pdo->query("SELECT SUM(amount) AS total_paid FROM payments");
$payRow = $payQuery->fetch(PDO::FETCH_ASSOC);
$total_paid = $payRow['total_paid'] ?? 0;

// --- Remaining balance ---
$remaining_balance = $total_investment - $total_paid;

// --- Fetch total payments stats ---
$statsQuery = $pdo->query("SELECT SUM(amount) AS total_paid, COUNT(*) AS total_transactions FROM payments");
$stats = $statsQuery->fetch(PDO::FETCH_ASSOC);

// --- Fetch payment history ---
$payments = $pdo->query("SELECT * FROM payments ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User - Payment Details</title>
    <link rel="stylesheet" href="style.css"> <!-- ✅ Using your global CSS -->
</head>
<body>
<div class="container">

    <!-- ✅ Mobile Header -->
    <div class="mobile-header">
        <span class="hamburger" onclick="openSidebar()">☰</span>
        <img src="logo.png" alt="Silvee Logo" class="logo"> <!-- replace with your logo -->
        <div class="icons">
            <span class="icon">👤</span>
            <span class="icon">❤️</span>
            <span class="icon">🛒</span>
        </div>
    </div>

    <!-- ✅ Overlay for mobile -->
    <div class="sidebar-overlay" onclick="closeSidebar()"></div>

    <!-- ✅ Sidebar -->
    <?php include "sidebar.php"; ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-heading">Payment Details</div>

        <!-- Summary Cards -->
        <div class="card">
            <h3>📊 Summary</h3>
            <p><strong>Total Investments:</strong> Rs. <?= number_format($total_investment, 2) ?></p>
            <p><strong>Total Payments:</strong> Rs. <?= number_format($stats['total_paid'] ?? 0, 2) ?></p>
            <p><strong>Remaining Balance:</strong> Rs. <?= number_format($remaining_balance, 2) ?></p>
        </div>

        <!-- Payment History -->
        <div class="card" style="margin-top:20px;">
            <h3>📜 Payment History</h3>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Full Name</th>
                        <th>Mobile</th>
                        <th>Amount</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($payments) > 0): ?>
                        <?php foreach ($payments as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['id']) ?></td>
                                <td><?= htmlspecialchars($row['full_name']) ?></td>
                                <td><?= htmlspecialchars($row['mobile']) ?></td>
                                <td>Rs. <?= number_format($row['amount'], 2) ?></td>
                                <td><?= htmlspecialchars($row['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5">No payments found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

<script>
function openSidebar() {
    document.querySelector('.sidebar').classList.add('mobile-open');
    document.querySelector('.sidebar-overlay').style.display = 'block';
}
function closeSidebar() {
    document.querySelector('.sidebar').classList.remove('mobile-open');
    document.querySelector('.sidebar-overlay').style.display = 'none';
}
</script>
</body>
</html>
