<?php
require 'db.php';

// Handle search input
$search = isset($_GET['search']) ? trim($_GET['search']) : "";

// Fetch users with optional search
$query = "
    SELECT u.id, u.first_name, u.last_name, u.email,
           IFNULL(SUM(i.amount), 0) AS total_investment
    FROM users u
    LEFT JOIN investments i ON u.id = i.user_id
    WHERE u.first_name LIKE :search
       OR u.last_name LIKE :search
       OR u.email LIKE :search
    GROUP BY u.id, u.first_name, u.last_name, u.email
    ORDER BY u.id ASC
";
$stmt = $pdo->prepare($query);
$stmt->execute(['search' => "%$search%"]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>All Users</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"> 
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background-color: #f8f9fa; }
    .sidebar {
      height: 100vh;
      background: #1e3a5f;
      color: #fff;
      padding-top: 20px;
      transition: transform 0.3s ease;
    }
    .sidebar h4 {
      text-align: center;
      font-size: 20px;
      margin-bottom: 20px;
    }
    .sidebar a {
      display: block;
      padding: 12px 20px;
      color: white;
      text-decoration: none;
      transition: 0.3s;
    }
    .sidebar a:hover { background: #2d4b75; }
    .content { padding: 20px; }
    .card {
      border-radius: 12px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.1);
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
      <h4 class="mb-4">Menu</h4>
      <a href="admin_dashboard.php">📊 Dashboard</a>
      <a href="view_users.php">👥 View All Users</a>
      <a href="add_user.php">➕ Add User</a>
      <a href="pay.php">💰 Pay</a>
      <a href="logout.php">🚪 Logout</a>
    </div>

    <!-- Main content -->
    <div class="col-md-10 content">
      <h3 class="mb-4">All Registered Users</h3>

      <!-- Search Form -->
      <form method="get" class="mb-3 d-flex" style="max-width: 500px;">
        <input type="text" name="search" class="form-control me-2" placeholder="Search by name or email"
               value="<?php echo htmlspecialchars($search); ?>">
        <button type="submit" class="btn btn-primary me-2">Search</button>
        <a href="view_users.php" class="btn btn-secondary">Reset</a>
      </form>

      <div class="card p-3">
        <!-- ✅ Responsive table wrapper -->
        <div class="table-responsive">
          <table class="table table-striped table-hover align-middle">
            <thead class="table-dark">
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Total Investment (Rs.)</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($users)) : ?>
                <?php foreach ($users as $user) : ?>
                  <tr>
                    <td>
                      <a href="user_details.php?id=<?php echo $user['id']; ?>">
                        <?php echo htmlspecialchars($user['id']); ?>
                      </a>
                    </td>
                    <td><?php echo htmlspecialchars($user['first_name'] . " " . $user['last_name']); ?></td>
                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                    <td><?php echo number_format($user['total_investment'], 2); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php else : ?>
                <tr>
                  <td colspan="4" class="text-center">No users found</td>
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
