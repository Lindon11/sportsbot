<?php
$h=getenv('DB_HOST');
$db=getenv('DB_DATABASE');
$u=getenv('DB_USERNAME');
$p=getenv('DB_PASSWORD');
try {
    $pdo=new PDO("mysql:host=$h;dbname=$db;charset=utf8mb4", $u, $p);
    $stmt=$pdo->query("SELECT id,username,email FROM users WHERE id=70");
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "PDO connected to: $h/$db with $u\n";
    if (empty($rows)) {
        echo "No rows\n";
    } else {
        print_r($rows);
    }
} catch (Exception $e) {
    echo 'PDO error: '.$e->getMessage()."\n";
}
