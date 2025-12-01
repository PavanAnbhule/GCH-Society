<?php
// header.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Dashboard</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <style>
    body { background-color: #f8f9fa; }
    .sidebar {
      height: 100vh;
      background: #1e3a5f;
      color: #fff;
      padding-top: 20px;
    }
    .sidebar a {
      color: #fff;
      display: block;
      padding: 12px 20px;
      text-decoration: none;
    }
    .sidebar a:hover {
      background: #2d4b75;
    }
    .content { padding: 20px; }
    .card {
      border-radius: 12px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    }
  </style>
</head>
<body>
<div class="container-fluid">
  <div class="row">
    <!-- Sidebar -->
    <div class="col-md-2 sidebar">
      <h4 class="text-center mb-4">Menu</h4>
      <a href="admin_dashboard.php">📊 Dashboard</a>
      <a href="view_users.php">👥 View All Users</a>
      <a href="#">➕ Add User</a>
      <a href="#">🚪 Logout</a>
    </div>

    <!-- Main content -->
    <div class="col-md-10 content">
