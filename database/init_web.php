<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>データベース初期化 - シフト管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-8 offset-md-2">
                <div class="card">
                    <div class="card-header">
                        <h5>🔧 データベース初期化</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($_POST['action'] ?? '' === 'init'): ?>
                        
                        <?php
                        require_once '../config/database.php';
                        
                        try {
                            echo "<div class='alert alert-info'>データベース初期化を開始します...</div>";
                            
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
                            $results = [];
                            
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
                                        $shortStatement = substr($statement, 0, 100) . '...';
                                        $results[] = ['success' => true, 'statement' => $shortStatement];
                                    }
                                } catch (PDOException $e) {
                                    $errorCount++;
                                    $shortStatement = substr($statement, 0, 100) . '...';
                                    $results[] = ['success' => false, 'statement' => $shortStatement, 'error' => $e->getMessage()];
                                }
                            }
                            
                            echo "<div class='alert alert-success'>";
                            echo "<h6>初期化完了</h6>";
                            echo "成功: {$successCount}件<br>";
                            echo "エラー: {$errorCount}件";
                            echo "</div>";
                            
                            if ($errorCount > 0) {
                                echo "<div class='alert alert-warning'>";
                                echo "<h6>エラー詳細</h6>";
                                foreach ($results as $result) {
                                    if (!$result['success']) {
                                        echo "<small>✗ " . htmlspecialchars($result['statement']) . "<br>";
                                        echo "エラー: " . htmlspecialchars($result['error']) . "</small><br><br>";
                                    }
                                }
                                echo "</div>";
                            }
                            
                            // データ件数確認
                            echo "<div class='alert alert-info'>";
                            echo "<h6>データ確認</h6>";
                            
                            $tables = ['users', 'events', 'task_types', 'skills', 'availability', 'schedules'];
                            foreach ($tables as $table) {
                                try {
                                    $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
                                    $count = $stmt->fetch()['count'];
                                    echo "$table: <strong>{$count}件</strong><br>";
                                } catch (Exception $e) {
                                    echo "$table: <span class='text-danger'>エラー - " . htmlspecialchars($e->getMessage()) . "</span><br>";
                                }
                            }
                            echo "</div>";
                            
                        } catch (Exception $e) {
                            echo "<div class='alert alert-danger'>初期化エラー: " . htmlspecialchars($e->getMessage()) . "</div>";
                        }
                        ?>
                        
                        <div class="mt-3">
                            <a href="../pages/shift_assignment.php" class="btn btn-primary">シフト作成画面へ</a>
                            <a href="init_web.php" class="btn btn-secondary">再読み込み</a>
                        </div>
                        
                        <?php else: ?>
                        
                        <div class="alert alert-warning">
                            <h6>⚠️ 注意</h6>
                            この操作により、既存のデータは削除され、サンプルデータで初期化されます。
                        </div>
                        
                        <form method="POST">
                            <input type="hidden" name="action" value="init">
                            <button type="submit" class="btn btn-danger" onclick="return confirm('本当にデータベースを初期化しますか？既存データは削除されます。')">
                                🔧 データベースを初期化する
                            </button>
                        </form>
                        
                        <div class="mt-3">
                            <a href="../pages/shift_assignment.php" class="btn btn-outline-primary">キャンセル</a>
                        </div>
                        
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
