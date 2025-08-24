<?php
require_once '../config/database.php';

echo "<h2>🔧 データベース最適化 - 重複防止インデックス追加</h2>";

try {
    // 現在のインデックスを確認
    $stmt = $pdo->query("SHOW INDEX FROM availability");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>現在のインデックス:</h3>";
    if ($indexes) {
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>インデックス名</th><th>カラム名</th><th>ユニーク</th></tr>";
        foreach ($indexes as $index) {
            $unique = $index['Non_unique'] == 0 ? 'Yes' : 'No';
            echo "<tr><td>{$index['Key_name']}</td><td>{$index['Column_name']}</td><td>{$unique}</td></tr>";
        }
        echo "</table>";
    }
    
    // 複合ユニークインデックスが存在するかチェック
    $hasUniqueIndex = false;
    foreach ($indexes as $index) {
        if ($index['Key_name'] === 'unique_user_date_general' || 
            ($index['Non_unique'] == 0 && $index['Column_name'] === 'user_id')) {
            $hasUniqueIndex = true;
            break;
        }
    }
    
    if (!$hasUniqueIndex) {
        echo "<h3>🔧 複合ユニークインデックスを追加中...</h3>";
        
        // 一般出勤情報の重複を防ぐユニークインデックスを追加
        $pdo->exec("
            ALTER TABLE availability 
            ADD CONSTRAINT unique_user_date_general 
            UNIQUE (user_id, work_date, event_id)
        ");
        
        echo "<p style='color: green;'>✅ 複合ユニークインデックスを追加しました！</p>";
        echo "<p><strong>効果:</strong> 同じユーザー、同じ日付、同じイベントの組み合わせで重複レコードが作成されなくなります。</p>";
    } else {
        echo "<p style='color: blue;'>ℹ️ 複合ユニークインデックスは既に存在します。</p>";
    }
    
    // パフォーマンス向上のための追加インデックス
    echo "<h3>🚀 パフォーマンス向上インデックス</h3>";
    
    $performanceIndexes = [
        'idx_work_date' => 'work_date',
        'idx_user_id' => 'user_id',
        'idx_updated_at' => 'updated_at'
    ];
    
    foreach ($performanceIndexes as $indexName => $column) {
        $exists = false;
        foreach ($indexes as $index) {
            if ($index['Key_name'] === $indexName) {
                $exists = true;
                break;
            }
        }
        
        if (!$exists) {
            try {
                $pdo->exec("CREATE INDEX {$indexName} ON availability ({$column})");
                echo "<p style='color: green;'>✅ インデックス {$indexName} を追加しました</p>";
            } catch (PDOException $e) {
                echo "<p style='color: orange;'>⚠️ インデックス {$indexName} の追加をスキップ: " . $e->getMessage() . "</p>";
            }
        } else {
            echo "<p style='color: blue;'>ℹ️ インデックス {$indexName} は既に存在します</p>";
        }
    }
    
    // 更新後のインデックス状況を表示
    echo "<h3>更新後のインデックス:</h3>";
    $stmt = $pdo->query("SHOW INDEX FROM availability");
    $updatedIndexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>インデックス名</th><th>カラム名</th><th>ユニーク</th><th>カーディナリティ</th></tr>";
    foreach ($updatedIndexes as $index) {
        $unique = $index['Non_unique'] == 0 ? 'Yes' : 'No';
        echo "<tr>";
        echo "<td>{$index['Key_name']}</td>";
        echo "<td>{$index['Column_name']}</td>";
        echo "<td>{$unique}</td>";
        echo "<td>{$index['Cardinality']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>エラー: " . $e->getMessage() . "</p>";
    echo "<p><small>注意: 既存データに重複がある場合、ユニークインデックスの追加に失敗することがあります。<br>";
    echo "その場合は、まず重複データをクリーンアップしてから再実行してください。</small></p>";
}

echo "<hr>";
echo "<p><a href='check_duplicates.php'>重複データチェック</a></p>";
echo "<p><a href='availability.php'>出勤入力ページに戻る</a></p>";
?>
