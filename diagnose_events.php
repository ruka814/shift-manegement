<?php
require_once 'config/database.php';

// HTMLでの表示
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>イベントテーブル診断</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .section { margin-bottom: 30px; padding: 15px; border: 1px solid #ddd; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .error { color: red; }
        .success { color: green; }
    </style>
</head>
<body>
    <h1>🔍 イベントテーブル診断</h1>
    
    <div class="section">
        <h2>1. テーブル構造</h2>
        <?php
        try {
            $stmt = $pdo->query("DESCRIBE events");
            $columns = $stmt->fetchAll();
            
            echo "<table>";
            echo "<tr><th>フィールド名</th><th>データ型</th><th>NULL許可</th><th>キー</th><th>デフォルト値</th></tr>";
            foreach ($columns as $column) {
                echo "<tr>";
                echo "<td>{$column['Field']}</td>";
                echo "<td>{$column['Type']}</td>";
                echo "<td>{$column['Null']}</td>";
                echo "<td>{$column['Key']}</td>";
                echo "<td>" . ($column['Default'] ?? 'NULL') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } catch (Exception $e) {
            echo "<p class='error'>エラー: " . $e->getMessage() . "</p>";
        }
        ?>
    </div>
    
    <div class="section">
        <h2>2. 既存データ</h2>
        <?php
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM events");
            $count = $stmt->fetch();
            echo "<p>総イベント数: <strong>{$count['count']}</strong></p>";
            
            if ($count['count'] > 0) {
                $stmt = $pdo->query("SELECT * FROM events ORDER BY id DESC LIMIT 5");
                $events = $stmt->fetchAll();
                
                echo "<h3>最新の5つのイベント:</h3>";
                echo "<table>";
                echo "<tr><th>ID</th><th>開催日</th><th>開始時間</th><th>終了時間</th><th>種別</th><th>説明</th><th>総必要人数</th></tr>";
                foreach ($events as $event) {
                    echo "<tr>";
                    echo "<td>{$event['id']}</td>";
                    echo "<td>{$event['event_date']}</td>";
                    echo "<td>{$event['start_time']}</td>";
                    echo "<td>{$event['end_time']}</td>";
                    echo "<td>{$event['event_type']}</td>";
                    echo "<td>" . ($event['description'] ?? '') . "</td>";
                    echo "<td>" . ($event['total_staff_required'] ?? 'NULL') . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
            }
        } catch (Exception $e) {
            echo "<p class='error'>エラー: " . $e->getMessage() . "</p>";
        }
        ?>
    </div>
    
    <div class="section">
        <h2>3. テスト挿入</h2>
        <?php
        try {
            echo "<p>テストイベントの挿入を試行します...</p>";
            
            $stmt = $pdo->prepare("
                INSERT INTO events (event_date, start_time, end_time, event_type, needs, description, total_staff_required) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $testData = [
                '2024-12-31',
                '18:00',
                '22:00',
                'テスト',
                '{}',
                'テストイベント',
                5
            ];
            
            $result = $stmt->execute($testData);
            
            if ($result) {
                $newId = $pdo->lastInsertId();
                echo "<p class='success'>✅ テストイベントが正常に挿入されました！ (ID: {$newId})</p>";
                
                // すぐに削除
                $deleteStmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
                $deleteResult = $deleteStmt->execute([$newId]);
                
                if ($deleteResult) {
                    echo "<p class='success'>✅ テストイベントを削除しました。</p>";
                }
            } else {
                echo "<p class='error'>❌ テストイベントの挿入に失敗しました。</p>";
            }
            
        } catch (Exception $e) {
            echo "<p class='error'>❌ テスト挿入エラー: " . $e->getMessage() . "</p>";
        }
        ?>
    </div>
    
    <div class="section">
        <h2>4. PHP情報</h2>
        <p>PHP Version: <?= PHP_VERSION ?></p>
        <p>PDO Available: <?= extension_loaded('pdo') ? 'Yes' : 'No' ?></p>
        <p>PDO MySQL Available: <?= extension_loaded('pdo_mysql') ? 'Yes' : 'No' ?></p>
        
        <h3>POST データ（もしあれば）:</h3>
        <?php if (!empty($_POST)): ?>
            <pre><?= htmlspecialchars(print_r($_POST, true)) ?></pre>
        <?php else: ?>
            <p>POSTデータなし</p>
        <?php endif; ?>
    </div>
    
    <div class="section">
        <h2>5. アクションテスト</h2>
        <form method="POST">
            <input type="hidden" name="test_action" value="test_event">
            <button type="submit">テストイベント追加を実行</button>
        </form>
        
        <?php
        if (isset($_POST['test_action']) && $_POST['test_action'] === 'test_event') {
            echo "<h3>フォーム送信テスト結果:</h3>";
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO events (event_date, start_time, end_time, event_type, needs, description, total_staff_required) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                $testData = [
                    '2024-12-25',
                    '19:00',
                    '23:00',
                    'フォームテスト',
                    '{}',
                    'フォーム経由テストイベント',
                    10
                ];
                
                $result = $stmt->execute($testData);
                
                if ($result) {
                    $newId = $pdo->lastInsertId();
                    echo "<p class='success'>✅ フォーム経由でのイベント追加に成功！ (ID: {$newId})</p>";
                    
                    // 3秒後に削除
                    sleep(1);
                    $deleteStmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
                    $deleteResult = $deleteStmt->execute([$newId]);
                    
                    if ($deleteResult) {
                        echo "<p class='success'>✅ テストイベントを削除しました。</p>";
                    }
                } else {
                    echo "<p class='error'>❌ フォーム経由でのイベント追加に失敗。</p>";
                }
                
            } catch (Exception $e) {
                echo "<p class='error'>❌ フォームテストエラー: " . $e->getMessage() . "</p>";
            }
        }
        ?>
    </div>
</body>
</html>
