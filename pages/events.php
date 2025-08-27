<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

// セッション開始
session_start();

// CSRFトークン生成
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// イベント管理画面
$message = '';

// セッションからメッセージを取得
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

// イベント追加処理
if ($_POST['action'] ?? '' === 'add_event') {
    try {
        // リクエストID生成（タイムスタンプ + ランダム値）
        $requestId = $_POST['request_id'] ?? (time() . '_' . bin2hex(random_bytes(8)));
        
        // より詳細なデバッグログ
        error_log("=== イベント追加処理開始 (RequestID: {$requestId}) ===");
        error_log("Request Method: " . $_SERVER['REQUEST_METHOD']);
        error_log("User Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'));
        error_log("Referer: " . ($_SERVER['HTTP_REFERER'] ?? 'None'));
        error_log("Request Time: " . date('Y-m-d H:i:s'));
        error_log("Session ID: " . session_id());
        error_log("POST データ: " . json_encode($_POST));
        
        // 処理済みリクエストチェック
        $processedKey = 'processed_request_' . $requestId;
        if (isset($_SESSION[$processedKey])) {
            error_log("events.php: 処理済みリクエストを検出 - RequestID: {$requestId}");
            throw new Exception('このリクエストは既に処理済みです。');
        }
        
        // リクエストを処理中としてマーク
        $_SESSION[$processedKey] = time();
        
        // 古い処理済みリクエストのクリーンアップ（5分以上前のものを削除）
        $cleanupTime = time() - 300; // 5分前
        foreach (array_keys($_SESSION) as $key) {
            if (strpos($key, 'processed_request_') === 0 && $_SESSION[$key] < $cleanupTime) {
                unset($_SESSION[$key]);
                error_log("events.php: 古い処理済みリクエストをクリーンアップ: {$key}");
            }
        }
        
        // CSRFトークンの検証
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            error_log("CSRFトークンエラー - 送信: " . ($_POST['csrf_token'] ?? 'なし') . ", セッション: " . ($_SESSION['csrf_token'] ?? 'なし'));
            throw new Exception('不正なリクエストです。ページを再読み込みして再度お試しください。');
        }
        
        // 二重送信防止：同じトークンでの連続送信をチェック
        $submitKey = 'last_submit_token_' . $_POST['csrf_token'];
        if (isset($_SESSION[$submitKey])) {
            error_log("二重送信検出: submitKey = " . $submitKey);
            error_log("既存セッションキー: " . print_r(array_keys($_SESSION), true));
            throw new Exception('既に処理済みです。重複した送信を防止しました。');
        }
        
        // 送信トークンを記録
        $_SESSION[$submitKey] = time(); // タイムスタンプも記録
        error_log("送信トークンを記録: " . $submitKey . " = " . $_SESSION[$submitKey]);
        
        // デバッグ用ログ
        error_log("events.php: イベント追加処理開始（新規作成）");
        error_log("events.php: POSTデータ: " . json_encode($_POST));
        
        // event_idが設定されていないことを確認（新規追加の場合）
        if (!empty($_POST['event_id'])) {
            error_log("events.php: 警告 - 新規追加なのにevent_idが設定されています: " . $_POST['event_id']);
        }
        
        // バリデーション
        if (empty($_POST['event_date'])) {
            throw new Exception('開催日は必須項目です。');
        }
        if (empty($_POST['start_hour']) || empty($_POST['start_minute'])) {
            throw new Exception('開始時間は必須項目です。');
        }
        if (empty($_POST['end_hour']) || empty($_POST['end_minute'])) {
            throw new Exception('終了時間は必須項目です。');
        }
        if (empty($_POST['event_type'])) {
            throw new Exception('イベント種別は必須項目です。');
        }
        if (empty($_POST['total_staff_required']) || $_POST['total_staff_required'] <= 0) {
            throw new Exception('総必要人数は必須項目です。1以上の数値を入力してください。');
        }
        
        $needs = [];
        if (isset($_POST['needs']) && is_array($_POST['needs'])) {
            foreach ($_POST['needs'] as $role => $count) {
                if (!empty($count)) {
                    $needs[$role] = $count;
                }
            }
        }
        
        // 時間と分を結合
        $start_time = sprintf('%02d:%02d', $_POST['start_hour'], $_POST['start_minute']);
        $end_time = sprintf('%02d:%02d', $_POST['end_hour'], $_POST['end_minute']);
        
                error_log("events.php: 準備されたデータ - 日付: {$_POST['event_date']}, 開始: {$start_time}, 終了: {$end_time}, 種別: {$_POST['event_type']}");
        
        // 重複チェック: 同じ日時・種別のイベントが既に存在しないか確認
        $duplicateCheckStmt = $pdo->prepare("
            SELECT id FROM events 
            WHERE event_date = ? AND start_time = ? AND end_time = ? AND event_type = ?
            LIMIT 1
        ");
        $duplicateCheckStmt->execute([
            $_POST['event_date'],
            $start_time,
            $end_time,
            $_POST['event_type']
        ]);
        
        if ($duplicateCheckStmt->fetch()) {
            error_log("events.php: 重複イベントを検出 - 追加を中止");
            throw new Exception('同じ日時・種別のイベントが既に存在します。重複を防止しました。');
        }
        
        error_log("events.php: 重複チェック完了 - 問題なし");
        
        // トランザクション開始
        $pdo->beginTransaction();
        error_log("events.php: トランザクション開始");
        
        try {
            // 再度重複チェック（トランザクション内で排他的に）
            $duplicateCheckStmt = $pdo->prepare("
                SELECT id FROM events 
                WHERE event_date = ? AND start_time = ? AND end_time = ? AND event_type = ?
                FOR UPDATE
            ");
            $duplicateCheckStmt->execute([
                $_POST['event_date'],
                $start_time,
                $end_time,
                $_POST['event_type']
            ]);
            
            if ($duplicateCheckStmt->fetch()) {
                $pdo->rollBack();
                error_log("events.php: トランザクション内で重複を検出 - ロールバック");
                throw new Exception('同じ日時・種別のイベントが既に存在します。重複を防止しました。');
            }
        
        // total_staff_requiredカラムの存在確認
        $hasTotal = false;
        try {
            $checkStmt = $pdo->query("SHOW COLUMNS FROM events LIKE 'total_staff_required'");
            $hasTotal = $checkStmt->rowCount() > 0;
            error_log("events.php: total_staff_requiredカラム存在チェック: " . ($hasTotal ? 'あり' : 'なし'));
        } catch (Exception $e) {
            error_log("events.php: カラム存在チェックエラー: " . $e->getMessage());
        }
        
        if ($hasTotal) {
            // total_staff_requiredカラムがある場合
            $stmt = $pdo->prepare("
                INSERT INTO events (event_date, start_time, end_time, event_type, venue, needs, description, total_staff_required, course_runner_count, buffet_runner_count, light_count, parents_count) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $totalStaffRequired = (int)$_POST['total_staff_required'];
            $courseRunnerCount = (int)($_POST['course_runner_count'] ?? 0);
            $buffetRunnerCount = (int)($_POST['buffet_runner_count'] ?? 0);
            $lightCount = (int)($_POST['light_count'] ?? 0);
            $parentsCount = (int)($_POST['parents_count'] ?? 0);
            
            error_log("events.php: 総必要人数の値: " . $totalStaffRequired);
            error_log("events.php: コースランナー数: " . $courseRunnerCount);
            error_log("events.php: ビュッフェランナー数: " . $buffetRunnerCount);
            error_log("events.php: ライト数: " . $lightCount);
            error_log("events.php: 両親数: " . $parentsCount);
            
            $result = $stmt->execute([
                $_POST['event_date'],
                $start_time,
                $end_time,
                $_POST['event_type'],
                $_POST['venue'] ?? '',
                json_encode($needs),
                $_POST['description'] ?? '',
                $totalStaffRequired,
                $courseRunnerCount,
                $buffetRunnerCount,
                $lightCount,
                $parentsCount
            ]);
            
            if ($result) {
                $newEventId = $pdo->lastInsertId();
                $pdo->commit();
                error_log("events.php: トランザクションコミット完了 - ID: {$newEventId}");
                
                // 成功時に送信トークンをクリア
                unset($_SESSION[$submitKey]);
                
                // 新しいCSRFトークンを生成
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                
                // セッションにメッセージを保存してリダイレクト
                $_SESSION['message'] = showAlert('success', "イベントを追加しました。（ID: {$newEventId}）");
                header('Location: events.php');
                exit;
            } else {
                $pdo->rollBack();
                error_log("events.php: データ挿入失敗 - ロールバック");
                throw new Exception('イベントの追加に失敗しました。');
            }
        } else {
            $pdo->rollBack();
            // total_staff_requiredカラムがない場合はエラー
            throw new Exception('データベースに総必要人数フィールドがありません。システム管理者にお問い合わせください。');
        }
        
        } catch (Exception $dbException) {
            $pdo->rollBack();
            error_log("events.php: データベース例外でロールバック: " . $dbException->getMessage());
            throw $dbException;
        }
        
    } catch(Exception $e) {
        error_log("events.php: イベント追加エラー: " . $e->getMessage());
        $message = showAlert('danger', 'エラーが発生しました: ' . $e->getMessage());
    } catch(PDOException $e) {
        error_log("events.php: データベースエラー: " . $e->getMessage());
        $message = showAlert('danger', 'データベースエラーが発生しました: ' . $e->getMessage());
    }
}

// イベント編集処理
if ($_POST['action'] ?? '' === 'edit_event') {
    try {
        // CSRFトークンの検証
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception('不正なリクエストです。ページを再読み込みして再度お試しください。');
        }
        
        // デバッグ用ログ
        error_log("events.php: イベント編集処理開始");
        error_log("events.php: 編集POSTデータ: " . json_encode($_POST));
        error_log("events.php: event_id: " . ($_POST['event_id'] ?? 'なし'));
        
        // event_idの存在確認
        if (empty($_POST['event_id'])) {
            throw new Exception('編集するイベントIDが指定されていません。');
        }
        
        $eventId = (int)$_POST['event_id'];
        if ($eventId <= 0) {
            throw new Exception('無効なイベントIDです。');
        }
        
        // 編集対象のイベントが存在するか確認
        $checkStmt = $pdo->prepare("SELECT id FROM events WHERE id = ?");
        $checkStmt->execute([$eventId]);
        if (!$checkStmt->fetch()) {
            throw new Exception('指定されたイベントが見つかりません。');
        }
        
        $needs = [];
        if (isset($_POST['needs']) && is_array($_POST['needs'])) {
            foreach ($_POST['needs'] as $role => $count) {
                if (!empty($count)) {
                    $needs[$role] = $count;
                }
            }
        }
        
        // 時間と分を結合
        $start_time = sprintf('%02d:%02d', $_POST['start_hour'], $_POST['start_minute']);
        $end_time = sprintf('%02d:%02d', $_POST['end_hour'], $_POST['end_minute']);
        
        // total_staff_requiredカラムの存在確認
        $hasTotal = false;
        try {
            $checkStmt = $pdo->query("SHOW COLUMNS FROM events LIKE 'total_staff_required'");
            $hasTotal = $checkStmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log("events.php: 編集時カラム存在チェックエラー: " . $e->getMessage());
        }
        
        if ($hasTotal) {
            $totalStaffRequired = (int)$_POST['total_staff_required'];
            $courseRunnerCount = (int)($_POST['course_runner_count'] ?? 0);
            $buffetRunnerCount = (int)($_POST['buffet_runner_count'] ?? 0);
            $lightCount = (int)($_POST['light_count'] ?? 0);
            $parentsCount = (int)($_POST['parents_count'] ?? 0);
            
            error_log("events.php: UPDATE実行開始 - event_id: " . $_POST['event_id']);
            
            $stmt = $pdo->prepare("
                UPDATE events 
                SET event_date = ?, start_time = ?, end_time = ?, event_type = ?, venue = ?, needs = ?, description = ?, total_staff_required = ?, course_runner_count = ?, buffet_runner_count = ?, light_count = ?, parents_count = ?
                WHERE id = ?
            ");
            $result = $stmt->execute([
                $_POST['event_date'],
                $start_time,
                $end_time,
                $_POST['event_type'],
                $_POST['venue'] ?? '',
                json_encode($needs),
                $_POST['description'],
                $totalStaffRequired,
                $courseRunnerCount,
                $buffetRunnerCount,
                $lightCount,
                $parentsCount,
                $eventId
            ]);
            
            error_log("events.php: UPDATE実行結果: " . ($result ? '成功' : '失敗'));
            error_log("events.php: 影響を受けた行数: " . $stmt->rowCount());
            
            if (!$result) {
                throw new Exception('イベントの更新に失敗しました。');
            }
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('指定されたイベントが見つからないか、データに変更がありませんでした。');
            }
        } else {
            // total_staff_requiredカラムがない場合はエラー
            throw new Exception('データベースに総必要人数フィールドがありません。システム管理者にお問い合わせください。');
        }
        
        $message = showAlert('success', 'イベントを更新しました。');
        
        // 新しいCSRFトークンを生成
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        
        // セッションにメッセージを保存してリダイレクト
        $_SESSION['message'] = $message;
        header('Location: events.php');
        exit;
    } catch(PDOException $e) {
        $message = showAlert('danger', 'エラーが発生しました: ' . $e->getMessage());
    }
}

// イベント削除処理
if ($_POST['action'] ?? '' === 'delete_event') {
    try {
        // CSRFトークンの検証
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception('不正なリクエストです。ページを再読み込みして再度お試しください。');
        }
        
        $stmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
        $stmt->execute([$_POST['event_id']]);
        $message = showAlert('success', 'イベントを削除しました。');
        
        // 新しいCSRFトークンを生成
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        
        // セッションにメッセージを保存してリダイレクト
        $_SESSION['message'] = $message;
        header('Location: events.php');
        exit;
    } catch(Exception $e) {
        $message = showAlert('danger', 'エラーが発生しました: ' . $e->getMessage());
    } catch(PDOException $e) {
        $message = showAlert('danger', 'データベースエラー: ' . $e->getMessage());
    }
}

// イベント一覧取得
try {
    error_log("events.php: イベント一覧取得開始");
    
    // total_staff_requiredカラムの存在をチェック
    $hasTotal = false;
    try {
        $checkStmt = $pdo->query("SELECT total_staff_required FROM events LIMIT 1");
        $hasTotal = true;
        error_log("events.php: total_staff_requiredカラムが存在します");
    } catch(PDOException $e) {
        error_log("events.php: total_staff_requiredカラムが存在しません: " . $e->getMessage());
    }
    
    // 一回だけクエリを実行（DISTINCTで重複除去）
    try {
        // まず全カラムを含むクエリを試行
        $stmt = $pdo->query("SELECT DISTINCT id, event_date, start_time, end_time, event_type, venue, needs, description, total_staff_required, course_runner_count, buffet_runner_count, light_count, parents_count FROM events ORDER BY event_date, start_time");
    } catch(PDOException $e) {
        error_log("events.php: 拡張カラムでのクエリ失敗、基本カラムで再試行: " . $e->getMessage());
        if ($hasTotal) {
            $stmt = $pdo->query("SELECT DISTINCT id, event_date, start_time, end_time, event_type, venue, needs, description, total_staff_required FROM events ORDER BY event_date, start_time");
        } else {
            $stmt = $pdo->query("SELECT DISTINCT id, event_date, start_time, end_time, event_type, venue, needs, description FROM events ORDER BY event_date, start_time");
        }
    }
    
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // カラムが存在しない場合はデフォルト値で初期化
    foreach ($events as &$event) {
        if (!isset($event['total_staff_required'])) {
            $event['total_staff_required'] = null;
        }
        if (!isset($event['course_runner_count'])) {
            $event['course_runner_count'] = 0;
        }
        if (!isset($event['buffet_runner_count'])) {
            $event['buffet_runner_count'] = 0;
        }
        if (!isset($event['light_count'])) {
            $event['light_count'] = 0;
        }
        if (!isset($event['parents_count'])) {
            $event['parents_count'] = 0;
        }
        if (!isset($event['venue'])) {
            $event['venue'] = '';
        }
    }
    unset($event); // 参照を解除
    
    error_log("events.php: 重複削除前 " . count($events) . "件のイベント");
    
    // 重複削除（念のため）
    $uniqueEvents = [];
    $seenIds = [];
    foreach ($events as $event) {
        if (!in_array($event['id'], $seenIds)) {
            $uniqueEvents[] = $event;
            $seenIds[] = $event['id'];
        }
    }
    
    error_log("events.php: 重複削除後 " . count($uniqueEvents) . "件のイベント");
    if (count($events) !== count($uniqueEvents)) {
        error_log("events.php: " . (count($events) - count($uniqueEvents)) . "件の重複を削除しました");
    }
    $events = $uniqueEvents;
    
    error_log("events.php: " . count($events) . "件のイベントを取得しました");
    
    // デバッグ用：イベントIDをログ出力
    $eventIds = array_map(function($event) { return $event['id']; }, $events);
    error_log("events.php: イベントID一覧: " . implode(', ', $eventIds));
    
} catch(PDOException $e) {
    error_log("events.php: イベント取得エラー: " . $e->getMessage());
    $events = [];
    $message = showAlert('warning', 'イベントの取得中にエラーが発生しました: ' . $e->getMessage());
}

// タスクタイプ取得
$stmt = $pdo->query("SELECT * FROM task_types ORDER BY name COLLATE utf8mb4_unicode_ci");
$taskTypes = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>イベント管理 - シフト管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="../index.php">シフト管理システム</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="users.php">スタッフ管理</a>
                <a class="nav-link active" href="events.php">イベント管理</a>
                <a class="nav-link" href="availability.php">出勤入力</a>
                <a class="nav-link" href="shift_assignment.php">シフト作成</a>
                <a class="nav-link" href="saved_shifts.php">保存済みシフト</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <?= $message ?>
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>🎉 イベント管理</h1>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addEventModal">
                新規イベント追加
            </button>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>開催日</th>
                                <th>時間</th>
                                <th>イベント種別</th>
                                <th>会場</th>
                                <th>役割別必要人数</th>
                                <th>総必要人数</th>
                                <th>説明</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $event): ?>
                            <tr>
                                <td><?= formatDate($event['event_date']) ?></td>
                                <td>
                                    <?= formatTime($event['start_time']) ?> - 
                                    <?= formatTime($event['end_time']) ?>
                                </td>
                                <td>
                                    <span class="badge bg-info event-type-badge">
                                        <?= h($event['event_type']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= getVenueBadge($event['venue']) ?>
                                </td>
                                <td>
                                    <?php
                                    $needs = parseNeeds($event['needs']);
                                    foreach ($needs as $role => $count):
                                    ?>
                                        <small class="d-block">
                                            <?= h($role) ?>: <?= $count['display'] ?>
                                        </small>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <?php if (isset($event['total_staff_required']) && !empty($event['total_staff_required'])): ?>
                                        <span class="badge bg-primary fs-6">
                                            <?= $event['total_staff_required'] ?>人
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">未設定</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($event['description']) ?></td>
                                <td>
                                    <div class="btn-group">
                                        <button class="btn btn-sm btn-outline-warning" 
                                                onclick="editEvent(<?= $event['id'] ?>)">
                                            編集
                                        </button>
                                        <a href="availability.php?date=<?= $event['event_date'] ?>" 
                                           class="btn btn-sm btn-outline-primary">
                                            出勤入力
                                        </a>
                                        <a href="shift_assignment.php?event_id=<?= $event['id'] ?>" 
                                           class="btn btn-sm btn-outline-success">
                                            シフト作成
                                        </a>
                                        <button class="btn btn-sm btn-outline-danger" 
                                                onclick="deleteEvent(<?= $event['id'] ?>, '<?= h($event['event_type']) ?>')">
                                            削除
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- 新規イベント追加モーダル -->
    <div class="modal fade" id="addEventModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" id="addEventForm">
                    <div class="modal-header">
                        <h5 class="modal-title">新規イベント追加</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_event">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="request_id" value="<?= time() . '_' . bin2hex(random_bytes(8)) ?>">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">開催日</label>
                                    <input type="date" class="form-control" name="event_date" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label">開始時間</label>
                                    <div class="row g-1 time-row">
                                        <div class="col-6">
                                            <select class="form-select form-select-sm time-part-select" name="start_hour" required>
                                                <?= generateHourOptions() ?>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <select class="form-select form-select-sm time-part-select" name="start_minute" required>
                                                <?= generateMinuteOptions() ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label">終了時間</label>
                                    <div class="row g-1 time-row">
                                        <div class="col-6">
                                            <select class="form-select form-select-sm time-part-select" name="end_hour" required>
                                                <?= generateHourOptions() ?>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <select class="form-select form-select-sm time-part-select" name="end_minute" required>
                                                <?= generateMinuteOptions() ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">イベント種別</label>
                                    <select class="form-select" name="event_type" id="addEventType" onchange="toggleRunnerFields('add')" required>
                                        <option value="">選択してください</option>
                                        <option value="ビュッフェ">ビュッフェ</option>
                                        <option value="コース">コース</option>
                                        <option value="会議">会議</option>
                                        <option value="婚礼">婚礼</option>
                                        <option value="その他">その他</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">総必要人数 <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" name="total_staff_required" 
                                           min="1" max="100" placeholder="例: 10" value="" required>
                                    <small class="form-text text-muted">全体で必要な人数（必須）</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">会場</label>
                                    <select class="form-select venue-select" name="venue">
                                        <option value="">選択してください</option>
                                        <option value="ローズ全">ローズ全</option>
                                        <option value="ローズI">ローズI</option>
                                        <option value="ローズII">ローズII</option>
                                        <option value="クリスタル">クリスタル</option>
                                        <option value="しらさぎ">しらさぎ</option>
                                        <option value="くじゃく">くじゃく</option>
                                        <option value="ちどり">ちどり</option>
                                        <option value="グラン">グラン</option>
                                    </select>
                                    <small class="form-text text-muted">会場を選択してください（任意）</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <!-- コースランナー数フィールド -->
                                <div class="mb-3" id="addCourseRunnerField" style="display: none;">
                                    <label class="form-label">
                                        <i class="fas fa-running text-primary"></i> コースランナー数
                                    </label>
                                    <input type="number" class="form-control" name="course_runner_count" 
                                           min="0" max="50" placeholder="例: 3" value="0">
                                    <small class="form-text text-muted">コースランナーの必要人数</small>
                                </div>
                                <!-- ビュッフェランナー数フィールド -->
                                <div class="mb-3" id="addBuffetRunnerField" style="display: none;">
                                    <label class="form-label">
                                        <i class="fas fa-utensils text-warning"></i> ビュッフェランナー数
                                    </label>
                                    <input type="number" class="form-control" name="buffet_runner_count" 
                                           min="0" max="50" placeholder="例: 2" value="0">
                                    <small class="form-text text-muted">ビュッフェランナーの必要人数</small>
                                </div>
                                <!-- ライト数フィールド（婚礼用） -->
                                <div class="mb-3" id="addLightField" style="display: none;">
                                    <label class="form-label">
                                        <i class="fas fa-lightbulb text-info"></i> ライト数
                                    </label>
                                    <input type="number" class="form-control" name="light_count" 
                                           min="0" max="20" placeholder="例: 2" value="0">
                                    <small class="form-text text-muted">ライトの必要人数</small>
                                </div>
                                <!-- 両親数フィールド（婚礼用） -->
                                <div class="mb-3" id="addParentsField" style="display: none;">
                                    <label class="form-label">
                                        <i class="fas fa-users text-success"></i> 両親数
                                    </label>
                                    <input type="number" class="form-control" name="parents_count" 
                                           min="0" max="10" placeholder="例: 2" value="0">
                                    <small class="form-text text-muted">両親の必要人数</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">説明</label>
                                    <input type="text" class="form-control" name="description" placeholder="例: 企業懇親会">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-primary" id="addEventSubmitBtn">追加</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- イベント編集モーダル -->
    <div class="modal fade" id="editEventModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" id="editEventForm">
                    <div class="modal-header">
                        <h5 class="modal-title">イベント編集</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_event">
                        <input type="hidden" name="event_id" id="editEventId">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">開催日</label>
                                    <input type="date" class="form-control" name="event_date" id="editEventDate" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label">開始時間</label>
                                    <div class="row g-1 time-row">
                                        <div class="col-6">
                                            <select class="form-select form-select-sm time-part-select" name="start_hour" id="editStartHour" required>
                                                <?= generateHourOptions() ?>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <select class="form-select form-select-sm time-part-select" name="start_minute" id="editStartMinute" required>
                                                <?= generateMinuteOptions() ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label">終了時間</label>
                                    <div class="row g-1 time-row">
                                        <div class="col-6">
                                            <select class="form-select form-select-sm time-part-select" name="end_hour" id="editEndHour" required>
                                                <?= generateHourOptions() ?>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <select class="form-select form-select-sm time-part-select" name="end_minute" id="editEndMinute" required>
                                                <?= generateMinuteOptions() ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">イベント種別</label>
                                    <select class="form-select" name="event_type" id="editEventType" onchange="toggleRunnerFields('edit')" required>
                                        <option value="">選択してください</option>
                                        <option value="ビュッフェ">ビュッフェ</option>
                                        <option value="コース">コース</option>
                                        <option value="会議">会議</option>
                                        <option value="婚礼">婚礼</option>
                                        <option value="その他">その他</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">総必要人数 <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" name="total_staff_required" id="editTotalStaffRequired"
                                           min="1" max="100" placeholder="例: 10" value="" required>
                                    <small class="form-text text-muted">全体で必要な人数（必須）</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">会場</label>
                                    <select class="form-select venue-select" name="venue" id="editVenue">
                                        <option value="">選択してください</option>
                                        <option value="ローズ全">ローズ全</option>
                                        <option value="ローズI">ローズI</option>
                                        <option value="ローズII">ローズII</option>
                                        <option value="クリスタル">クリスタル</option>
                                        <option value="しらさぎ">しらさぎ</option>
                                        <option value="くじゃく">くじゃく</option>
                                        <option value="ちどり">ちどり</option>
                                        <option value="グラン">グラン</option>
                                    </select>
                                    <small class="form-text text-muted">会場を選択してください（任意）</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <!-- コースランナー数フィールド -->
                                <div class="mb-3" id="editCourseRunnerField" style="display: none;">
                                    <label class="form-label">
                                        <i class="fas fa-running text-primary"></i> コースランナー数
                                    </label>
                                    <input type="number" class="form-control" name="course_runner_count" id="editCourseRunnerCount"
                                           min="0" max="50" placeholder="例: 3" value="0">
                                    <small class="form-text text-muted">コースランナーの必要人数</small>
                                </div>
                                <!-- ビュッフェランナー数フィールド -->
                                <div class="mb-3" id="editBuffetRunnerField" style="display: none;">
                                    <label class="form-label">
                                        <i class="fas fa-utensils text-warning"></i> ビュッフェランナー数
                                    </label>
                                    <input type="number" class="form-control" name="buffet_runner_count" id="editBuffetRunnerCount"
                                           min="0" max="50" placeholder="例: 2" value="0">
                                    <small class="form-text text-muted">ビュッフェランナーの必要人数</small>
                                </div>
                                <!-- ライト数フィールド（婚礼用） -->
                                <div class="mb-3" id="editLightField" style="display: none;">
                                    <label class="form-label">
                                        <i class="fas fa-lightbulb text-info"></i> ライト数
                                    </label>
                                    <input type="number" class="form-control" name="light_count" id="editLightCount"
                                           min="0" max="20" placeholder="例: 2" value="0">
                                    <small class="form-text text-muted">ライトの必要人数</small>
                                </div>
                                <!-- 両親数フィールド（婚礼用） -->
                                <div class="mb-3" id="editParentsField" style="display: none;">
                                    <label class="form-label">
                                        <i class="fas fa-users text-success"></i> 両親数
                                    </label>
                                    <input type="number" class="form-control" name="parents_count" id="editParentsCount"
                                           min="0" max="10" placeholder="例: 2" value="0">
                                    <small class="form-text text-muted">両親の必要人数</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">説明</label>
                                    <input type="text" class="form-control" name="description" id="editDescription" placeholder="例: 企業懇親会">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <!-- 空のスペース -->
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-warning" id="editEventSubmitBtn">更新</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 削除確認モーダル -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="deleteEventForm">
                    <div class="modal-header">
                        <h5 class="modal-title">イベント削除確認</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete_event">
                        <input type="hidden" name="event_id" id="deleteEventId">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <p><span id="deleteEventName"></span>を削除しますか？</p>
                        <div class="alert alert-warning">
                            <strong>注意:</strong> この操作は取り消せません。関連する出勤情報も削除されます。
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-danger">削除</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ページレベルの二重送信防止
        window.submitHistory = window.submitHistory || [];
        window.lastSubmitTime = window.lastSubmitTime || 0;
        
        // beforeunloadイベントで警告（開発時のみ）
        let formChanged = false;
        document.addEventListener('input', function() {
            formChanged = true;
        });
        
        // ブラウザの戻るボタン対策
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                console.log('ページがキャッシュから復元されました - フォームをリセット');
                location.reload();
            }
        });
        // イベント編集用のデータ（重複除去済み）
        const rawEventsData = <?= json_encode($events) ?>;
        const eventsData = rawEventsData.filter((event, index, self) => 
            index === self.findIndex(e => e.id === event.id)
        );
        
        console.log('取得したイベント数:', eventsData.length);
        
        // イベント種別に応じたランナー設定
        const eventTypeRunners = {
            'ビュッフェ': 'ビュッフェランナー',
            'コース': 'コースランナー',
            '婚礼': 'コースランナー',
            '会議': null,
            'その他': null
        };
        
        // イベント種別変更時の処理
        function handleEventTypeChange(selectElement, isEdit = false) {
            const eventType = selectElement.value;
            const runner = eventTypeRunners[eventType];
            
            // 対応するフォームの役割別入力エリアを取得
            const container = isEdit ? 
                document.getElementById('editNeedsContainer') : 
                selectElement.closest('.modal-body').querySelector('.row:last-child');
            
            // 全ての役割別入力の表示状態をリセット
            const inputs = container.querySelectorAll('input[name^="needs["]');
            inputs.forEach(input => {
                const col = input.closest('.col-md-4');
                col.style.display = 'block';
                col.classList.remove('runner-highlight');
                
                // 編集時以外は値をクリア
                if (!isEdit) {
                    input.value = '';
                }
            });
            
            if (runner) {
                // 対応するランナーのみハイライト、他は通常表示
                inputs.forEach(input => {
                    const role = input.name.match(/needs\[(.*?)\]/)[1];
                    const col = input.closest('.col-md-4');
                    col.style.display = 'block'; // 全て表示
                    
                    if (role === runner) {
                        // ランナーの入力フィールドを強調
                        col.classList.add('runner-highlight');
                    } else {
                        // 他の役割は通常表示
                        col.classList.remove('runner-highlight');
                    }
                });
                
                // 説明文を更新
                const helpText = container.parentElement.querySelector('.small.text-muted');
                if (helpText) {
                    helpText.innerHTML = `
                        <strong>${eventType}</strong>が選択されています。<br>
                        <span style="color: #1976d2;">${runner}</span>がハイライトされています。必要に応じて各役割の人数を入力してください。総必要人数（上記）は必須です。
                    `;
                }
            } else {
                // 全て表示し、強調を解除
                inputs.forEach(input => {
                    const col = input.closest('.col-md-4');
                    col.style.display = 'block';
                    col.classList.remove('runner-highlight');
                });
                
                // 説明文を元に戻す
                const helpText = container.parentElement.querySelector('.small.text-muted');
                if (helpText) {
                    helpText.innerHTML = `
                        特定の役割で必要な人数を指定したい場合に入力してください。<br>
                        <strong>総必要人数（上記）は必須です。</strong>役割別は補足的な情報として使用されます。<br>
                        <span style="color: #1976d2;">💡 イベント種別を選択すると、対応するランナーがハイライトされます。</span>
                    `;
                }
            }
        }
        
        function editEvent(eventId) {
            try {
                console.log('editEvent called with ID:', eventId);
                console.log('eventsData available:', typeof eventsData !== 'undefined');
                console.log('eventsData length:', eventsData ? eventsData.length : 'undefined');
                
                // まず編集フォームをリセット
                const editForm = document.querySelector('#editEventModal form');
                if (editForm) {
                    editForm.reset();
                } else {
                    console.error('Edit form not found!');
                }
                
                if (typeof eventsData === 'undefined') {
                    console.error('eventsData is not defined!');
                    alert('イベントデータが読み込まれていません。ページを再読み込みしてください。');
                    return;
                }
                
                const event = eventsData.find(e => e.id == eventId);
                console.log('Found event:', event);
                if (!event) {
                    console.error('Event not found for ID:', eventId);
                    alert('イベントデータが見つかりません。');
                    return;
                }
                
                // 基本情報を設定
                const editEventIdEl = document.getElementById('editEventId');
                const editEventDateEl = document.getElementById('editEventDate');
                const editEventTypeEl = document.getElementById('editEventType');
                const editVenueEl = document.getElementById('editVenue');
                const editDescriptionEl = document.getElementById('editDescription');
                const editTotalStaffRequiredEl = document.getElementById('editTotalStaffRequired');
                
                if (!editEventIdEl) console.error('editEventId element not found');
                if (!editEventDateEl) console.error('editEventDate element not found');
                if (!editEventTypeEl) console.error('editEventType element not found');
                if (!editVenueEl) console.error('editVenue element not found');
                if (!editDescriptionEl) console.error('editDescription element not found');
                if (!editTotalStaffRequiredEl) console.error('editTotalStaffRequired element not found');
                
                if (editEventIdEl) editEventIdEl.value = event.id;
                if (editEventDateEl) editEventDateEl.value = event.event_date;
                if (editEventTypeEl) editEventTypeEl.value = event.event_type;
                if (editVenueEl) editVenueEl.value = event.venue || '';
                if (editDescriptionEl) editDescriptionEl.value = event.description || '';
                if (editTotalStaffRequiredEl) editTotalStaffRequiredEl.value = event.total_staff_required || '';
                
                console.log('Set editEventId to:', event.id);
                
                // ランナー数を設定
                const editCourseRunnerCountEl = document.getElementById('editCourseRunnerCount');
                const editBuffetRunnerCountEl = document.getElementById('editBuffetRunnerCount');
                const editLightCountEl = document.getElementById('editLightCount');
                const editParentsCountEl = document.getElementById('editParentsCount');
                
                if (editCourseRunnerCountEl) editCourseRunnerCountEl.value = event.course_runner_count || 0;
                if (editBuffetRunnerCountEl) editBuffetRunnerCountEl.value = event.buffet_runner_count || 0;
                if (editLightCountEl) editLightCountEl.value = event.light_count || 0;
                if (editParentsCountEl) editParentsCountEl.value = event.parents_count || 0;
                
                // イベント種別に応じてフィールドを表示
                toggleRunnerFields('edit');
            
                // 時間を分割して設定
                if (event.start_time && event.end_time) {
                    const startTime = event.start_time.split(':');
                    const endTime = event.end_time.split(':');
                    
                    const editStartHourEl = document.getElementById('editStartHour');
                    const editStartMinuteEl = document.getElementById('editStartMinute');
                    const editEndHourEl = document.getElementById('editEndHour');
                    const editEndMinuteEl = document.getElementById('editEndMinute');
                    
                    if (editStartHourEl) editStartHourEl.value = startTime[0];
                    if (editStartMinuteEl) editStartMinuteEl.value = startTime[1];
                    if (editEndHourEl) editEndHourEl.value = endTime[0];
                    if (editEndMinuteEl) editEndMinuteEl.value = endTime[1];
                } else {
                    console.warn('Start time or end time is missing for event:', event);
                }
                
                // 役割別必要人数をクリア
                const editNeedsInputs = document.querySelectorAll('.edit-needs-input');
                if (editNeedsInputs && editNeedsInputs.length > 0) {
                    editNeedsInputs.forEach(input => {
                        input.value = '';
                    });
                } else {
                    console.warn('No .edit-needs-input elements found');
                }
                
                // イベント種別に応じて表示を調整（先に実行）
                const editEventTypeSelect = document.getElementById('editEventType');
                if (editEventTypeSelect) {
                    if (typeof handleEventTypeChange === 'function') {
                        try {
                            handleEventTypeChange(editEventTypeSelect, true);
                        } catch (e) {
                            console.warn('Error in handleEventTypeChange:', e);
                        }
                    } else {
                        console.warn('handleEventTypeChange function not found, using toggleRunnerFields instead');
                        // 代替としてtoggleRunnerFieldsを使用
                        if (typeof toggleRunnerFields === 'function') {
                            toggleRunnerFields('edit');
                        }
                    }
                } else {
                    console.warn('editEventType element not found');
                }
                
                // 既存の役割別必要人数を設定（イベント種別処理の後に実行）
                if (event.needs) {
                    try {
                        const needs = JSON.parse(event.needs);
                        Object.keys(needs).forEach(role => {
                            const input = document.querySelector(`input[data-role="${role}"]`);
                            if (input) {
                                input.value = needs[role];
                            }
                        });
                    } catch (e) {
                        console.warn('Failed to parse event needs:', e);
                    }
                }
            
                // モーダルを表示
                console.log('Opening edit modal for event ID:', event.id);
                const editModalElement = document.getElementById('editEventModal');
                if (editModalElement) {
                    const modal = new bootstrap.Modal(editModalElement);
                    modal.show();
                    
                    // モーダルが表示された後に隠しフィールドの値を再確認
                    setTimeout(() => {
                        const hiddenEventId = document.getElementById('editEventId');
                        if (hiddenEventId) {
                            console.log('Hidden event_id field value after modal show:', hiddenEventId.value);
                        } else {
                            console.error('editEventId element not found after modal show');
                        }
                    }, 100);
                } else {
                    console.error('editEventModal element not found');
                    alert('編集モーダルが見つかりません。');
                }            } catch (error) {
                console.error('Error in editEvent function:', error);
                alert('編集機能でエラーが発生しました: ' + error.message);
            }
        }
        
        function deleteEvent(eventId, eventName) {
            document.getElementById('deleteEventId').value = eventId;
            document.getElementById('deleteEventName').textContent = eventName;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }
        
        // 今日の日付を初期値に設定
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            document.querySelector('input[name="event_date"]').value = today;
            
            // 新規追加モーダルが開かれるときのイベントリスナー
            const addEventModal = document.getElementById('addEventModal');
            addEventModal.addEventListener('show.bs.modal', function() {
                console.log('New event modal opening - resetting form');
                const addForm = document.querySelector('#addEventModal form');
                if (addForm) {
                    addForm.reset();
                    // 今日の日付を再設定
                    const dateInput = addForm.querySelector('input[name="event_date"]');
                    if (dateInput) {
                        dateInput.value = today;
                    }
                }
            });
            
            // モーダルが閉じられた時にフォームをリセット
            addEventModal.addEventListener('hidden.bs.modal', function() {
                const form = this.querySelector('form');
                if (form) {
                    form.reset();
                    // 送信ボタンも元に戻す
                    const submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = '追加';
                    }
                }
            });
            
            const editModal = document.getElementById('editEventModal');
            if (editModal) {
                editModal.addEventListener('hidden.bs.modal', function() {
                    const form = this.querySelector('form');
                    if (form) {
                        form.reset();
                        // 送信ボタンも元に戻す
                        const submitBtn = form.querySelector('button[type="submit"]');
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.textContent = '更新';
                        }
                    }
                });
            }
            
            // イベント種別選択のイベントリスナーを追加
            const addEventTypeSelect = document.querySelector('#addEventModal select[name="event_type"]');
            const editEventTypeSelect = document.querySelector('#editEventModal select[name="event_type"]');
            
            if (addEventTypeSelect) {
                addEventTypeSelect.addEventListener('change', function() {
                    handleEventTypeChange(this, false);
                });
            }
            
            if (editEventTypeSelect) {
                editEventTypeSelect.addEventListener('change', function() {
                    handleEventTypeChange(this, true);
                });
            }
            
            // フォームのバリデーション
            const forms = document.querySelectorAll('form');
            const submittedForms = new Set(); // 送信済みフォームを追跡
            
            forms.forEach(form => {
                let isSubmitting = false; // 二重送信防止フラグ
                let submitStartTime = 0; // 送信開始時刻
                
                form.addEventListener('submit', function(e) {
                    const currentTime = Date.now();
                    const formId = form.getAttribute('id') || 'unknown';
                    const actionInput = form.querySelector('input[name="action"]');
                    const action = actionInput ? actionInput.value : 'unknown';
                    
                    console.log(`フォーム送信試行: ${formId}, action: ${action}, time: ${currentTime}`);
                    
                    // 1秒以内の連続送信をブロック
                    if (submitStartTime > 0 && (currentTime - submitStartTime) < 1000) {
                        e.preventDefault();
                        console.log('1秒以内の連続送信をブロックしました');
                        alert('送信処理中です。しばらくお待ちください。');
                        return false;
                    }
                    
                    // 二重送信をチェック
                    if (isSubmitting) {
                        e.preventDefault();
                        console.log('二重送信を防止しました (isSubmitting=true)');
                        alert('既に送信処理中です。');
                        return false;
                    }
                    
                    // フォームIDベースの重複チェック
                    const formKey = `${formId}_${action}_${currentTime}`;
                    if (submittedForms.has(formKey.substring(0, formKey.lastIndexOf('_')))) {
                        e.preventDefault();
                        console.log('フォーム重複送信を防止しました');
                        alert('このフォームは既に送信されています。');
                        return false;
                    }
                    
                    // デバッグ: フォーム送信前の値を確認
                    const eventIdInput = form.querySelector('input[name="event_id"]');
                    console.log('Form submission:', {
                        action: action,
                        eventId: eventIdInput ? eventIdInput.value : 'none',
                        formId: formId,
                        time: new Date(currentTime).toLocaleTimeString()
                    });
                    
                    // 編集フォームの場合、event_idの存在を確認
                    if (actionInput && actionInput.value === 'edit_event') {
                        if (!eventIdInput || !eventIdInput.value || eventIdInput.value.trim() === '') {
                            e.preventDefault();
                            alert('エラー: イベントIDが設定されていません。ページを再読み込みして再度お試しください。');
                            return false;
                        }
                    }
                    
                    const totalStaffInput = form.querySelector('input[name="total_staff_required"]');
                    if (totalStaffInput && (!totalStaffInput.value || totalStaffInput.value <= 0)) {
                        e.preventDefault();
                        alert('総必要人数は必須項目です。1以上の数値を入力してください。');
                        totalStaffInput.focus();
                        return false;
                    }
                    
                    // バリデーション通過後、送信フラグを設定
                    isSubmitting = true;
                    submitStartTime = currentTime;
                    submittedForms.add(formKey.substring(0, formKey.lastIndexOf('_')));
                    
                    console.log('フォーム送信を許可:', {
                        formId: formId,
                        action: action,
                        submitTime: new Date(currentTime).toLocaleTimeString()
                    });
                    
                    // 送信ボタンを無効化
                    const submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        const originalText = submitBtn.textContent;
                        submitBtn.textContent = '処理中...';
                        submitBtn.style.opacity = '0.6';
                        
                        // 10秒後に強制リセット（万が一のため）
                        setTimeout(() => {
                            isSubmitting = false;
                            submitStartTime = 0;
                            if (submitBtn && submitBtn.disabled) {
                                submitBtn.disabled = false;
                                submitBtn.textContent = originalText;
                                submitBtn.style.opacity = '1';
                                console.log('送信ボタンを強制リセットしました');
                            }
                        }, 10000);
                    }
                });
                
                // フォームリセット時に状態もリセット
                form.addEventListener('reset', function() {
                    isSubmitting = false;
                    submitStartTime = 0;
                    console.log('フォームリセット - 送信状態をクリア');
                });
            });
        });
        
        // イベント種別に応じてランナー数フィールドを表示/非表示
        function toggleRunnerFields(modalType) {
            const eventTypeSelect = document.getElementById(modalType + 'EventType');
            const courseRunnerField = document.getElementById(modalType + 'CourseRunnerField');
            const buffetRunnerField = document.getElementById(modalType + 'BuffetRunnerField');
            const lightField = document.getElementById(modalType + 'LightField');
            const parentsField = document.getElementById(modalType + 'ParentsField');
            
            if (!eventTypeSelect || !courseRunnerField || !buffetRunnerField || !lightField || !parentsField) {
                return;
            }
            
            const eventType = eventTypeSelect.value;
            
            // すべてのフィールドを一旦非表示に
            courseRunnerField.style.display = 'none';
            buffetRunnerField.style.display = 'none';
            lightField.style.display = 'none';
            parentsField.style.display = 'none';
            
            // イベント種別に応じて表示
            if (eventType === 'コース') {
                courseRunnerField.style.display = 'block';
            } else if (eventType === 'ビュッフェ') {
                buffetRunnerField.style.display = 'block';
            } else if (eventType === '婚礼') {
                courseRunnerField.style.display = 'block';
                lightField.style.display = 'block';
                parentsField.style.display = 'block';
            }
        }
    </script>
</body>
</html>
