<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

// イベント管理画面
$message = '';

// セッションからメッセージを取得
session_start();
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

// イベント追加処理
if ($_POST['action'] ?? '' === 'add_event') {
    try {
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
                INSERT INTO events (event_date, start_time, end_time, event_type, venue, needs, description, total_staff_required) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $totalStaffRequired = (int)$_POST['total_staff_required'];
            
            error_log("events.php: 総必要人数の値: " . $totalStaffRequired);
            
            $result = $stmt->execute([
                $_POST['event_date'],
                $start_time,
                $end_time,
                $_POST['event_type'],
                $_POST['venue'] ?? '',
                json_encode($needs),
                $_POST['description'] ?? '',
                $totalStaffRequired
            ]);
        } else {
            // total_staff_requiredカラムがない場合はエラー
            throw new Exception('データベースに総必要人数フィールドがありません。システム管理者にお問い合わせください。');
        }
        
        if ($result) {
            $newEventId = $pdo->lastInsertId();
            error_log("events.php: イベント追加成功 - ID: {$newEventId}");
            
            // セッションにメッセージを保存してリダイレクト
            session_start();
            $_SESSION['message'] = showAlert('success', "イベントを追加しました。（ID: {$newEventId}）");
            header('Location: events.php');
            exit;
        } else {
            throw new Exception('イベントの追加に失敗しました。');
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
            
            error_log("events.php: UPDATE実行開始 - event_id: " . $_POST['event_id']);
            
            $stmt = $pdo->prepare("
                UPDATE events 
                SET event_date = ?, start_time = ?, end_time = ?, event_type = ?, venue = ?, needs = ?, description = ?, total_staff_required = ?
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
        
        // セッションにメッセージを保存してリダイレクト
        session_start();
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
        $stmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
        $stmt->execute([$_POST['event_id']]);
        $message = showAlert('success', 'イベントを削除しました。');
        
        // セッションにメッセージを保存してリダイレクト
        session_start();
        $_SESSION['message'] = $message;
        header('Location: events.php');
        exit;
    } catch(PDOException $e) {
        $message = showAlert('danger', 'エラーが発生しました: ' . $e->getMessage());
    }
}

// イベント一覧取得
try {
    error_log("events.php: イベント一覧取得開始");
    
    // まず基本的なフィールドで試行
    $stmt = $pdo->query("SELECT id, event_date, start_time, end_time, event_type, venue, needs, description FROM events ORDER BY event_date, start_time");
    $events = $stmt->fetchAll();
    
    // total_staff_requiredカラムの存在をチェック
    $hasTotal = false;
    try {
        $checkStmt = $pdo->query("SELECT total_staff_required FROM events LIMIT 1");
        $hasTotal = true;
        error_log("events.php: total_staff_requiredカラムが存在します");
    } catch(PDOException $e) {
        error_log("events.php: total_staff_requiredカラムが存在しません: " . $e->getMessage());
    }
    
    if ($hasTotal) {
        // total_staff_requiredカラムが存在する場合は再取得
        $stmt = $pdo->query("SELECT id, event_date, start_time, end_time, event_type, venue, needs, description, total_staff_required FROM events ORDER BY event_date, start_time");
        $events = $stmt->fetchAll();
    } else {
        // カラムが存在しない場合はnullで初期化
        foreach ($events as &$event) {
            $event['total_staff_required'] = null;
        }
    }
    
    // venueカラムが存在しない場合の対応
    foreach ($events as &$event) {
        if (!isset($event['venue'])) {
            $event['venue'] = '';
        }
    }
    
    error_log("events.php: " . count($events) . "件のイベントを取得しました");
    
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
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">新規イベント追加</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_event">
                        
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
                                    <select class="form-select" name="event_type" required>
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
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">総必要人数 <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" name="total_staff_required" 
                                           min="1" max="100" placeholder="例: 10" value="" required>
                                    <small class="form-text text-muted">全体で必要な人数（必須）</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">説明</label>
                                    <input type="text" class="form-control" name="description" placeholder="例: 企業懇親会">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">役割別必要人数 <small class="text-muted">（オプション）</small></label>
                            <p class="small text-muted mb-2">
                                特定の役割で必要な人数を指定したい場合に入力してください。<br>
                                <strong>総必要人数（上記）は必須です。</strong>役割別は補足的な情報として使用されます。<br>
                                <span style="color: #1976d2;">💡 イベント種別を選択すると、対応するランナーがハイライトされます。</span>
                            </p>
                            <div class="row">
                                <?php foreach ($taskTypes as $taskType): ?>
                                <div class="col-md-4 mb-2">
                                    <label class="form-label small"><?= h($taskType['name']) ?></label>
                                    <input type="text" 
                                           class="form-control form-control-sm" 
                                           name="needs[<?= h($taskType['name']) ?>]" 
                                           placeholder="例: 2 or 1-3">
                                    <small class="form-text text-muted">固定数または範囲（1-3）</small>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-primary">追加</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- イベント編集モーダル -->
    <div class="modal fade" id="editEventModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">イベント編集</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_event">
                        <input type="hidden" name="event_id" id="editEventId">
                        
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
                                    <select class="form-select" name="event_type" id="editEventType" required>
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
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">総必要人数 <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" name="total_staff_required" id="editTotalStaffRequired"
                                           min="1" max="100" placeholder="例: 10" value="" required>
                                    <small class="form-text text-muted">全体で必要な人数（必須）</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">説明</label>
                                    <input type="text" class="form-control" name="description" id="editDescription" placeholder="例: 企業懇親会">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">役割別必要人数 <small class="text-muted">（オプション）</small></label>
                            <p class="small text-muted mb-2">
                                特定の役割で必要な人数を指定したい場合に入力してください。<br>
                                <strong>総必要人数（上記）は必須です。</strong>役割別は補足的な情報として使用されます。<br>
                                <span style="color: #1976d2;">💡 イベント種別を選択すると、対応するランナーがハイライトされます。</span>
                            </p>
                            <div class="row" id="editNeedsContainer">
                                <?php foreach ($taskTypes as $taskType): ?>
                                <div class="col-md-4 mb-2">
                                    <label class="form-label small"><?= h($taskType['name']) ?></label>
                                    <input type="text" 
                                           class="form-control form-control-sm edit-needs-input" 
                                           name="needs[<?= h($taskType['name']) ?>]" 
                                           data-role="<?= h($taskType['name']) ?>"
                                           placeholder="例: 2 or 1-3">
                                    <small class="form-text text-muted">固定数または範囲（1-3）</small>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-warning">更新</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 削除確認モーダル -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">イベント削除確認</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete_event">
                        <input type="hidden" name="event_id" id="deleteEventId">
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
        // イベント編集用のデータ
        const eventsData = <?= json_encode($events) ?>;
        
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
            console.log('editEvent called with ID:', eventId);
            
            // まず編集フォームをリセット
            const editForm = document.querySelector('#editEventModal form');
            if (editForm) {
                editForm.reset();
            }
            
            const event = eventsData.find(e => e.id == eventId);
            console.log('Found event:', event);
            if (!event) {
                alert('イベントデータが見つかりません。');
                return;
            }
            
            // 基本情報を設定
            document.getElementById('editEventId').value = event.id;
            console.log('Set editEventId to:', event.id);
            document.getElementById('editEventDate').value = event.event_date;
            document.getElementById('editEventType').value = event.event_type;
            document.getElementById('editVenue').value = event.venue || '';
            document.getElementById('editDescription').value = event.description || '';
            document.getElementById('editTotalStaffRequired').value = event.total_staff_required || '';
            
            // 時間を分割して設定
            const startTime = event.start_time.split(':');
            const endTime = event.end_time.split(':');
            
            document.getElementById('editStartHour').value = startTime[0];
            document.getElementById('editStartMinute').value = startTime[1];
            document.getElementById('editEndHour').value = endTime[0];
            document.getElementById('editEndMinute').value = endTime[1];
            
            // 役割別必要人数をクリア
            document.querySelectorAll('.edit-needs-input').forEach(input => {
                input.value = '';
            });
            
            // イベント種別に応じて表示を調整（先に実行）
            const editEventTypeSelect = document.getElementById('editEventType');
            handleEventTypeChange(editEventTypeSelect, true);
            
            // 既存の役割別必要人数を設定（イベント種別処理の後に実行）
            if (event.needs) {
                const needs = JSON.parse(event.needs);
                Object.keys(needs).forEach(role => {
                    const input = document.querySelector(`input[data-role="${role}"]`);
                    if (input) {
                        input.value = needs[role];
                    }
                });
            }
            
            // モーダルを表示
            console.log('Opening edit modal for event ID:', event.id);
            const modal = new bootstrap.Modal(document.getElementById('editEventModal'));
            modal.show();
            
            // モーダルが表示された後に隠しフィールドの値を再確認
            setTimeout(() => {
                const hiddenEventId = document.getElementById('editEventId').value;
                console.log('Hidden event_id field value after modal show:', hiddenEventId);
            }, 100);
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
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    // デバッグ: フォーム送信前の値を確認
                    const actionInput = form.querySelector('input[name="action"]');
                    const eventIdInput = form.querySelector('input[name="event_id"]');
                    console.log('Form submission:', {
                        action: actionInput ? actionInput.value : 'none',
                        eventId: eventIdInput ? eventIdInput.value : 'none'
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
                });
            });
        });
    </script>
</body>
</html>
