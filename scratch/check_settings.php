<?php
require 'db_connect.php';
$pdo = ab_pdo();
$stmt = $pdo->query('SELECT * FROM tbl_system_settings');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
