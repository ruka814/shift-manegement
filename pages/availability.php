<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

// 出勤可能時間入力画面
$message = '';
$selectedEventId = $_GET['event_id'] ?? '';

// 出勤情報保存処理
if ($_POST['action'] ?? '' === 'save_availability') {
    try {
        $pdo->beginTransaction();
        
        // バリデーション
        $event_id = $_POST['event_id'] ?? null;
        $availability_data = $_POST['availability'] ?? [];
        
        if (empty($event_id)) {
            throw new Exception('イベントIDが指定されていません。');
        }
        
        if (empty($availability_data)) {
            throw new Exception('出勤情報が送信されていません。');
        }
        
        // バリデーションエラーを格納する配列
        $errors = [];
        
        // ユーザー情報を取得（バリデーション用）
        $userInfo = [];
        $stmt = $pdo->query("SELECT id, name, is_highschool FROM users");
        while ($user = $stmt->fetch()) {
            $userInfo[$user['id']] = $user;
        }
        
        foreach ($availability_data as $userId => $data) {
            // ユーザーIDの存在確認
            if (!isset($userInfo[$userId])) {
                // デバッグ用：存在しないユーザーIDをログに記録
                error_log("availability.php: User ID {$userId} not found in userInfo array");
                continue; // 存在しないユーザーIDはスキップ
            }
            
            // 既存データを削除
            $stmt = $pdo->prepare("DELETE FROM availability WHERE user_id = ? AND event_id = ?");
            $stmt->execute([$userId, $event_id]);
            
            // 新しいデータを挿入
            if (isset($data['available'])) {
                $user = $userInfo[$userId];
                
                // 高校生の時間制限チェック
                if ($user['is_highschool']) {
                    if (!empty($data['start_hour']) && !isValidHighSchoolTime($data['start_hour'])) {
                        $errors[] = "{$user['name']}さん（高校生）の開始時間は23時から4時の間は選択できません";
                    }
                    if (!empty($data['end_hour']) && !isValidHighSchoolTime($data['end_hour'])) {
                        $errors[] = "{$user['name']}さん（高校生）の終了時間は23時から4時の間は選択できません";
                    }
                }
                
                // エラーがある場合は処理を中断
                if (!empty($errors)) {
                    throw new Exception(implode('<br>', $errors));
                }
                
                // 時間と分を結合
                $start_time = null;
                $end_time = null;
                
                if (!empty($data['start_hour']) && !empty($data['start_minute'])) {
                    $start_time = sprintf('%02d:%02d', $data['start_hour'], $data['start_minute']);
                }
                
                if (!empty($data['end_hour']) && !empty($data['end_minute'])) {
                    $end_time = sprintf('%02d:%02d', $data['end_hour'], $data['end_minute']);
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO availability (user_id, event_id, available, available_start_time, available_end_time, note) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $userId,
                    $event_id,
                    1,
                    $start_time,
                    $end_time,
                    $data['note'] ?? ''
                ]);
            }
        }
        
        $pdo->commit();
        $message = showAlert('success', '出勤情報を保存しました。');
    } catch(Exception $e) {
        $pdo->rollback();
        $message = showAlert('danger', $e->getMessage());
    } catch(PDOException $e) {
        $pdo->rollback();
        $message = showAlert('danger', 'エラーが発生しました: ' . $e->getMessage());
    }
}

// イベント一覧取得
$stmt = $pdo->query("SELECT id, event_date, start_time, end_time, event_type, description FROM events ORDER BY event_date, start_time");
$events = $stmt->fetchAll();

// 選択されたイベント情報取得
$selectedEvent = null;
if ($selectedEventId) {
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$selectedEventId]);
    $selectedEvent = $stmt->fetch();
}

// ユーザー一覧取得
$stmt = $pdo->query("SELECT id, name, is_rank, is_highschool FROM users");
$users = $stmt->fetchAll();

// PHP側で五十音順にソート
$users = sortUsersByRankAndName($users);

// 既存の出勤情報取得
$existingAvailability = [];
if ($selectedEventId) {
    $stmt = $pdo->prepare("SELECT * FROM availability WHERE event_id = ?");
    $stmt->execute([$selectedEventId]);
    $availability = $stmt->fetchAll();
    
    foreach ($availability as $avail) {
        $existingAvailability[$avail['user_id']] = $avail;
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>出勤時間入力 - シフト管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="../index.php">シフト管理システム</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="users.php">スタッフ管理</a>
                <a class="nav-link" href="events.php">イベント管理</a>
                <a class="nav-link active" href="availability.php">出勤入力</a>
                <a class="nav-link" href="shift_assignment.php">シフト作成</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <?= $message ?>
        
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5>⏰ イベント選択</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET">
                            <div class="mb-3">
                                <label class="form-label">イベントを選択</label>
                                <select class="form-select" name="event_id" onchange="this.form.submit()">
                                    <option value="">選択してください</option>
                                    <?php foreach ($events as $event): ?>
                                    <option value="<?= $event['id'] ?>" <?= $selectedEventId == $event['id'] ? 'selected' : '' ?>>
                                        <?= formatDate($event['event_date']) ?> - <?= h($event['event_type']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>
                        
                        <?php if ($selectedEvent): ?>
                        <div class="event-info mt-3">
                            <h6>イベント詳細</h6>
                            <ul class="list-unstyled small">
                                <li><strong>日時:</strong> <?= formatDate($selectedEvent['event_date']) ?></li>
                                <li><strong>時間:</strong> <?= formatTime($selectedEvent['start_time']) ?> - <?= formatTime($selectedEvent['end_time']) ?></li>
                                <li><strong>種別:</strong> <?= h($selectedEvent['event_type']) ?></li>
                                <li><strong>説明:</strong> <?= h($selectedEvent['description']) ?></li>
                            </ul>
                            
                            <div class="mt-3">
                                <h6>必要人数</h6>
                                <?php
                                $needs = parseNeeds($selectedEvent['needs']);
                                foreach ($needs as $role => $count):
                                ?>
                                <small class="d-block">
                                    <span class="badge bg-secondary"><?= h($role) ?>: <?= $count['display'] ?></span>
                                </small>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <?php if ($selectedEvent): ?>
                <div class="card">
                    <div class="card-header">
                        <h5>👥 スタッフ出勤時間入力</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="save_availability">
                            <input type="hidden" name="event_id" value="<?= $selectedEventId ?>">
                            
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th>スタッフ名</th>
                                            <th>ランク</th>
                                            <th>出勤可能</th>
                                            <th>開始時間</th>
                                            <th>終了時間</th>
                                            <th>備考</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $previousRank = null;
                                        foreach ($users as $user): 
                                            // ランナーからランナー以外に変わるタイミングで区切り行を追加
                                            if ($previousRank === 'ランナー' && $user['is_rank'] !== 'ランナー'):
                                        ?>
                                        <tr class="table-secondary">
                                            <td colspan="6" class="text-center fw-bold">
                                                <i class="fas fa-minus"></i> ランナー以外 <i class="fas fa-minus"></i>
                                            </td>
                                        </tr>
                                        <?php 
                                            elseif ($previousRank === null && $user['is_rank'] === 'ランナー'):
                                        ?>
                                        <tr class="table-primary">
                                            <td colspan="6" class="text-center fw-bold">
                                                <i class="fas fa-star"></i> ランナー <i class="fas fa-star"></i>
                                            </td>
                                        </tr>
                                        <?php 
                                            elseif ($previousRank === null && $user['is_rank'] !== 'ランナー'):
                                        ?>
                                        <tr class="table-secondary">
                                            <td colspan="6" class="text-center fw-bold">
                                                <i class="fas fa-minus"></i> ランナー以外 <i class="fas fa-minus"></i>
                                            </td>
                                        </tr>
                                        <?php 
                                            endif;
                                            $previousRank = $user['is_rank'];
                                        ?>
                                        <?php 
                                        $existing = $existingAvailability[$user['id']] ?? null;
                                        $isChecked = $existing && $existing['available'];
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?= h($user['name']) ?></strong>
                                                <?php if ($user['is_highschool']): ?>
                                                    <span class="badge bg-warning text-dark ms-1">🎓高校生</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= getRankBadge($user['is_rank']) ?>
                                            </td>
                                            <td>
                                                <div class="form-check">
                                                    <input class="form-check-input availability-check" 
                                                           type="checkbox" 
                                                           name="availability[<?= $user['id'] ?>][available]"
                                                           id="available_<?= $user['id'] ?>"
                                                           data-user-id="<?= $user['id'] ?>"
                                                           <?= $isChecked ? 'checked' : '' ?>>
                                                </div>
                                            </td>
                                            <td>
                                                <?php 
                                                $startTime = parseTimeString($existing['available_start_time'] ?? '');
                                                ?>
                                                <div class="row g-1 time-row">
                                                    <div class="col-6">
                                                        <select class="form-select form-select-sm time-part-select" 
                                                                name="availability[<?= $user['id'] ?>][start_hour]"
                                                                <?= !$isChecked ? 'disabled' : '' ?>>
                                                            <?= $user['is_highschool'] ? generateHourOptionsForHighSchool($startTime['hour']) : generateHourOptions($startTime['hour']) ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-6">
                                                        <select class="form-select form-select-sm time-part-select" 
                                                                name="availability[<?= $user['id'] ?>][start_minute]"
                                                                <?= !$isChecked ? 'disabled' : '' ?>>
                                                            <?= generateMinuteOptions($startTime['minute']) ?>
                                                        </select>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php 
                                                $endTime = parseTimeString($existing['available_end_time'] ?? '');
                                                ?>
                                                <div class="row g-1 time-row">
                                                    <div class="col-6">
                                                        <select class="form-select form-select-sm time-part-select" 
                                                                name="availability[<?= $user['id'] ?>][end_hour]"
                                                                <?= !$isChecked ? 'disabled' : '' ?>>
                                                            <?= $user['is_highschool'] ? generateHourOptionsForHighSchool($endTime['hour']) : generateHourOptions($endTime['hour']) ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-6">
                                                        <select class="form-select form-select-sm time-part-select" 
                                                                name="availability[<?= $user['id'] ?>][end_minute]"
                                                                <?= !$isChecked ? 'disabled' : '' ?>>
                                                            <?= generateMinuteOptions($endTime['minute']) ?>
                                                        </select>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <input type="text" 
                                                       class="form-control form-control-sm" 
                                                       name="availability[<?= $user['id'] ?>][note]"
                                                       value="<?= h($existing['note'] ?? '') ?>"
                                                       placeholder="備考"
                                                       <?= !$isChecked ? 'disabled' : '' ?>>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="d-flex justify-content-between align-items-center mt-3">
                                <div>
                                    <button type="button" class="btn btn-outline-secondary" onclick="selectAll()">
                                        全員選択
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="clearAll()">
                                        全員解除
                                    </button>
                                    <button type="button" class="btn btn-outline-info" onclick="setEventTime()">
                                        イベント時間を設定
                                    </button>
                                </div>
                                <button type="submit" class="btn btn-primary">
                                    保存
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php else: ?>
                <div class="card">
                    <div class="card-body text-center">
                        <h5>イベントを選択してください</h5>
                        <p class="text-muted">左側からイベントを選択すると、出勤時間の入力が可能になります。</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // チェックボックスの状態に応じて入力フィールドを有効/無効にする
        document.querySelectorAll('.availability-check').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const userId = this.dataset.userId;
                const row = this.closest('tr');
                const selects = row.querySelectorAll('select, input[type="text"]');
                
                selects.forEach(select => {
                    select.disabled = !this.checked;
                    if (!this.checked) {
                        if (select.tagName === 'SELECT') {
                            select.value = '';
                        } else {
                            select.value = '';
                        }
                    }
                });
            });
        });
        
        function selectAll() {
            document.querySelectorAll('.availability-check').forEach(checkbox => {
                checkbox.checked = true;
                checkbox.dispatchEvent(new Event('change'));
            });
        }
        
        function clearAll() {
            document.querySelectorAll('.availability-check').forEach(checkbox => {
                checkbox.checked = false;
                checkbox.dispatchEvent(new Event('change'));
            });
        }
        
        function setEventTime() {
            const startTime = '<?= $selectedEvent['start_time'] ?? '' ?>';
            const endTime = '<?= $selectedEvent['end_time'] ?? '' ?>';
            
            if (startTime) {
                const startParts = startTime.split(':');
                document.querySelectorAll('select[name*="[start_hour]"]').forEach(select => {
                    if (!select.disabled) {
                        select.value = startParts[0];
                    }
                });
                document.querySelectorAll('select[name*="[start_minute]"]').forEach(select => {
                    if (!select.disabled) {
                        select.value = startParts[1];
                    }
                });
            }
            
            if (endTime) {
                const endParts = endTime.split(':');
                document.querySelectorAll('select[name*="[end_hour]"]').forEach(select => {
                    if (!select.disabled) {
                        select.value = endParts[0];
                    }
                });
                document.querySelectorAll('select[name*="[end_minute]"]').forEach(select => {
                    if (!select.disabled) {
                        select.value = endParts[1];
                    }
                });
            }
        }
    </script>
</body>
</html>
