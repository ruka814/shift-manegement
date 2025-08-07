<?php
// テーブル削除スクリプト
// ブラウザから http://localhost/TSW/drop_tables.php にアクセスしてテーブルを削除

// 現在のdatabase.phpの設定を読み込み
require_once 'config/database.php';

// 削除確認
$confirm = $_GET['confirm'] ?? '';

if ($confirm !== 'yes') {
    ?>
    <!DOCTYPE html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>テーブル削除確認</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { padding: 20px; background-color: #f8f9fa; }
            .container { max-width: 600px; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
            .btn { text-decoration: none; padding: 10px 20px; border-radius: 5px; display: inline-block; margin: 5px; }
            .btn-danger { background-color: #dc3545; color: white; }
            .btn-secondary { background-color: #6c757d; color: white; }
        </style>
    </head>
    <body>
        <div class="container">
            <h2>⚠️ テーブル削除確認</h2>
            <div class="alert alert-danger">
                <h4>危険な操作です！</h4>
                <p>この操作により、以下のテーブルとすべてのデータが<strong>完全に削除</strong>されます：</p>
                <ul>
                    <li>assignments（割当結果）</li>
                    <li>availability（出勤可能情報）</li>
                    <li>skills（スキル情報）</li>
                    <li>events（イベント情報）</li>
                    <li>task_types（タスク種別）</li>
                    <li>users（ユーザー情報）</li>
                </ul>
                <p><strong>この操作は取り消すことができません。</strong></p>
            </div>
            
            <h3>実行理由</h3>
            <ul>
                <li>データベースを完全にリセットしたい</li>
                <li>テーブル構造を変更してからre-setupしたい</li>
                <li>開発環境をクリーンアップしたい</li>
            </ul>
            
            <div class="mt-4">
                <a href="?confirm=yes" class="btn btn-danger" onclick="return confirm('本当にすべてのテーブルを削除しますか？この操作は取り消せません。')">
                    🗑️ すべてのテーブルを削除する
                </a>
                <a href="index.php" class="btn btn-secondary">
                    ← 戻る
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 削除実行
try {
    echo "<h2>🗑️ テーブル削除処理</h2>";
    
    // 外部キー制約チェックを無効化
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    echo "<p>📝 外部キー制約チェックを無効化しました。</p>";
    
    // 手動でテーブルを削除（依存関係の逆順）
    $tablesToDrop = [
        'assignments',
        'availability', 
        'skills',
        'events',
        'task_types',
        'users'
    ];
    
    $deletedTables = [];
    
    foreach ($tablesToDrop as $table) {
        try {
            $pdo->exec("DROP TABLE IF EXISTS `$table`");
            $deletedTables[] = $table;
            echo "<p>✅ テーブル '$table' を削除しました。</p>";
        } catch(PDOException $e) {
            echo "<p>⚠️ テーブル '$table' の削除でエラー: " . $e->getMessage() . "</p>";
        }
    }
    
    // 外部キー制約チェックを再有効化
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "<p>📝 外部キー制約チェックを再有効化しました。</p>";
    
    echo "<div class='alert alert-success'>";
    echo "<h4>✅ 削除完了</h4>";
    if (!empty($deletedTables)) {
        echo "<p>以下のテーブルを削除しました：</p>";
        echo "<ul>";
        foreach ($deletedTables as $table) {
            echo "<li>$table</li>";
        }
        echo "</ul>";
    } else {
        echo "<p>削除対象のテーブルはありませんでした。</p>";
    }
    echo "</div>";
    
    echo "<div class='alert alert-info'>";
    echo "<h4>次のステップ</h4>";
    echo "<p>テーブルを再作成する場合は、以下のボタンをクリックしてください：</p>";
    echo "<a href='setup.php' class='btn btn-primary'>🔧 データベースセットアップ</a>";
    echo "</div>";
    
    // このファイルを削除する推奨メッセージ
    echo "<div style='background: #fff3cd; padding: 15px; margin: 20px 0; border: 1px solid #ffeaa7; border-radius: 5px;'>";
    echo "<strong>セキュリティのため、drop_tables.phpファイルを削除することを推奨します。</strong>";
    echo "</div>";
    
} catch(PDOException $e) {
    echo "<div class='alert alert-danger'>";
    echo "<h4>❌ エラーが発生しました</h4>";
    echo "<p>エラー: " . $e->getMessage() . "</p>";
    echo "<h5>解決方法:</h5>";
    echo "<ul>";
    echo "<li>データベース接続を確認してください</li>";
    echo "<li>権限があることを確認してください</li>";
    echo "</ul>";
    echo "</div>";
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>テーブル削除結果</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { padding: 20px; background-color: #f8f9fa; }
        .container { max-width: 700px; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .btn { text-decoration: none; padding: 10px 20px; border-radius: 5px; color: white; display: inline-block; margin: 5px; }
        .btn-primary { background-color: #0d6efd; }
        .alert { padding: 15px; margin: 15px 0; border-radius: 5px; }
        .alert-success { background: #d1edff; border: 1px solid #0084ff; }
        .alert-danger { background: #ffe6e6; border: 1px solid #ff0000; }
        .alert-info { background: #e7f3ff; border: 1px solid #0066cc; }
    </style>
</head>
<body>
    <div class="container">
        <!-- PHP output will be displayed here -->
    </div>
</body>
</html>
