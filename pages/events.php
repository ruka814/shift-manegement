<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

// イベント管理画面
$message = '';

// イベント追加処理
if ($_POST['action'] ?? '' === 'add_event') {
    try {
        $needs = [];
        foreach ($_POST['needs'] as $role => $count) {
            if (!empty($count)) {
                $needs[$role] = $count;
            }
        }
        
        // 時間と分を結合
        $start_time = sprintf('%02d:%02d', $_POST['start_hour'], $_POST['start_minute']);
        $end_time = sprintf('%02d:%02d', $_POST['end_hour'], $_POST['end_minute']);
        
        $stmt = $pdo->prepare("
            INSERT INTO events (event_date, start_time, end_time, event_type, needs, description) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $_POST['event_date'],
            $start_time,
            $end_time,
            $_POST['event_type'],
            json_encode($needs),
            $_POST['description']
        ]);
        
        $message = showAlert('success', 'イベントを追加しました。');
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
    } catch(PDOException $e) {
        $message = showAlert('danger', 'エラーが発生しました: ' . $e->getMessage());
    }
}

// イベント一覧取得
$stmt = $pdo->query("SELECT * FROM events ORDER BY event_date, start_time");
$events = $stmt->fetchAll();

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
                                <th>必要人数</th>
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
                                    <?php
                                    $needs = parseNeeds($event['needs']);
                                    foreach ($needs as $role => $count):
                                    ?>
                                        <small class="d-block">
                                            <?= h($role) ?>: <?= $count['display'] ?>
                                        </small>
                                    <?php endforeach; ?>
                                </td>
                                <td><?= h($event['description']) ?></td>
                                <td>
                                    <div class="btn-group">
                                        <a href="availability.php?event_id=<?= $event['id'] ?>" 
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
                                    <label class="form-label">説明</label>
                                    <input type="text" class="form-control" name="description" placeholder="例: 企業懇親会">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">必要人数</label>
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
        function deleteEvent(eventId, eventName) {
            document.getElementById('deleteEventId').value = eventId;
            document.getElementById('deleteEventName').textContent = eventName;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }
        
        // 今日の日付を初期値に設定
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            document.querySelector('input[name="event_date"]').value = today;
        });
    </script>
</body>
</html>
