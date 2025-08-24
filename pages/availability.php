<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

// 日付ベース出勤可能時間入力画面
$message = '';
$selectedDate = $_GET['work_date'] ?? $_GET['date'] ?? date('Y-m-d');

// 出勤情報更新処理
if ($_POST['action'] ?? '' === 'update_availability') {
    try {
        $pdo->beginTransaction();
        
        // バリデーション
        $work_date = $_POST['work_date'] ?? null;
        $availability_data = $_POST['availability'] ?? [];
        
        if (empty($work_date)) {
            throw new Exception('日付が指定されていません。');
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
            
            // まず該当日のユーザーの既存データを削除（一般的な出勤情報のみ）
            // event_id IS NULL または event_id = 0 の両方を削除
            $stmt = $pdo->prepare("DELETE FROM availability WHERE user_id = ? AND work_date = ? AND (event_id IS NULL OR event_id = 0)");
            $stmt->execute([$userId, $work_date]);            // 時間が入力されている場合のみ保存
            $hasStartTime = !empty($data['start_hour']) && !empty($data['start_minute']);
            $hasEndTime = !empty($data['end_hour']) && !empty($data['end_minute']);
            
            if ($hasStartTime || $hasEndTime) {
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
                
                if ($hasStartTime) {
                    $start_time = sprintf('%02d:%02d', $data['start_hour'], $data['start_minute']);
                }
                
                if ($hasEndTime) {
                    $end_time = sprintf('%02d:%02d', $data['end_hour'], $data['end_minute']);
                }
                
                // まず、テーブル構造を確認してevent_idがNULL許可かチェック
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO availability (user_id, work_date, available, available_start_time, available_end_time, event_id) 
                        VALUES (?, ?, ?, ?, ?, NULL)
                    ");
                    $stmt->execute([
                        $userId,
                        $work_date,
                        1,
                        $start_time,
                        $end_time
                    ]);
                } catch (PDOException $e) {
                    // NULLが許可されていない場合は、0を使用（一般的な出勤情報の識別子として）
                    if (strpos($e->getMessage(), 'cannot be null') !== false) {
                        $stmt = $pdo->prepare("
                            INSERT INTO availability (user_id, work_date, available, available_start_time, available_end_time, event_id) 
                            VALUES (?, ?, ?, ?, ?, 0)
                        ");
                        $stmt->execute([
                            $userId,
                            $work_date,
                            1,
                            $start_time,
                            $end_time
                        ]);
                    } else {
                        throw $e; // その他のエラーは再スロー
                    }
                }
            }
        }
        
        $pdo->commit();
        $message = showAlert('success', '出勤情報を更新しました。');
        $selectedDate = $work_date; // 保存後も同じ日付を表示
    } catch(Exception $e) {
        $pdo->rollback();
        $message = showAlert('danger', $e->getMessage());
    } catch(PDOException $e) {
        $pdo->rollback();
        $message = showAlert('danger', 'エラーが発生しました: ' . $e->getMessage());
    }
}

// ユーザー一覧取得
$stmt = $pdo->query("SELECT * FROM users");
$users = $stmt->fetchAll();

// PHP側でランク別かつ五十音順にソート
$users = sortUsersByRankAndName($users);

// 既存の出勤情報取得（選択された日付、一般的な出勤情報のみ）
$existingAvailability = [];
if ($selectedDate) {
    $stmt = $pdo->prepare("SELECT * FROM availability WHERE work_date = ? AND (event_id IS NULL OR event_id = 0)");
    $stmt->execute([$selectedDate]);
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
    <style>
        /* 日付選択の改善 */
        .date-quick-buttons .btn {
            transition: all 0.2s ease;
        }
        
        .date-quick-buttons .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .date-quick-buttons .btn.active {
            transform: none;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
        }
        
        /* 時間入力の改善 */
        .time-part-select {
            font-size: 0.875rem;
        }
        
        .time-row {
            gap: 2px !important;
        }
        
        /* 日付入力フィールドの改善 */
        input[type="date"] {
            cursor: pointer;
        }
        
        input[type="date"]::-webkit-calendar-picker-indicator {
            cursor: pointer;
            padding: 4px;
        }
        
        /* 選択された日付のハイライト */
        .alert-info {
            border-left: 4px solid #0d6efd;
        }
        
        /* 過去日付の警告スタイル */
        .text-warning {
            font-weight: 500;
        }
        
        /* レスポンシブ対応 */
        @media (max-width: 768px) {
            .btn-group-vertical .btn {
                font-size: 0.875rem;
                padding: 0.375rem 0.5rem;
            }
        }
    </style>
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
                <a class="nav-link" href="saved_shifts.php">保存済みシフト</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <?= $message ?>
        
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5>📅 日付選択</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" id="dateForm">
                            <div class="mb-3">
                                <label class="form-label">出勤日を選択</label>
                                <input type="date" class="form-control" name="work_date" 
                                       value="<?= $selectedDate ?>" 
                                       min="<?= date('Y-m-d') ?>"
                                       max="<?= date('Y-m-d', strtotime('+6 months')) ?>"
                                       onchange="this.form.submit()"
                                       required>
                                <div class="form-text">
                                    今日から6ヶ月先まで選択可能です
                                </div>
                            </div>
                        </form>
                        
                        <!-- 日付クイック選択ボタン -->
                        <div class="mb-3">
                            <label class="form-label small">クイック選択</label>
                            <div class="btn-group-vertical d-grid gap-1 date-quick-buttons">
                                <a href="?work_date=<?= date('Y-m-d') ?>" 
                                   class="btn btn-outline-primary btn-sm <?= $selectedDate === date('Y-m-d') ? 'active' : '' ?>">
                                    📅 今日 (<?= date('n/j') ?>)
                                </a>
                                <a href="?work_date=<?= date('Y-m-d', strtotime('+1 day')) ?>" 
                                   class="btn btn-outline-primary btn-sm <?= $selectedDate === date('Y-m-d', strtotime('+1 day')) ? 'active' : '' ?>">
                                    📅 明日 (<?= date('n/j', strtotime('+1 day')) ?>)
                                </a>
                                <a href="?work_date=<?= date('Y-m-d', strtotime('next Saturday')) ?>" 
                                   class="btn btn-outline-success btn-sm <?= $selectedDate === date('Y-m-d', strtotime('next Saturday')) ? 'active' : '' ?>">
                                    📅 次の土曜日 (<?= date('n/j', strtotime('next Saturday')) ?>)
                                </a>
                                <a href="?work_date=<?= date('Y-m-d', strtotime('next Sunday')) ?>" 
                                   class="btn btn-outline-success btn-sm <?= $selectedDate === date('Y-m-d', strtotime('next Sunday')) ? 'active' : '' ?>">
                                    📅 次の日曜日 (<?= date('n/j', strtotime('next Sunday')) ?>)
                                </a>
                            </div>
                        </div>
                        
                        <?php if ($selectedDate): ?>
                        <div class="alert alert-info">
                            <h6 class="mb-2"><i class="fas fa-calendar-check"></i> 選択した日付</h6>
                            <div class="row">
                                <div class="col-6">
                                    <strong>日付:</strong><br>
                                    <?= date('Y年m月d日', strtotime($selectedDate)) ?>
                                </div>
                                <div class="col-6">
                                    <strong>曜日:</strong><br>
                                    <span class="badge <?= in_array(date('w', strtotime($selectedDate)), [0, 6]) ? 'bg-warning text-dark' : 'bg-primary' ?>">
                                        <?= formatJapaneseWeekday($selectedDate) ?>
                                    </span>
                                </div>
                            </div>
                            
                            <?php
                            // 過去の日付チェック
                            if (strtotime($selectedDate) < strtotime(date('Y-m-d'))):
                            ?>
                            <div class="mt-2">
                                <small class="text-warning">
                                    <i class="fas fa-exclamation-triangle"></i> 
                                    過去の日付が選択されています
                                </small>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <?php if ($selectedDate): ?>
                <div class="card">
                    <div class="card-header">
                        <h5>👥 スタッフ出勤時間入力</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="update_availability">
                            <input type="hidden" name="work_date" value="<?= $selectedDate ?>">
                            
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th>スタッフ名</th>
                                            <th>開始時間</th>
                                            <th>終了時間</th>
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
                                            <td colspan="3" class="text-center fw-bold">
                                                <i class="fas fa-minus"></i> ランナー以外 <i class="fas fa-minus"></i>
                                            </td>
                                        </tr>
                                        <?php 
                                            elseif ($previousRank === null && $user['is_rank'] === 'ランナー'):
                                        ?>
                                        <tr class="table-primary">
                                            <td colspan="3" class="text-center fw-bold">
                                                <i class="fas fa-star"></i> ランナー <i class="fas fa-star"></i>
                                            </td>
                                        </tr>
                                        <?php 
                                            elseif ($previousRank === null && $user['is_rank'] !== 'ランナー'):
                                        ?>
                                        <tr class="table-secondary">
                                            <td colspan="4" class="text-center fw-bold">
                                                <i class="fas fa-minus"></i> ランナー以外 <i class="fas fa-minus"></i>
                                            </td>
                                        </tr>
                                        <?php 
                                            endif;
                                            $previousRank = $user['is_rank'];
                                        ?>
                                        <?php 
                                        $existing = $existingAvailability[$user['id']] ?? null;
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold"><?= h($user['name']) ?></div>
                                                <?php if (isset($user['furigana']) && !empty($user['furigana'])): ?>
                                                <small class="text-muted"><?= h($user['furigana']) ?></small>
                                                <?php endif; ?>
                                                <?php if ($user['is_highschool']): ?>
                                                    <span class="badge bg-warning text-dark ms-1">🎓高校生</span>
                                                <?php endif; ?>
                                                <?php if (isRunner($user['is_rank'])): ?>
                                                    <div class="mt-1">
                                                        <?php
                                                        // ランナースキルのみ簡潔に表示
                                                        $runnerSkillsStmt = $pdo->prepare("
                                                            SELECT tt.name, s.skill_level 
                                                            FROM skills s 
                                                            JOIN task_types tt ON s.task_type_id = tt.id 
                                                            WHERE s.user_id = ? AND tt.name IN ('コースランナー', 'ブッフェランナー') AND s.skill_level = 'できる'
                                                            ORDER BY tt.name
                                                        ");
                                                        $runnerSkillsStmt->execute([$user['id']]);
                                                        $runnerSkills = $runnerSkillsStmt->fetchAll();
                                                        
                                                        $skillLabels = [];
                                                        foreach ($runnerSkills as $skill) {
                                                            if ($skill['name'] === 'コースランナー') {
                                                                $skillLabels[] = '<span class="badge bg-success text-white" style="font-size: 0.7rem;"><i class="fas fa-utensils"></i> コース</span>';
                                                            } elseif ($skill['name'] === 'ブッフェランナー') {
                                                                $skillLabels[] = '<span class="badge bg-warning text-dark" style="font-size: 0.7rem;"><i class="fas fa-server"></i> ブッフェ</span>';
                                                            }
                                                        }
                                                        
                                                        if (count($skillLabels) === 2) {
                                                            echo '<span class="badge bg-primary text-white" style="font-size: 0.7rem;"><i class="fas fa-crown"></i> 両方対応</span>';
                                                        } elseif (count($skillLabels) > 0) {
                                                            echo implode(' ', $skillLabels);
                                                        } else {
                                                            echo '<small class="text-muted">ランナー</small>';
                                                        }
                                                        ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                $startTime = parseTimeString($existing['available_start_time'] ?? '');
                                                ?>
                                                <div class="row g-1 time-row">
                                                    <div class="col-6">
                                                        <select class="form-select form-select-sm time-part-select" 
                                                                name="availability[<?= $user['id'] ?>][start_hour]">
                                                            <?= $user['is_highschool'] ? generateHourOptionsForHighSchool($startTime['hour']) : generateHourOptions($startTime['hour']) ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-6">
                                                        <select class="form-select form-select-sm time-part-select" 
                                                                name="availability[<?= $user['id'] ?>][start_minute]">
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
                                                                name="availability[<?= $user['id'] ?>][end_hour]">
                                                            <?= $user['is_highschool'] ? generateHourOptionsForHighSchool($endTime['hour']) : generateHourOptions($endTime['hour']) ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-6">
                                                        <select class="form-select form-select-sm time-part-select" 
                                                                name="availability[<?= $user['id'] ?>][end_minute]">
                                                            <?= generateMinuteOptions($endTime['minute']) ?>
                                                        </select>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="d-flex justify-content-end mt-3">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-sync-alt"></i> 更新
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php else: ?>
                <div class="card">
                    <div class="card-body text-center">
                        <h5>日付を選択してください</h5>
                        <p class="text-muted">左側から出勤日を選択すると、出勤時間の入力が可能になります。</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // 日付入力フィールドの設定
            const dateInput = document.querySelector('input[name="work_date"]');
            if (dateInput) {
                // 日付変更時の処理
                dateInput.addEventListener('change', function() {
                    // 選択された日付をローカルストレージに保存
                    localStorage.setItem('selectedDate', this.value);
                    
                    // フォーム送信前に確認
                    const selectedDate = new Date(this.value);
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    
                    if (selectedDate < today) {
                        if (!confirm('過去の日付が選択されています。この日付で出勤時間を入力しますか？')) {
                            this.value = '<?= $selectedDate ?>';
                            return false;
                        }
                    }
                });
                
                // ページ読み込み時に保存された日付を復元
                const savedDate = localStorage.getItem('selectedDate');
                if (savedDate && !dateInput.value) {
                    dateInput.value = savedDate;
                }
            }
            
            // クイック選択ボタンのハイライト
            const quickButtons = document.querySelectorAll('.btn-outline-primary, .btn-outline-success');
            quickButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const url = new URL(this.href);
                    const selectedDate = url.searchParams.get('work_date');
                    localStorage.setItem('selectedDate', selectedDate);
                });
            });
            
            // 時間入力の改善
            const timeSelects = document.querySelectorAll('.time-part-select');
            timeSelects.forEach(select => {
                select.addEventListener('change', function() {
                    // 時間選択時の自動調整機能
                    const row = this.closest('tr');
                    const userId = this.name.match(/\[(\d+)\]/)[1];
                    const isStart = this.name.includes('start');
                    
                    if (isStart) {
                        // 開始時間が選択された場合、終了時間の最小値を調整
                        const startHour = row.querySelector(`[name="availability[${userId}][start_hour]"]`).value;
                        const endHourSelect = row.querySelector(`[name="availability[${userId}][end_hour]"]`);
                        
                        if (startHour && endHourSelect.value && parseInt(endHourSelect.value) <= parseInt(startHour)) {
                            // 終了時間を開始時間より後に自動調整
                            endHourSelect.value = Math.min(parseInt(startHour) + 1, 23);
                        }
                    }
                });
            });
            
            // フォーム送信時の検証
            const form = document.querySelector('form[method="POST"]');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const timeInputs = this.querySelectorAll('.time-part-select');
                    let hasTimeInput = false;
                    
                    timeInputs.forEach(input => {
                        if (input.value) {
                            hasTimeInput = true;
                        }
                    });
                    
                    if (!hasTimeInput) {
                        e.preventDefault();
                        alert('少なくとも1人以上のスタッフの出勤時間を入力してください。');
                        return false;
                    }
                    
                    // 更新確認
                    if (!confirm('入力した出勤時間を更新しますか？')) {
                        e.preventDefault();
                        return false;
                    }
                });
            }
        });
    </script>
</body>
</html>
