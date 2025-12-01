<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit;
}

include 'db.php';

try {
    $stmt = $pdo->prepare("SELECT first_name, last_name, email, contact_number, role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION["user_id"]]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_destroy();
        header("Location: login.php");
        exit;
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>User Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"> 
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">

  <style>
    body { background-color: #f8f9fa; }
    .content { padding: 20px; }
    .card {
      border-radius: 12px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    }

    /* ✅ Sidebar base */
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
      font-size: 15px;
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
        width: 240px;
        height: 100%;
        transform: translateX(-100%);
        transition: transform 0.3s ease;
        z-index: 1000;
        padding-top: 60px; /* space below header */
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
    <!-- Sidebar (menu items inside sidebar.php) -->
    <div class="col-md-2 p-0">
      <div class="sidebar">
        <?php include 'sidebar.php'; ?>
      </div>
    </div>

    <!-- Main content -->
    <div class="col-md-10 content">
      <div class="top-heading mb-4">User Details</div>

      <div class="card p-4">
          <h2>Welcome, <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></h2>
          <p><strong>Email:</strong> <?= htmlspecialchars($user['email']) ?></p>
          <p><strong>Contact Number:</strong> <?= htmlspecialchars($user['contact_number']) ?></p>
          <p><strong>Role:</strong> <?= ucfirst(htmlspecialchars($user['role'])) ?></p>
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
