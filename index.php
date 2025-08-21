<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// メインダッシュボード
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>シフト管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="index.php">シフト管理システム</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="pages/users.php">スタッフ管理</a>
                <a class="nav-link" href="pages/events.php">イベント管理</a>
                <a class="nav-link" href="pages/availability.php">出勤入力</a>
                <a class="nav-link" href="pages/shift_assignment.php">シフト作成</a>
                <a class="nav-link" href="pages/saved_shifts.php">保存済みシフト</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-12">
                <h1>📋 シフト管理システム</h1>
                <p class="lead">宴会・婚礼スタッフのシフト管理を効率的に行います</p>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-md-2_4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title">👥 スタッフ管理</h5>
                        <p class="card-text">スタッフ情報・スキル登録</p>
                        <a href="pages/users.php" class="btn btn-primary">管理画面へ</a>
                    </div>
                </div>
            </div>
            <div class="col-md-2_4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title">🎉 イベント管理</h5>
                        <p class="card-text">宴会・婚礼情報の登録</p>
                        <a href="pages/events.php" class="btn btn-primary">管理画面へ</a>
                    </div>
                </div>
            </div>
            <div class="col-md-2_4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title">⏰ 出勤入力</h5>
                        <p class="card-text">出勤可能時間の入力</p>
                        <a href="pages/availability.php" class="btn btn-primary">入力画面へ</a>
                    </div>
                </div>
            </div>
            <div class="col-md-2_4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title">📊 シフト作成</h5>
                        <p class="card-text">自動割当・出力</p>
                        <a href="pages/shift_assignment.php" class="btn btn-success">作成画面へ</a>
                    </div>
                </div>
            </div>
            <div class="col-md-2_4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title">📋 保存済みシフト</h5>
                        <p class="card-text">シフト履歴・再利用</p>
                        <a href="pages/saved_shifts.php" class="btn btn-info">一覧表示へ</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
