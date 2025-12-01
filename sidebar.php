<?php
$current_page = basename($_SERVER['PHP_SELF']); // get current file name
?>
<a href="user_dashboard.php" class="<?php echo ($current_page == 'user_dashboard.php') ? 'active' : ''; ?>">🏠 Home</a>

<a href="user_dashboard.php" class="<?php echo ($current_page == 'user_dashboard.php') ? 'active' : ''; ?>">👤 User Profile</a>

<a href="transactions.php" class="<?php echo ($current_page == 'transactions.php') ? 'active' : ''; ?>">💰 Transaction</a>



<a href="logout.php">🚪 Logout</a>
