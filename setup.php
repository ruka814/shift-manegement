<?php
// データベース初期化スクリプト
// ブラウザから http://localhost/TSW/setup.php にアクセスしてデータベースをセットアップ

$host = 'localhost';
$port = '8889'; // MAMPのデフォルトポート
$username = 'root';
$password = 'root'; // MAMPのデフォルトパスワード

try {
    // まず、データベースなしで接続
    $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>シフト管理システム データベースセットアップ</h2>";
    
    // データベース作成
    $pdo->exec("CREATE DATABASE IF NOT EXISTS shift_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "<p>✅ データベース 'shift_management' を作成しました。</p>";
    
    // データベースを選択
    $pdo->exec("USE shift_management");
    
    // テーブル作成
    $sql = file_get_contents('database/schema.sql');
    $statements = explode(';', $sql);
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement) && !preg_match('/^(CREATE DATABASE|USE)/i', $statement)) {
            $pdo->exec($statement);
        }
    }
    
    echo "<p>✅ テーブルを作成しました。</p>";
    echo "<p>✅ サンプルデータを挿入しました。</p>";
    echo "<p><strong>セットアップが完了しました！</strong></p>";
    echo "<p><a href='index.php' class='btn btn-primary'>アプリケーションを開く</a></p>";
    
    // セットアップ完了後、このファイルを削除する推奨メッセージ
    echo "<div style='background: #fff3cd; padding: 15px; margin: 20px 0; border: 1px solid #ffeaa7; border-radius: 5px;'>";
    echo "<strong>セキュリティのため、setup.phpファイルを削除することを推奨します。</strong>";
    echo "</div>";
    
} catch(PDOException $e) {
    echo "<h2>❌ エラーが発生しました</h2>";
    echo "<p>エラー: " . $e->getMessage() . "</p>";
    echo "<h3>解決方法:</h3>";
    echo "<ol>";
    echo "<li>MAMPが起動していることを確認してください</li>";
    echo "<li>MySQLサービスが動作していることを確認してください</li>";
    echo "<li>config/database.phpの設定を確認してください</li>";
    echo "</ol>";
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>データベースセットアップ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { padding: 20px; background-color: #f8f9fa; }
        .container { max-width: 600px; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .btn-primary { background-color: #0d6efd; border-color: #0d6efd; text-decoration: none; padding: 10px 20px; border-radius: 5px; color: white; display: inline-block; }
    </style>
</head>
<body>
    <div class="container">
        <!-- PHP output will be displayed here -->
    </div>
</body>
</html>
