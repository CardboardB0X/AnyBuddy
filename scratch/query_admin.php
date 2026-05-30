<?php
require 'db_connect.php';
$pdo = ab_pdo();
$stmt = $pdo->prepare("SELECT * FROM tbl_users WHERE role = 'admin'");
$stmt->execute();
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
