<?php
require_once 'config/database.php';

echo "Attempting to modify availability table...\n";

try {
    // まず現在のテーブル構造を確認
    echo "Current table structure:\n";
    $stmt = $pdo->query('DESCRIBE availability');
    $columns = $stmt->fetchAll();
    
    foreach ($columns as $column) {
        if ($column['Field'] === 'event_id') {
            echo "event_id: Type=" . $column['Type'] . ", Null=" . $column['Null'] . ", Default=" . ($column['Default'] ?? 'NULL') . "\n";
        }
    }
    
    // テーブル構造を変更
    echo "\nModifying table structure...\n";
    $sql = "ALTER TABLE availability MODIFY COLUMN event_id INT NULL";
    $result = $pdo->exec($sql);
    
    echo "Table modification completed successfully.\n";
    
    // 変更後の構造を確認
    echo "\nUpdated table structure:\n";
    $stmt = $pdo->query('DESCRIBE availability');
    $columns = $stmt->fetchAll();
    
    foreach ($columns as $column) {
        if ($column['Field'] === 'event_id') {
            echo "event_id: Type=" . $column['Type'] . ", Null=" . $column['Null'] . ", Default=" . ($column['Default'] ?? 'NULL') . "\n";
        }
    }
    
} catch (PDOException $e) {
    echo 'Database Error: ' . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
?>
