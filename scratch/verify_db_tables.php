<?php
require_once __DIR__ . '/../db_connect.php';
try {
    $pdo = ab_pdo();
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables in anybuddy_db:\n";
    foreach ($tables as $t) {
        echo "- " . $t . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
