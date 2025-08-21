<?php
// データベース初期化スクリプト
require_once '../config/database.php';

try {
    echo "データベース初期化を開始します...\n";
    
    // schema.sqlファイルを読み込んで実行
    $sqlFile = __DIR__ . '/schema.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception('schema.sqlファイルが見つかりません');
    }
    
    $sql = file_get_contents($sqlFile);
    
    // SQLを文ごとに分割
    $statements = explode(';', $sql);
    
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (empty($statement) || $statement === '--' || strpos($statement, '--') === 0) {
            continue;
        }
        
        try {
            $pdo->exec($statement);
            $successCount++;
            
            // CREATE文やINSERT文の場合は詳細を表示
            if (preg_match('/^(CREATE|INSERT|DROP|USE)/i', $statement)) {
                $shortStatement = substr($statement, 0, 80) . '...';
                echo "✓ 実行成功: " . $shortStatement . "\n";
            }
        } catch (PDOException $e) {
            $errorCount++;
            $shortStatement = substr($statement, 0, 80) . '...';
            echo "✗ 実行エラー: " . $shortStatement . "\n";
            echo "  エラー内容: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n=== 初期化完了 ===\n";
    echo "成功: {$successCount}件\n";
    echo "エラー: {$errorCount}件\n";
    
    // データ件数確認
    echo "\n=== データ確認 ===\n";
    
    $tables = ['users', 'events', 'task_types', 'skills', 'availability', 'schedules'];
    foreach ($tables as $table) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
            $count = $stmt->fetch()['count'];
            echo "$table: {$count}件\n";
        } catch (Exception $e) {
            echo "$table: エラー - " . $e->getMessage() . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "初期化エラー: " . $e->getMessage() . "\n";
}
?>
