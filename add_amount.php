<?php
include 'db.php';
if (!isset($_SESSION["user_id"]) || $_SESSION["role"] != "admin") {
    header("Location: login.php");
    exit;
}

$user_id = $_GET["id"];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $amount = $_POST["amount"];
    $stmt = $pdo->prepare("INSERT INTO investments (user_id, amount) VALUES (?, ?)");
    $stmt->execute([$user_id, $amount]);
    echo "Investment added! <a href='admin_dashboard.php'>Back</a>";
    exit;
}
?>
<form method="POST">
    Amount: <input type="number" step="0.01" name="amount"><br>
    <button type="submit">Add Investment</button>
</form>
