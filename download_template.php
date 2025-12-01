<?php
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="user_template.csv"');

$output = fopen("php://output", "w");
fputcsv($output, ["first_name", "last_name", "email", "contact_number", "password", "confirm_password"]);
fclose($output);
exit;
