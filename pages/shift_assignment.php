<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

// シフト自動作成画面
$selectedEventId = $_GET['event_id'] ?? '';
$message = '';
$assignmentResult = null;

// 簡易データベース初期化処理
if ($_POST['action'] ?? '' === 'init_sample_data') {
    try {
        // 最小限のサンプルデータを挿入
        
        // タスクタイプを挿入
        $pdo->exec("INSERT IGNORE INTO task_types (id, name, description) VALUES 
            (1, '両親', '会場内での料理運搬、セッティング'),
            (2, 'ライト', '軽作業、補助業務'),
            (3, 'コースランナー', 'コース料理の配膳・サービス'),
            (4, 'ブッフェランナー', 'ブッフェ会場での配膳・補充')");
        
        // サンプルユーザーを挿入
        $pdo->exec("INSERT IGNORE INTO users (id, name, furigana, gender, is_highschool, max_workdays, is_rank) VALUES 
            (1, '田中太郎', 'たなかたろう', 'M', FALSE, 15, 'ランナー'),
            (2, '佐藤花子', 'さとうはなこ', 'F', FALSE, 12, 'ランナー'),
            (3, '山田一郎', 'やまだいちろう', 'M', TRUE, 8, 'ランナー'),
            (4, '鈴木美香', 'すずきみか', 'F', FALSE, 10, 'ランナー'),
            (5, '吉田和也', 'よしだかずや', 'M', FALSE, 8, 'ランナー以外')");
        
        // サンプルイベントを挿入
        $pdo->exec("INSERT IGNORE INTO events (id, event_date, start_time, end_time, event_type, venue, needs, description) VALUES 
            (1, '2025-08-15', '18:00:00', '22:00:00', 'ビュッフェ', 'ローズII', '{\"両親\": \"2-3\", \"ライト\": 2}', '企業懇親会'),
            (2, '2025-08-20', '11:00:00', '15:00:00', '婚礼', 'クリスタル', '{\"両親\": 4, \"ライト\": \"1-2\"}', '結婚披露宴')");
        
        // サンプルスキルを挿入
        $pdo->exec("INSERT IGNORE INTO skills (user_id, task_type_id, skill_level) VALUES 
            (1, 1, 'できる'), (1, 2, 'まあまあできる'),
            (2, 1, 'まあまあできる'), (2, 2, 'できる'),
            (3, 1, 'できる'), (3, 2, 'できる'),
            (4, 1, 'まあまあできる'), (4, 2, 'できる'),
            (5, 1, 'できる'), (5, 2, 'まあまあできる')");
        
        // サンプル出勤可能情報を挿入
        $pdo->exec("INSERT IGNORE INTO availability (user_id, work_date, event_id, available, available_start_time, available_end_time, note) VALUES 
            (1, '2025-08-15', NULL, TRUE, '17:00:00', '22:00:00', '夜間のみ可能'),
            (2, '2025-08-15', NULL, TRUE, '10:00:00', '22:00:00', '一日中可能'),
            (3, '2025-08-15', NULL, TRUE, '16:00:00', '22:00:00', '夕方から可能'),
            (4, '2025-08-15', NULL, TRUE, '15:00:00', '22:00:00', '午後から可能'),
            (5, '2025-08-15', NULL, TRUE, '17:00:00', '22:00:00', '夜間可能'),
            (1, '2025-08-20', NULL, TRUE, '09:00:00', '18:00:00', '土曜日対応'),
            (2, '2025-08-20', NULL, TRUE, '10:00:00', '16:00:00', '昼間可能'),
            (4, '2025-08-20', NULL, TRUE, '09:00:00', '18:00:00', '週末対応')");
        
        $message = showAlert('success', 'サンプルデータを挿入しました。ページを再読み込みしてください。');
        
    } catch(Exception $e) {
        $message = showAlert('danger', 'サンプルデータ挿入エラー: ' . $e->getMessage());
    }
}

// シフト保存処理
if ($_POST['action'] ?? '' === 'save_shift') {
    try {
        $eventId = $_POST['event_id'];
        $assignments = $_POST['assignments'] ?? [];
        
        // 既存の割当を削除
        $stmt = $pdo->prepare("DELETE FROM assignments WHERE event_id = ?");
        $stmt->execute([$eventId]);
        
        // 新しい割当を保存
        $stmt = $pdo->prepare("INSERT INTO assignments (user_id, event_id, assigned_role, note) VALUES (?, ?, ?, ?)");
        
        foreach ($assignments as $role => $userIds) {
            foreach ($userIds as $userId) {
                $stmt->execute([$userId, $eventId, $role, '自動割当による']);
            }
        }
        
        $message = showAlert('success', 'シフトを保存しました。');
        
        // 保存後に割当結果を再取得
        $assignmentResult = getSavedAssignments($pdo, $eventId);
        
    } catch(Exception $e) {
        $message = showAlert('danger', '保存エラー: ' . $e->getMessage());
    }
}

// ランダム選択シフト保存処理
if ($_POST['action'] ?? '' === 'save_random_shift') {
    try {
        $eventId = $_POST['event_id'];
        $selectedStaff = json_decode($_POST['selected_staff'], true);
        
        if (!$eventId || empty($selectedStaff)) {
            throw new Exception('イベントIDまたは選択スタッフが不正です');
        }
        
        // 既存の割当を削除
        $stmt = $pdo->prepare("DELETE FROM assignments WHERE event_id = ?");
        $stmt->execute([$eventId]);
        
        // ランダム選択されたスタッフを保存
        $stmt = $pdo->prepare("INSERT INTO assignments (user_id, event_id, assigned_role, note) VALUES (?, ?, ?, ?)");
        
        foreach ($selectedStaff as $staff) {
            $role = $staff['is_rank'] === 'ランナー' ? 'ランナー' : 'その他';
            $stmt->execute([$staff['id'], $eventId, $role, 'ランダム選択による']);
        }
        
        $message = showAlert('success', count($selectedStaff) . '名のランダムシフトを保存しました。');
        $selectedEventId = $eventId;
        
        // 保存後に割当結果を再取得
        $assignmentResult = getSavedAssignments($pdo, $eventId);
        
    } catch(Exception $e) {
        $message = showAlert('danger', 'ランダムシフト保存エラー: ' . $e->getMessage());
    }
}

// 保存済みシフト読み込み処理
if ($_GET['load_saved'] ?? '' === '1' && $selectedEventId) {
    try {
        $assignmentResult = getSavedAssignments($pdo, $selectedEventId);
        if ($assignmentResult) {
            $message = showAlert('info', '保存済みシフトを読み込みました。');
        }
    } catch(Exception $e) {
        $message = showAlert('danger', 'シフト読み込みエラー: ' . $e->getMessage());
    }
}

// イベント一覧取得
$stmt = $pdo->query("SELECT id, event_date, start_time, end_time, event_type, description, needs, total_staff_required, light_count, parents_count FROM events ORDER BY event_date, start_time");
$events = $stmt->fetchAll();

// 選択されたイベント情報取得
$selectedEvent = null;
$hasSavedShift = false;
if ($selectedEventId) {
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$selectedEventId]);
    $selectedEvent = $stmt->fetch();
    
    // 保存済みシフトがあるかチェック
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE event_id = ?");
    $stmt->execute([$selectedEventId]);
    $hasSavedShift = $stmt->fetchColumn() > 0;
}

/**
 * 保存済みシフト情報を取得
 */
function getSavedAssignments($pdo, $eventId) {
    // イベント情報取得
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    
    if (!$event) {
        return null;
    }
    
    // 保存済み割当情報取得
    $stmt = $pdo->prepare("
        SELECT a.*, u.name, u.gender, u.is_rank, u.furigana
        FROM assignments a
        JOIN users u ON a.user_id = u.id
        WHERE a.event_id = ?
        ORDER BY a.assigned_role, u.furigana
    ");
    $stmt->execute([$eventId]);
    $savedAssignments = $stmt->fetchAll();
    
    if (empty($savedAssignments)) {
        return null;
    }
    
    // 必要人数解析
    $needs = parseNeeds($event['needs']);
    
    // 役割別に整理
    $assignments = [];
    foreach ($savedAssignments as $assignment) {
        $role = $assignment['assigned_role'];
        if (!isset($assignments[$role])) {
            $assignments[$role] = [];
        }
        
        // ユーザー情報とスキル情報を取得
        $stmt = $pdo->prepare("
            SELECT tt.name as task_name, s.skill_level
            FROM skills s
            JOIN task_types tt ON s.task_type_id = tt.id
            WHERE s.user_id = ?
        ");
        $stmt->execute([$assignment['user_id']]);
        $skills = $stmt->fetchAll();
        
        $userSkills = [];
        foreach ($skills as $skill) {
            $userSkills[$skill['task_name']] = $skill['skill_level'];
        }
        
        $assignments[$role][] = [
            'user' => [
                'id' => $assignment['user_id'],
                'name' => $assignment['name'],
                'gender' => $assignment['gender'],
                'is_rank' => $assignment['is_rank'],
                'furigana' => $assignment['furigana'],
                'skills' => $userSkills
            ],
            'skill_level' => $userSkills[$role] ?? 'できない'
        ];
    }
    
    return [
        'event' => $event,
        'needs' => $needs,
        'assignments' => $assignments,
        'is_saved' => true
    ];
}

// 統計情報取得
function getAssignmentStats($assignments) {
    $stats = [
        'total_assigned' => 0,
        'male_count' => 0,
        'female_count' => 0,
        'runner_count' => 0,
        'non_runner_count' => 0,
        'skill_distribution' => []
    ];
    
    foreach ($assignments as $role => $roleAssignments) {
        foreach ($roleAssignments as $assignment) {
            $user = $assignment['user'];
            $stats['total_assigned']++;
            
            if ($user['gender'] === 'M') $stats['male_count']++;
            else $stats['female_count']++;
            
            if ($user['is_rank'] === 'ランナー') $stats['runner_count']++;
            else $stats['non_runner_count']++;
            
            if (!isset($stats['skill_distribution'][$assignment['skill_level']])) {
                $stats['skill_distribution'][$assignment['skill_level']] = 0;
            }
            $stats['skill_distribution'][$assignment['skill_level']]++;
        }
    }
    
    return $stats;
}

// 不足統計計算
function calculateShortageStats($assignments, $event) {
    $stats = [
        'total_shortage' => 0,
        'details' => []
    ];
    
    if (!$event) return $stats;
    
    $assignedCount = 0;
    foreach ($assignments as $role => $roleAssignments) {
        $assignedCount += count($roleAssignments);
    }
    
    $requiredCount = (int)($event['total_staff_required'] ?? 0);
    
    // 基本的な人数不足/余剰
    $stats['total_shortage'] = $requiredCount - $assignedCount;
    
    // 婚礼の場合の詳細分析
    if ($event['event_type'] === '婚礼') {
        $lightRequired = (int)($event['light_count'] ?? 0);
        $parentsRequired = (int)($event['parents_count'] ?? 0);
        
        // ライト要員の確認（例：特定スキルを持つ人）
        $lightAssigned = 0;
        $parentsAssigned = 0; // 両親対応可能な人
        
        foreach ($assignments as $role => $roleAssignments) {
            foreach ($roleAssignments as $assignment) {
                $user = $assignment['user'];
                if (strpos($assignment['skill_level'], 'ライト') !== false) {
                    $lightAssigned++;
                }
                if (strpos($assignment['skill_level'], '接客') !== false || $user['is_rank'] === 'ランナー') {
                    $parentsAssigned++;
                }
            }
        }
        
        if ($lightRequired > 0) {
            $lightShortage = $lightRequired - $lightAssigned;
            if ($lightShortage > 0) {
                $stats['details'][] = "ライト要員 {$lightShortage}名不足";
            }
        }
        
        if ($parentsRequired > 0) {
            $parentsShortage = $parentsRequired - $parentsAssigned;
            if ($parentsShortage > 0) {
                $stats['details'][] = "両親対応 {$parentsShortage}名不足";
            }
        }
    }
    
    return $stats;
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>シフト作成 - シフト管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        @media print {
            .no-print { display: none !important; }
            .print-title { font-size: 1.5rem; margin-bottom: 1rem; }
        }
        
        /* 🆕 時間重複表示用のスタイル */
        .bg-light-success {
            background-color: rgba(25, 135, 84, 0.1) !important;
        }
        
        .bg-light-info {
            background-color: rgba(13, 202, 240, 0.1) !important;
        }
        
        .bg-light-warning {
            background-color: rgba(255, 193, 7, 0.1) !important;
        }
        
        .border-success {
            border-color: #198754 !important;
        }
        
        .border-info {
            border-color: #0dcaf0 !important;
        }
        
        .border-warning {
            border-color: #ffc107 !important;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary no-print">
        <div class="container">
            <a class="navbar-brand" href="../index.php">シフト管理システム</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="users.php">スタッフ管理</a>
                <a class="nav-link" href="events.php">イベント管理</a>
                <a class="nav-link" href="availability.php">出勤入力</a>
                <a class="nav-link active" href="shift_assignment.php">シフト作成</a>
                <a class="nav-link" href="saved_shifts.php">保存済みシフト</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <?= $message ?>
        
        <div class="row">
            <div class="col-md-4 no-print">
                <div class="card">
                    <div class="card-header">
                        <h5>📊 イベント選択</h5>
                    </div>
                    <div class="card-body">
                        <!-- デバッグ情報 -->
                        <?php
                        // データベース状況確認
                        $debugInfo = [];
                        try {
                            $stmt = $pdo->query("SELECT COUNT(*) as count FROM events");
                            $debugInfo['events'] = $stmt->fetch()['count'];
                            
                            $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
                            $debugInfo['users'] = $stmt->fetch()['count'];
                            
                            $stmt = $pdo->query("SELECT COUNT(*) as count FROM availability WHERE available = 1");
                            $debugInfo['availability'] = $stmt->fetch()['count'];
                            
                            $stmt = $pdo->query("SELECT COUNT(*) as count FROM skills");
                            $debugInfo['skills'] = $stmt->fetch()['count'];
                        } catch(Exception $e) {
                            $debugInfo['error'] = $e->getMessage();
                        }
                        ?>
                        
                        <div class="alert alert-info small mb-3">
                            <strong>📊 データベース状況:</strong><br>
                            イベント: <?= $debugInfo['events'] ?? 'エラー' ?>件<br>
                            スタッフ: <?= $debugInfo['users'] ?? 'エラー' ?>人<br>
                            出勤可能: <?= $debugInfo['availability'] ?? 'エラー' ?>件<br>
                            スキル: <?= $debugInfo['skills'] ?? 'エラー' ?>件<br>
                            <?php if (isset($debugInfo['error'])): ?>
                            <span class="text-danger">エラー: <?= $debugInfo['error'] ?></span>
                            <?php endif; ?>
                            
                            <?php if (($debugInfo['events'] ?? 0) == 0 || ($debugInfo['users'] ?? 0) == 0): ?>
                            <hr>
                            <div class="text-warning">
                                <strong>⚠️ サンプルデータが不足しています</strong><br>
                                <div class="btn-group-vertical d-grid gap-1 mt-2">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="init_sample_data">
                                        <button type="submit" class="btn btn-success btn-sm">
                                            ⚡ 簡易サンプルデータ挿入
                                        </button>
                                    </form>
                                    <a href="../database/init_web.php" class="btn btn-warning btn-sm" target="_blank">
                                        🔧 完全データベース初期化
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <form method="GET">
                            <div class="mb-3">
                                <label class="form-label">イベントを選択</label>
                                <select class="form-select" name="event_id" id="event_id" onchange="this.form.submit()">
                                    <option value="">選択してください</option>
                                    <?php foreach ($events as $event): ?>
                                    <option value="<?= $event['id'] ?>" 
                                            data-total-staff="<?= $event['total_staff_required'] ?? 0 ?>"
                                            <?= $selectedEventId == $event['id'] ? 'selected' : '' ?>>
                                        <?= formatDate($event['event_date']) ?> - <?= h($event['event_type']) ?>
                                        <?php if (!empty($event['total_staff_required'])): ?>
                                        (必要人数: <?= $event['total_staff_required'] ?>名・全体)
                                        <?php endif; ?>
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
                                <li><strong>総必要人数:</strong> <?= $selectedEvent['total_staff_required'] ? h($selectedEvent['total_staff_required']) . '名' : '未設定' ?></li>
                                <li><strong>説明:</strong> <?= h($selectedEvent['description']) ?></li>
                            </ul>
                        </div>
                        
                        <!-- 出勤可能スタッフ表示エリア -->
                        <div id="availableStaffArea" class="mt-3"></div>
                        
                        <!-- シフト作成ボタンエリア -->
                        <div class="mt-3">
                            <!-- ランダム選択ボタン -->
                            <button type="button" class="btn btn-primary w-100 mb-2" id="randomSelectBtn" onclick="randomSelectStaff()" disabled>
                                🎲 ランダム選択
                            </button>
                            <small class="text-muted d-block mb-3">※出勤可能スタッフからランダムで選択</small>
                            
                            <!-- 手動選択ボタン -->
                            <button type="button" class="btn btn-success w-100" id="manualSelectBtn" onclick="manualSelectStaff()" disabled>
                                ✅ 選択したスタッフでシフト作成
                            </button>
                            <small class="text-muted d-block mt-1">※チェックしたスタッフでシフトを作成</small>
                        </div>
                        
                        <?php if ($hasSavedShift && !$assignmentResult): ?>
                        <div class="mt-2">
                            <a href="?event_id=<?= $selectedEventId ?>&load_saved=1" class="btn btn-outline-info w-100">
                                📂 保存済みシフト読み込み
                            </a>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($assignmentResult): ?>
                        <div class="mt-3">
                            <div class="card border-primary">
                                <div class="card-header bg-primary text-white">
                                    <h6 class="mb-0">� シフト操作パネル</h6>
                                </div>
                                <div class="card-body p-3">
                                    <?php if (!($assignmentResult['is_saved'] ?? false)): ?>
                                    <!-- 保存ボタン - メイン操作 -->
                                    <div class="alert alert-warning text-center py-2 mb-3">
                                        <small><strong>⚠️ 未保存</strong><br>シフトを確定するには保存してください</small>
                                    </div>
                                    
                                    <form method="POST" id="saveShiftForm" onsubmit="return confirm('✅ このシフト内容で保存しますか？\n\n※既存の保存データは上書きされます。')">
                                        <input type="hidden" name="action" value="save_shift">
                                        <input type="hidden" name="event_id" value="<?= $selectedEventId ?>">
                                        
                                        <?php foreach ($assignmentResult['assignments'] as $role => $roleAssignments): ?>
                                            <?php foreach ($roleAssignments as $assignment): ?>
                                                <input type="hidden" name="assignments[<?= h($role) ?>][]" value="<?= $assignment['user']['id'] ?>">
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                        
                                        <button type="submit" class="btn btn-success btn-lg w-100 mb-3" id="saveShiftBtn">
                                            <i class="fas fa-save"></i> シフトを保存
                                        </button>
                                    </form>
                                    
                                    <hr>
                                    
                                    <!-- サブ操作 -->
                                    <div class="row">
                                        <div class="col-12">
                                            <button class="btn btn-outline-info w-100 btn-sm" onclick="window.print()">
                                                🖨️ 印刷
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <?php else: ?>
                                    <!-- 保存済みシフトの場合 -->
                                    <div class="alert alert-success text-center py-2 mb-3">
                                        <strong>✅ 保存済みシフト</strong><br>
                                        <small>このシフトは確定済みです</small>
                                    </div>
                                    
                                    <!-- サブ操作（保存済みの場合） -->
                                    <div class="row">
                                        <div class="col-12">
                                            <button class="btn btn-outline-info w-100 btn-sm" onclick="window.print()">
                                                🖨️ 印刷
                                            </button>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <?php if ($assignmentResult && !empty($assignmentResult['assignments'])): ?>
                <!-- シフト作成結果がある場合 -->
                <div class="print-title d-none d-print-block">
                    <h2><?= h($assignmentResult['event']['event_type']) ?> シフト表</h2>
                    <p><?= formatDate($assignmentResult['event']['event_date']) ?> 
                       <?= formatTime($assignmentResult['event']['start_time']) ?> - 
                       <?= formatTime($assignmentResult['event']['end_time']) ?></p>
                </div>
                
                <!-- 統計情報 -->
                <?php $stats = getAssignmentStats($assignmentResult['assignments']); ?>
                <div class="row mb-4 no-print">
                    <div class="col-md-2">
                        <div class="card text-center stat-card">
                            <div class="stat-number"><?= $stats['total_assigned'] ?></div>
                            <div class="stat-label">総割当人数</div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card text-center stat-card">
                            <div class="gender-ratio">
                                <span class="badge bg-primary">男性: <?= $stats['male_count'] ?></span>
                                <span class="badge bg-danger">女性: <?= $stats['female_count'] ?></span>
                            </div>
                            <div class="stat-label">性別比率</div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card text-center stat-card">
                            <div class="gender-ratio">
                                <span class="badge bg-info">ランナー: <?= $stats['runner_count'] ?></span>
                                <span class="badge bg-secondary">その他: <?= $stats['non_runner_count'] ?></span>
                            </div>
                            <div class="stat-label">ランク比率</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center stat-card">
                            <?php foreach ($stats['skill_distribution'] as $skill => $count): ?>
                            <div><?= getSkillBadge($skill) ?> <?= $count ?></div>
                            <?php endforeach; ?>
                            <div class="stat-label">スキル分布</div>
                        </div>
                    </div>
                    
                    <!-- 不足人数表示 -->
                    <?php if (isset($selectedEvent['total_staff_required']) && $selectedEvent['total_staff_required'] > 0): ?>
                    <div class="col-md-3">
                        <?php 
                        $shortageStats = calculateShortageStats($assignmentResult['assignments'], $selectedEvent);
                        $requiredCount = (int)$selectedEvent['total_staff_required'];
                        $assignedCount = $stats['total_assigned'];
                        ?>
                        <div class="card text-center stat-card">
                            <?php if ($shortageStats['total_shortage'] > 0): ?>
                                <div class="stat-number text-warning"><?= $shortageStats['total_shortage'] ?></div>
                                <div class="stat-label text-warning">名不足</div>
                                <small class="text-muted"><?= $requiredCount ?>名必要</small>
                                <?php if ($shortageStats['details']): ?>
                                    <?php foreach ($shortageStats['details'] as $detail): ?>
                                        <small class="d-block text-warning"><?= $detail ?></small>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php elseif ($shortageStats['total_shortage'] === 0): ?>
                                <div class="stat-number text-success">0</div>
                                <div class="stat-label text-success">過不足なし</div>
                                <small class="text-muted"><?= $requiredCount ?>名完了</small>
                            <?php else: ?>
                                <div class="stat-number text-info">+<?= abs($shortageStats['total_shortage']) ?></div>
                                <div class="stat-label text-info">名余裕</div>
                                <small class="text-muted"><?= $requiredCount ?>名必要</small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- 割当結果 -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5>🎯 シフト割当結果</h5>
                        <?php if ($assignmentResult['is_saved'] ?? false): ?>
                        <span class="badge bg-success">保存済み</span>
                        <?php else: ?>
                        <span class="badge bg-warning">未保存</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php foreach ($assignmentResult['assignments'] as $role => $roleAssignments): ?>
                        <div class="mb-4">
                            <h6 class="border-bottom pb-2">
                                <?= h($role) ?> 
                                <span class="badge bg-primary"><?= count($roleAssignments) ?>人</span>
                                <small class="text-muted">
                                    （必要: <?= $assignmentResult['needs'][$role]['display'] ?>）
                                </small>
                            </h6>
                            
                            <?php if (empty($roleAssignments)): ?>
                            <div class="alert alert-warning">
                                <strong>⚠️ 割当できませんでした</strong><br>
                                スキルを持つスタッフが出勤可能時間内にいません。
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th>スタッフ名</th>
                                            <th>性別</th>
                                            <th>ランク</th>
                                            <th>スキルレベル</th>
                                            <th>出勤可能時間</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($roleAssignments as $assignment): ?>
                                        <?php $user = $assignment['user']; ?>
                                        <tr>
                                            <td><strong><?= h($user['name']) ?></strong></td>
                                            <td><?= getGenderText($user['gender']) ?></td>
                                            <td><?= getRankBadge($user['is_rank']) ?></td>
                                            <td><?= getSkillBadge($assignment['skill_level']) ?></td>
                                            <td class="availability-time">
                                                <?= formatTime($user['available_start_time']) ?> - 
                                                <?= formatTime($user['available_end_time']) ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        
                        <!-- 出勤可能だが割当されなかったスタッフ -->
                        <?php
                        $assignedUserIds = [];
                        foreach ($assignmentResult['assignments'] as $roleAssignments) {
                            foreach ($roleAssignments as $assignment) {
                                $assignedUserIds[] = $assignment['user']['id'];
                            }
                        }
                        
                        $unassignedUsers = array_filter($assignmentResult['available_users'], function($user) use ($assignedUserIds) {
                            return !in_array($user['id'], $assignedUserIds);
                        });
                        ?>
                        
                        <?php if (!empty($unassignedUsers)): ?>
                        <div class="mt-4">
                            <h6 class="border-bottom pb-2">📋 出勤可能（未割当）スタッフ</h6>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead class="table-light">
                                        <tr>
                                            <th>スタッフ名</th>
                                            <th>ランク</th>
                                            <th>出勤可能時間</th>
                                            <th>備考</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($unassignedUsers as $user): ?>
                                        <tr>
                                            <td><?= h($user['name']) ?></td>
                                            <td><?= getRankBadge($user['is_rank']) ?></td>
                                            <td class="availability-time">
                                                <?= formatTime($user['available_start_time']) ?> - 
                                                <?= formatTime($user['available_end_time']) ?>
                                            </td>
                                            <td><small class="text-muted">予備要員として活用可能</small></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php elseif ($selectedEvent): ?>
                <!-- イベントが選択されているがシフトが作成されていない場合 -->
                <div class="card">
                    <div class="card-body text-center">
                        <h5>🎯 シフト作成</h5>
                        <p class="text-muted">左側の「ランダム選択」ボタンをクリックして、シフトを作成します。</p>
                        
                        <div class="alert alert-info">
                            <h6>✅ ランダム選択の特徴</h6>
                            <ul class="text-start mb-0">
                                <li>出勤可能時間がイベント時間と重複</li>
                                <li>ランナー・その他から選択可能</li>
                                <li>男女バランス考慮オプション</li>
                                <li>公平なランダム選択</li>
                            </ul>
                        </div>
                        
                        <!-- ランダム選択結果表示エリア -->
                        <div id="randomSelectionResult" style="display: none;" class="mt-4">
                            <div class="alert alert-success">
                                <h6 class="mb-3">🎲 ランダム選択されたスタッフ</h6>
                                <div id="selectedStaffList" class="row g-2"></div>
                                <div class="mt-3">
                                    <button type="button" class="btn btn-primary btn-sm me-2" onclick="randomSelectStaff()">
                                        🎲 再選択
                                    </button>
                                    <button type="button" class="btn btn-success btn-sm me-2" onclick="saveRandomShift()">
                                        💾 シフト保存
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="hideRandomResult()">
                                        ✖️ 結果を閉じる
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php else: ?>
                <div class="card">
                    <div class="card-body text-center">
                        <h5>イベントを選択してください</h5>
                        <p class="text-muted">左側からイベントを選択すると、シフト作成が可能になります。</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // 保存ボタンの処理
            const saveShiftForm = document.getElementById('saveShiftForm');
            const saveShiftBtn = document.getElementById('saveShiftBtn');
            
            if (saveShiftForm && saveShiftBtn) {
                saveShiftForm.addEventListener('submit', function(e) {
                    saveShiftBtn.disabled = true;
                    saveShiftBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 保存中...';
                    saveShiftBtn.classList.add('disabled');
                    
                    console.log('シフト保存を実行中...');
                });
            }
            
            // フォーム送信後のリセット（エラー時など）
            setTimeout(function() {
                const buttons = document.querySelectorAll('.disabled');
                buttons.forEach(function(btn) {
                    btn.disabled = false;
                    btn.classList.remove('disabled');
                    
                    if (btn.id === 'saveShiftBtn') {
                        btn.innerHTML = '<i class="fas fa-save"></i> シフトを保存';
                    }
                });
            }, 5000); // 5秒後にリセット
        });
    </script>
    
    <script>
        // イベントデータをJavaScriptで利用可能にする
        const eventsData = <?= json_encode($events) ?>;
        
        // 🆕 出勤可能スタッフ表示機能
        document.addEventListener('DOMContentLoaded', function() {
            const eventSelect = document.querySelector('select[name="event_id"]');
            
            // ページ読み込み時に既に選択されている場合
            if (eventSelect && eventSelect.value) {
                loadAvailableStaff(eventSelect.value);
            }
            
            // イベント選択変更時
            if (eventSelect) {
                eventSelect.addEventListener('change', function() {
                    if (this.value) {
                        loadAvailableStaff(this.value);
                    } else {
                        document.getElementById('availableStaffArea').innerHTML = '';
                    }
                });
            }
        });
        
        function loadAvailableStaff(eventId) {
            const staffArea = document.getElementById('availableStaffArea');
            
            // ローディング表示
            staffArea.innerHTML = `
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">👥 出勤可能スタッフ</h6>
                    </div>
                    <div class="card-body text-center">
                        <div class="spinner-border spinner-border-sm" role="status"></div>
                        <span class="ms-2">読み込み中...</span>
                    </div>
                </div>
            `;
            
            // API呼び出し
            fetch(`get_available_staff.php?event_id=${eventId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayAvailableStaff(data);
                    } else {
                        showStaffError(data.error);
                    }
                })
                .catch(error => {
                    console.error('Error loading available staff:', error);
                    showStaffError('データの取得に失敗しました');
                });
        }
        
        function displayAvailableStaff(data) {
            const staffArea = document.getElementById('availableStaffArea');
            const eventDate = new Date(data.event.event_date).toLocaleDateString('ja-JP', {
                month: 'numeric',
                day: 'numeric',
                weekday: 'short'
            });
            
            // 🆕 グローバル変数に出勤可能スタッフデータを保存
            currentAvailableStaff = data.available_staff;
            
            if (data.stats.total_available === 0) {
                // データがない場合は空の配列に設定
                currentAvailableStaff = [];
                
                staffArea.innerHTML = `
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">👥 出勤可能スタッフ (0名) - ${eventDate}</h6>
                        </div>
                        <div class="card-body text-center text-muted py-4">
                            <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                            <p class="mb-1">この日に出勤可能なスタッフがいません</p>
                            <small>出勤入力ページで出勤予定を入力してください</small>
                        </div>
                    </div>
                `;
                return;
            }
            
            let html = `
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">👥 出勤可能スタッフ (${data.stats.total_available}名) - ${eventDate}</h6>
                            <div class="d-flex align-items-center gap-2">
                                <small class="text-muted">♂${data.stats.male_count} ♀${data.stats.female_count}</small>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="selectAllStaff()">全選択</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="deselectAllStaff()">全解除</button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-3">
                        <div class="mb-3">
                            <div class="d-flex align-items-center gap-3 small">
                                <div class="text-muted">宴会時間: ${data.event.start_time ? data.event.start_time.substr(0, 5) : '未設定'} - ${data.event.end_time ? data.event.end_time.substr(0, 5) : '未設定'}</div>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-check-circle text-success me-1"></i>
                                        <span class="small">完全重複</span>
                                    </div>
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-info-circle text-info me-1"></i>
                                        <span class="small">一部重複</span>
                                    </div>
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-exclamation-triangle text-warning me-1"></i>
                                        <span class="small">重複なし</span>
                                    </div>
                                </div>
                            </div>
                        </div>
            `;
            
            // ランナー表示
            if (data.runners.length > 0) {
                html += `
                    <div class="mb-3">
                        <div class="fw-bold small text-primary mb-2">
                            <i class="fas fa-star"></i> ランナー (${data.runners.length}名)
                        </div>
                        <div class="row g-2">
                `;
                
                data.runners.forEach(staff => {
                    const genderBadge = staff.gender === 'M' ? '♂' : '♀';
                    const timeDisplay = staff.available_start_time && staff.available_end_time ?
                        `${staff.available_start_time.substr(0, 5)} - ${staff.available_end_time.substr(0, 5)}` : '時間未設定';
                    
                    // 🆕 宴会時間との重複チェック（3段階）
                    let overlapClass = '';
                    let overlapIcon = '';
                    if (data.event.start_time && data.event.end_time && 
                        staff.available_start_time && staff.available_end_time) {
                        const overlapResult = checkTimeOverlap(
                            data.event.start_time, data.event.end_time,
                            staff.available_start_time, staff.available_end_time
                        );
                        
                        if (overlapResult.type === 'complete') {
                            // 完全重複（出勤時間が宴会時間を完全にカバー）
                            overlapClass = 'border-success bg-light-success';
                            overlapIcon = '<i class="fas fa-check-circle text-success me-1" title="完全重複：宴会時間を完全にカバー"></i>';
                        } else if (overlapResult.type === 'partial') {
                            // 一部重複
                            overlapClass = 'border-info bg-light-info';
                            overlapIcon = '<i class="fas fa-info-circle text-info me-1" title="一部重複：宴会時間と一部重複"></i>';
                        } else {
                            // 重複なし
                            overlapClass = 'border-warning bg-light-warning';
                            overlapIcon = '<i class="fas fa-exclamation-triangle text-warning me-1" title="重複なし：時間調整が必要"></i>';
                        }
                    }
                    
                    html += `
                        <div class="col-md-6">
                            <div class="border rounded p-2 ${overlapClass}">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center">
                                            <input type="checkbox" class="form-check-input me-2 staff-checkbox" 
                                                   value="${staff.id}" data-name="${staff.name}" data-category="runner">
                                            <div>
                                                <div class="fw-bold small">${overlapIcon}${staff.name}</div>
                                                <div class="text-muted" style="font-size: 0.75rem;">${timeDisplay}</div>
                                            </div>
                                        </div>
                                    </div>
                                    <span class="badge bg-light text-dark">${genderBadge}</span>
                                </div>
                            </div>
                        </div>
                    `;
                });
                
                html += '</div></div>';
            }
            
            // その他のスタッフ表示
            if (data.non_runners.length > 0) {
                html += `
                    <div class="mb-3">
                        <div class="fw-bold small text-secondary mb-2">
                            <i class="fas fa-users"></i> その他 (${data.non_runners.length}名)
                        </div>
                        <div class="row g-2">
                `;
                
                data.non_runners.forEach(staff => {
                    const genderBadge = staff.gender === 'M' ? '♂' : '♀';
                    const timeDisplay = staff.available_start_time && staff.available_end_time ?
                        `${staff.available_start_time.substr(0, 5)} - ${staff.available_end_time.substr(0, 5)}` : '時間未設定';
                    
                    // 🆕 宴会時間との重複チェック（3段階）
                    let overlapClass = '';
                    let overlapIcon = '';
                    if (data.event.start_time && data.event.end_time && 
                        staff.available_start_time && staff.available_end_time) {
                        const overlapResult = checkTimeOverlap(
                            data.event.start_time, data.event.end_time,
                            staff.available_start_time, staff.available_end_time
                        );
                        
                        if (overlapResult.type === 'complete') {
                            // 完全重複（出勤時間が宴会時間を完全にカバー）
                            overlapClass = 'border-success bg-light-success';
                            overlapIcon = '<i class="fas fa-check-circle text-success me-1" title="完全重複：宴会時間を完全にカバー"></i>';
                        } else if (overlapResult.type === 'partial') {
                            // 一部重複
                            overlapClass = 'border-info bg-light-info';
                            overlapIcon = '<i class="fas fa-info-circle text-info me-1" title="一部重複：宴会時間と一部重複"></i>';
                        } else {
                            // 重複なし
                            overlapClass = 'border-warning bg-light-warning';
                            overlapIcon = '<i class="fas fa-exclamation-triangle text-warning me-1" title="重複なし：時間調整が必要"></i>';
                        }
                    }
                    
                    html += `
                        <div class="col-md-6">
                            <div class="border rounded p-2 ${overlapClass}">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center">
                                            <input type="checkbox" class="form-check-input me-2 staff-checkbox" 
                                                   value="${staff.id}" data-name="${staff.name}" data-category="other">
                                            <div>
                                                <div class="fw-bold small">${overlapIcon}${staff.name}</div>
                                                <div class="text-muted" style="font-size: 0.75rem;">${timeDisplay}</div>
                                            </div>
                                        </div>
                                    </div>
                                    <span class="badge bg-light text-dark">${genderBadge}</span>
                                </div>
                            </div>
                        </div>
                    `;
                });
                
                html += '</div></div>';
            }
            
            html += '</div></div>';
            
            // 不足人数の計算と表示
            const eventSelect = document.getElementById('event_id');
            if (eventSelect && eventSelect.value) {
                const selectedOption = eventSelect.options[eventSelect.selectedIndex];
                const totalStaffRequired = selectedOption.getAttribute('data-total-staff');
                
                if (totalStaffRequired && !isNaN(totalStaffRequired) && totalStaffRequired > 0) {
                    const requiredCount = parseInt(totalStaffRequired);
                    const availableCount = data.stats.total_available;
                    
                    if (requiredCount > availableCount) {
                        const shortage = requiredCount - availableCount;
                        html += `
                            <div class="alert alert-warning mt-3 mb-0">
                                <i class="fas fa-exclamation-triangle"></i> 
                                <strong>人数不足:</strong> ${shortage}名不足しています 
                                <small class="text-muted">(必要: ${requiredCount}名 / 利用可能: ${availableCount}名)</small>
                            </div>
                        `;
                    } else if (requiredCount === availableCount) {
                        html += `
                            <div class="alert alert-info mt-3 mb-0">
                                <i class="fas fa-info-circle"></i> 
                                必要人数と利用可能人数が一致しています 
                                <small class="text-muted">(${requiredCount}名)</small>
                            </div>
                        `;
                    } else {
                        const surplus = availableCount - requiredCount;
                        html += `
                            <div class="alert alert-success mt-3 mb-0">
                                <i class="fas fa-check-circle"></i> 
                                十分な人数が確保されています 
                                <small class="text-muted">(必要: ${requiredCount}名 / 利用可能: ${availableCount}名 / 余裕: ${surplus}名)</small>
                            </div>
                        `;
                    }
                }
            }
            
            html += '</div>';
            staffArea.innerHTML = html;
            
            // 🆕 ボタンを有効化し、チェックボックスイベントを設定
            const randomBtn = document.getElementById('randomSelectBtn');
            const manualBtn = document.getElementById('manualSelectBtn');
            if (randomBtn) {
                randomBtn.disabled = false;
            }
            if (manualBtn) {
                manualBtn.disabled = true; // 初期状態では無効
            }
            
            // チェックボックスの変更イベントを設定
            updateManualSelectionState();
            document.addEventListener('change', function(e) {
                if (e.target.classList.contains('staff-checkbox')) {
                    updateManualSelectionState();
                }
            });
        }
        
        function showStaffError(message) {
            // エラー時は空の配列に設定
            currentAvailableStaff = [];
            
            const staffArea = document.getElementById('availableStaffArea');
            staffArea.innerHTML = `
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">👥 出勤可能スタッフ</h6>
                    </div>
                    <div class="card-body text-center text-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <span class="ms-2">${message}</span>
                    </div>
                </div>
            `;
            
            // ランダム選択ボタンを無効化
            const randomBtn = document.getElementById('randomSelectBtn');
            if (randomBtn) {
                randomBtn.disabled = true;
            }
        }
        
        // 🆕 グローバル変数として出勤可能スタッフデータを保存
        let currentAvailableStaff = [];
        
        // 🆕 時間重複チェック関数（重複の種類も判定）
        function checkTimeOverlap(eventStart, eventEnd, availableStart, availableEnd) {
            // 時間文字列をDateオブジェクトに変換（同じ日付で比較）
            const baseDate = '2024-01-01 ';
            const eventStartTime = new Date(baseDate + eventStart);
            const eventEndTime = new Date(baseDate + eventEnd);
            const availableStartTime = new Date(baseDate + availableStart);
            const availableEndTime = new Date(baseDate + availableEnd);
            
            // 重複なし
            if (eventEndTime <= availableStartTime || eventStartTime >= availableEndTime) {
                return { hasOverlap: false, type: 'none' };
            }
            
            // 完全に含む（出勤時間が宴会時間を完全にカバー）
            if (availableStartTime <= eventStartTime && availableEndTime >= eventEndTime) {
                return { hasOverlap: true, type: 'complete' };
            }
            
            // 一部重複
            return { hasOverlap: true, type: 'partial' };
        }
        
        // 🆕 宴会時間と重複するスタッフをフィルタリング
        function filterStaffByEventTime(staff, eventStartTime, eventEndTime) {
            return staff.filter(person => {
                if (!person.available_start_time || !person.available_end_time) {
                    return false; // 時間情報がない場合は除外
                }
                
                const overlapResult = checkTimeOverlap(
                    eventStartTime,
                    eventEndTime,
                    person.available_start_time,
                    person.available_end_time
                );
                
                return overlapResult.hasOverlap;
            });
        }
        
        // 🆕 ランダム選択機能
        function randomSelectStaff() {
            if (currentAvailableStaff.length === 0) {
                alert('出勤可能スタッフのデータがありません');
                return;
            }
            
            // 選択オプションのモーダルを表示
            showRandomSelectionModal();
        }
        
        function showRandomSelectionModal() {
            // 現在選択されているイベント情報を取得
            const eventSelect = document.getElementById('event_id');
            let selectedEvent = null;
            let defaultStaffCount = 3; // デフォルト値
            
            if (eventSelect && eventSelect.value) {
                selectedEvent = eventsData.find(e => e.id == eventSelect.value);
                const selectedOption = eventSelect.options[eventSelect.selectedIndex];
                const totalStaffRequired = selectedOption.getAttribute('data-total-staff');
                if (totalStaffRequired && !isNaN(totalStaffRequired) && totalStaffRequired > 0) {
                    defaultStaffCount = Math.min(parseInt(totalStaffRequired), currentAvailableStaff.length);
                }
            }
            
            // 🆕 宴会時間と重複するスタッフのみに絞り込み
            let timeFilteredStaff = currentAvailableStaff;
            let excludedByTime = 0;
            
            if (selectedEvent && selectedEvent.start_time && selectedEvent.end_time) {
                const originalCount = currentAvailableStaff.length;
                timeFilteredStaff = filterStaffByEventTime(
                    currentAvailableStaff, 
                    selectedEvent.start_time, 
                    selectedEvent.end_time
                );
                excludedByTime = originalCount - timeFilteredStaff.length;
                
                console.log('時間フィルタリング結果:');
                console.log('- 宴会時間:', selectedEvent.start_time + ' - ' + selectedEvent.end_time);
                console.log('- 元の候補者数:', originalCount);
                console.log('- 時間除外者数:', excludedByTime);
                console.log('- フィルタ後候補者数:', timeFilteredStaff.length);
            }
            
            const runnerCount = timeFilteredStaff.filter(s => s.is_rank === 'ランナー').length;
            
            // コースランナーとビュッフェランナーの数を計算（時間フィルタ後）
            const courseRunners = timeFilteredStaff.filter(s => 
                s.is_rank === 'ランナー' && 
                s.skills.some(skill => skill.task_name === 'コースランナー')
            ).length;
            const buffetRunners = timeFilteredStaff.filter(s => 
                s.is_rank === 'ランナー' && 
                s.skills.some(skill => skill.task_name === 'ビュッフェランナー')
            ).length;
            
            // 🆕 婚礼用：ライトと両親のスタッフ数を計算（時間フィルタ後）
            const lightStaff = timeFilteredStaff.filter(s => 
                s.skills.some(skill => skill.task_name === 'ライト')
            ).length;
            const parentsStaff = timeFilteredStaff.filter(s => 
                s.skills.some(skill => skill.task_name === '両親')
            ).length;
            
            // その他（ランナー以外）の数を正確に計算
            // 🆕 改善: その他にはランナーも含めるが、選択されているランナーは除外
            const allStaff = timeFilteredStaff.length;
            
            // その他の候補数は全スタッフ数（実際の選択時に動的に調整）
            const otherCandidatesCount = allStaff;
            
            // イベント種別に応じてランナーカテゴリを制限
            let showCourseRunner = true;
            let showBuffetRunner = true;
            let showLight = false;
            let showParents = false;
            let categoryMessage = '';
            
            if (selectedEvent) {
                const eventType = selectedEvent.event_type;
                if (eventType === 'コース') {
                    showBuffetRunner = false;
                    categoryMessage = '<div class="alert alert-info"><i class="fas fa-info-circle"></i> コースイベントのため、コースランナーのみ選択可能です</div>';
                } else if (eventType === 'ビュッフェ') {
                    showCourseRunner = false;
                    categoryMessage = '<div class="alert alert-info"><i class="fas fa-info-circle"></i> ビュッフェイベントのため、ビュッフェランナーのみ選択可能です</div>';
                } else if (eventType === '婚礼') {
                    showBuffetRunner = false;
                    showLight = true;
                    showParents = true;
                    categoryMessage = '<div class="alert alert-info"><i class="fas fa-heart text-danger"></i> 婚礼イベントのため、コースランナー・ライト・両親対応スタッフを選択できます</div>';
                }
            }
            
            // デフォルト値の計算（イベント種別に応じて調整）
            let defaultCourseRunner = 0;
            let defaultBuffetRunner = 0;
            let defaultLight = 0;
            let defaultParents = 0;
            let defaultOther = Math.min(Math.ceil(defaultStaffCount * 0.4), otherCandidatesCount);
            
            if (showCourseRunner && showBuffetRunner) {
                // 両方表示する場合（その他のイベント種別）
                defaultCourseRunner = Math.min(Math.ceil(defaultStaffCount * 0.3), courseRunners);
                defaultBuffetRunner = Math.min(Math.ceil(defaultStaffCount * 0.3), buffetRunners);
            } else if (showCourseRunner) {
                if (showLight && showParents) {
                    // 婚礼の場合
                    defaultCourseRunner = Math.min(Math.ceil(defaultStaffCount * 0.4), courseRunners);
                    
                    // イベント設定から必要数を取得
                    if (selectedEvent.light_count) {
                        defaultLight = Math.min(parseInt(selectedEvent.light_count), lightStaff);
                    } else {
                        defaultLight = Math.min(Math.ceil(defaultStaffCount * 0.2), lightStaff);
                    }
                    
                    if (selectedEvent.parents_count) {
                        defaultParents = Math.min(parseInt(selectedEvent.parents_count), parentsStaff);
                    } else {
                        defaultParents = Math.min(Math.ceil(defaultStaffCount * 0.2), parentsStaff);
                    }
                    
                    defaultOther = Math.min(Math.ceil(defaultStaffCount * 0.2), otherCandidatesCount);
                } else {
                    // コースランナーのみ（コース）
                    defaultCourseRunner = Math.min(Math.ceil(defaultStaffCount * 0.6), courseRunners);
                }
            } else if (showBuffetRunner) {
                // ビュッフェランナーのみ（ビュッフェ）
                defaultBuffetRunner = Math.min(Math.ceil(defaultStaffCount * 0.6), buffetRunners);
            }
            
            const modalHtml = `
                <div class="modal fade" id="randomSelectionModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">🎲 ランダム選択設定</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                ${categoryMessage}
                                
                                ${excludedByTime > 0 ? `
                                <div class="alert alert-warning">
                                    <i class="fas fa-clock"></i> <strong>時間フィルタリング</strong><br>
                                    宴会時間 (${selectedEvent.start_time.substr(0,5)} - ${selectedEvent.end_time.substr(0,5)}) と重複しないため、
                                    ${excludedByTime}名が候補から除外されました。
                                </div>
                                ` : ''}
                                
                                <div id="categorySelection">
                                    <div class="row">
                                        ${showCourseRunner ? `
                                        <div class="col-lg-4 col-md-6">
                                            <div class="mb-3">
                                                <label for="courseRunnerCount" class="form-label">
                                                    <i class="fas fa-running text-primary"></i> コースランナー
                                                </label>
                                                <input type="number" class="form-control" id="courseRunnerCount" min="0" max="${courseRunners}" value="${defaultCourseRunner}">
                                            </div>
                                        </div>
                                        ` : ''}
                                        ${showBuffetRunner ? `
                                        <div class="col-lg-4 col-md-6">
                                            <div class="mb-3">
                                                <label for="buffetRunnerCount" class="form-label">
                                                    <i class="fas fa-utensils text-warning"></i> ビュッフェランナー
                                                </label>
                                                <input type="number" class="form-control" id="buffetRunnerCount" min="0" max="${buffetRunners}" value="${defaultBuffetRunner}">
                                            </div>
                                        </div>
                                        ` : ''}
                                        ${showLight ? `
                                        <div class="col-lg-4 col-md-6">
                                            <div class="mb-3">
                                                <label for="lightCount" class="form-label">
                                                    <i class="fas fa-lightbulb text-info"></i> ライト要員
                                                </label>
                                                <input type="number" class="form-control" id="lightCount" min="0" max="${lightStaff}" value="${defaultLight}">
                                                <small class="form-text text-muted">候補: ${lightStaff}名</small>
                                            </div>
                                        </div>
                                        ` : ''}
                                        ${showParents ? `
                                        <div class="col-lg-4 col-md-6">
                                            <div class="mb-3">
                                                <label for="parentsCount" class="form-label">
                                                    <i class="fas fa-users-cog text-secondary"></i> 両親対応
                                                </label>
                                                <input type="number" class="form-control" id="parentsCount" min="0" max="${parentsStaff}" value="${defaultParents}">
                                                <small class="form-text text-muted">候補: ${parentsStaff}名</small>
                                            </div>
                                        </div>
                                        ` : ''}
                                        <div class="col-lg-4 col-md-12">
                                            <div class="mb-3">
                                                <label for="otherCount" class="form-label">
                                                    <i class="fas fa-users text-success"></i> その他 <small class="text-muted">(全スタッフ)</small>
                                                </label>
                                                <input type="number" class="form-control" id="otherCount" min="0" max="${otherCandidatesCount}" value="${defaultOther}">
                                                <small class="form-text text-muted">
                                                    選択済みランナーを除く全スタッフから選択<br>
                                                    <span class="text-info">候補: ${allStaff}名 (ランナー ${runnerCount}名含む)</span>
                                                    ${excludedByTime > 0 ? `<br><span class="text-warning">時間除外: ${excludedByTime}名</span>` : ''}
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="balanceGender">
                                    <label class="form-check-label" for="balanceGender">
                                        男女バランスを考慮
                                    </label>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                                <button type="button" class="btn btn-primary" onclick="executeRandomSelection()">🎲 選択実行</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // 既存のモーダルがあれば削除
            const existingModal = document.getElementById('randomSelectionModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // モーダルをDOMに追加
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            
            // モーダル表示
            const modal = new bootstrap.Modal(document.getElementById('randomSelectionModal'));
            modal.show();
        }
        
        function executeRandomSelection() {
            const balanceGender = document.getElementById('balanceGender').checked;
            
            // フィールドが存在する場合のみ値を取得、存在しない場合は0
            const courseRunnerCountEl = document.getElementById('courseRunnerCount');
            const buffetRunnerCountEl = document.getElementById('buffetRunnerCount');
            const lightCountEl = document.getElementById('lightCount');
            const parentsCountEl = document.getElementById('parentsCount');
            const otherCountEl = document.getElementById('otherCount');
            
            const courseRunnerCount = courseRunnerCountEl ? parseInt(courseRunnerCountEl.value) || 0 : 0;
            const buffetRunnerCount = buffetRunnerCountEl ? parseInt(buffetRunnerCountEl.value) || 0 : 0;
            const lightCount = lightCountEl ? parseInt(lightCountEl.value) || 0 : 0;
            const parentsCount = parentsCountEl ? parseInt(parentsCountEl.value) || 0 : 0;
            const otherCount = otherCountEl ? parseInt(otherCountEl.value) || 0 : 0;
            
            if (courseRunnerCount + buffetRunnerCount + lightCount + parentsCount + otherCount === 0) {
                alert('最低1名は選択してください');
                return;
            }
            
            // 🆕 時間フィルタリングを適用
            const eventSelect = document.getElementById('event_id');
            let timeFilteredStaff = currentAvailableStaff;
            
            if (eventSelect && eventSelect.value) {
                const selectedEvent = eventsData.find(e => e.id == eventSelect.value);
                if (selectedEvent && selectedEvent.start_time && selectedEvent.end_time) {
                    timeFilteredStaff = filterStaffByEventTime(
                        currentAvailableStaff, 
                        selectedEvent.start_time, 
                        selectedEvent.end_time
                    );
                }
            }
            
            // 各カテゴリのスタッフを分類（時間フィルタリング後）
            const courseRunners = timeFilteredStaff.filter(s => 
                s.is_rank === 'ランナー' && 
                s.skills.some(skill => skill.task_name === 'コースランナー')
            );
            const buffetRunners = timeFilteredStaff.filter(s => 
                s.is_rank === 'ランナー' && 
                s.skills.some(skill => skill.task_name === 'ビュッフェランナー')
            );
            const lightStaffList = timeFilteredStaff.filter(s => 
                s.skills.some(skill => skill.task_name === 'ライト')
            );
            const parentsStaffList = timeFilteredStaff.filter(s => 
                s.skills.some(skill => skill.task_name === '両親')
            );
            
            // 🆕 改善: その他は全スタッフから選択済みランナーを除外
            let selectedRunners = [];
            
            // まずランナーを選択
            if (balanceGender) {
                selectedRunners = [
                    ...selectWithGenderBalance(courseRunners, Math.min(courseRunnerCount, courseRunners.length)).map(s => ({...s, selectionCategory: 'courseRunner'})),
                    ...selectWithGenderBalance(buffetRunners, Math.min(buffetRunnerCount, buffetRunners.length)).map(s => ({...s, selectionCategory: 'buffetRunner'})),
                    ...selectWithGenderBalance(lightStaffList, Math.min(lightCount, lightStaffList.length)).map(s => ({...s, selectionCategory: 'light'})),
                    ...selectWithGenderBalance(parentsStaffList, Math.min(parentsCount, parentsStaffList.length)).map(s => ({...s, selectionCategory: 'parents'}))
                ];
            } else {
                selectedRunners = [
                    ...courseRunners.sort(() => 0.5 - Math.random()).slice(0, Math.min(courseRunnerCount, courseRunners.length)).map(s => ({...s, selectionCategory: 'courseRunner'})),
                    ...buffetRunners.sort(() => 0.5 - Math.random()).slice(0, Math.min(buffetRunnerCount, buffetRunners.length)).map(s => ({...s, selectionCategory: 'buffetRunner'})),
                    ...lightStaffList.sort(() => 0.5 - Math.random()).slice(0, Math.min(lightCount, lightStaffList.length)).map(s => ({...s, selectionCategory: 'light'})),
                    ...parentsStaffList.sort(() => 0.5 - Math.random()).slice(0, Math.min(parentsCount, parentsStaffList.length)).map(s => ({...s, selectionCategory: 'parents'}))
                ];
            }
            
            // その他の候補から選択済みランナーを除外（時間フィルタリング後）
            const otherCandidates = timeFilteredStaff.filter(s => 
                !selectedRunners.some(selected => selected.id === s.id)
            );
            
            // デバッグ情報をコンソールに出力
            console.log('ランダム選択デバッグ情報:');
            console.log('全スタッフ数:', currentAvailableStaff.length);
            console.log('時間フィルタリング後スタッフ数:', timeFilteredStaff.length);
            console.log('選択されたランナー数:', selectedRunners.length);
            console.log('その他候補数:', otherCandidates.length);
            console.log('その他候補内のランナー数:', otherCandidates.filter(s => s.is_rank === 'ランナー').length);
            
            // 選択可能数のチェックと不足人数の計算
            let shortageMessages = [];
            let actualCourseRunnerCount = Math.min(courseRunnerCount, courseRunners.length);
            let actualBuffetRunnerCount = Math.min(buffetRunnerCount, buffetRunners.length);
            let actualLightCount = Math.min(lightCount, lightStaffList.length);
            let actualParentsCount = Math.min(parentsCount, parentsStaffList.length);
            let actualOtherCount = Math.min(otherCount, otherCandidates.length);
            
            if (courseRunnerCount > courseRunners.length) {
                const shortage = courseRunnerCount - courseRunners.length;
                shortageMessages.push(`コースランナー: ${shortage}名不足（${courseRunners.length}名のみ選択）`);
            }
            
            if (buffetRunnerCount > buffetRunners.length) {
                const shortage = buffetRunnerCount - buffetRunners.length;
                shortageMessages.push(`ビュッフェランナー: ${shortage}名不足（${buffetRunners.length}名のみ選択）`);
            }
            
            if (lightCount > lightStaffList.length) {
                const shortage = lightCount - lightStaffList.length;
                shortageMessages.push(`ライト要員: ${shortage}名不足（${lightStaffList.length}名のみ選択）`);
            }
            
            if (parentsCount > parentsStaffList.length) {
                const shortage = parentsCount - parentsStaffList.length;
                shortageMessages.push(`両親対応: ${shortage}名不足（${parentsStaffList.length}名のみ選択）`);
            }
            
            if (otherCount > otherCandidates.length) {
                const shortage = otherCount - otherCandidates.length;
                shortageMessages.push(`その他: ${shortage}名不足（${otherCandidates.length}名のみ選択）`);
            }
            
            // 不足がある場合は警告メッセージを表示
            if (shortageMessages.length > 0) {
                const message = `出勤可能な人数が不足しています：\n${shortageMessages.join('\n')}\n\n利用可能な全員を選択して続行しますか？`;
                if (!confirm(message)) {
                    return;
                }
            }
            
            // その他の候補から選択
            let selectedOthers = [];
            if (balanceGender) {
                selectedOthers = selectWithGenderBalance(otherCandidates, actualOtherCount).map(s => ({...s, selectionCategory: 'other'}));
            } else {
                selectedOthers = otherCandidates.sort(() => 0.5 - Math.random()).slice(0, actualOtherCount).map(s => ({...s, selectionCategory: 'other'}));
            }
            
            // 最終的な選択結果
            const selectedStaff = [...selectedRunners, ...selectedOthers];
            
            // モーダルを閉じる
            const modal = bootstrap.Modal.getInstance(document.getElementById('randomSelectionModal'));
            modal.hide();
            
            // 結果を表示（不足メッセージも含む）
            const selectionDetails = {
                totalAvailable: currentAvailableStaff.length,
                timeFiltered: timeFilteredStaff.length,
                selectedRunners: selectedRunners.filter(s => s.selectionCategory === 'courseRunner' || s.selectionCategory === 'buffetRunner').length,
                selectedLight: selectedRunners.filter(s => s.selectionCategory === 'light').length,
                selectedParents: selectedRunners.filter(s => s.selectionCategory === 'parents').length,
                selectedOthers: selectedOthers.length,
                otherCandidates: otherCandidates.length,
                runnersInOthers: selectedOthers.filter(s => s.is_rank === 'ランナー').length
            };
            showRandomSelectionResult(selectedStaff, currentAvailableStaff.length, shortageMessages, selectionDetails);
        }
        
        function selectWithGenderBalance(staff, count) {
            const males = staff.filter(s => s.gender === 'M');
            const females = staff.filter(s => s.gender === 'F');
            
            const maleRatio = males.length / staff.length;
            const targetMales = Math.round(count * maleRatio);
            const targetFemales = count - targetMales;
            
            const selectedMales = males.sort(() => 0.5 - Math.random()).slice(0, Math.min(targetMales, males.length));
            const selectedFemales = females.sort(() => 0.5 - Math.random()).slice(0, Math.min(targetFemales, females.length));
            
            let selected = [...selectedMales, ...selectedFemales];
            
            // 不足分は残りから補完
            if (selected.length < count) {
                const remaining = staff.filter(s => !selected.includes(s));
                const additional = remaining.sort(() => 0.5 - Math.random()).slice(0, count - selected.length);
                selected = [...selected, ...additional];
            }
            
            return selected.sort(() => 0.5 - Math.random());
        }
        
        function showRandomSelectionResult(selectedStaff, totalCount, shortageMessages = [], selectionDetails = null) {
            // 🆕 選択されたスタッフデータをグローバル変数に保存
            currentSelectedStaff = selectedStaff;
            
            // メインの結果表示エリアを表示
            const resultArea = document.getElementById('randomSelectionResult');
            const selectedStaffList = document.getElementById('selectedStaffList');
            
            // 不足メッセージがある場合は表示
            let shortageWarning = '';
            if (shortageMessages.length > 0) {
                shortageWarning = `
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> <strong>人数不足のお知らせ</strong><br>
                        ${shortageMessages.map(msg => `• ${msg}`).join('<br>')}
                    </div>
                `;
            }
            
            // 選択詳細情報の表示
            let detailsInfo = '';
            if (selectionDetails) {
                if (selectionDetails.manualSelection) {
                    // 手動選択の場合
                    detailsInfo = `
                        <div class="alert alert-success">
                            <i class="fas fa-hand-pointer"></i> <strong>手動選択</strong><br>
                            • 利用可能スタッフ: ${selectionDetails.totalAvailable}名<br>
                            • 手動選択: ${selectionDetails.selectedStaff}名
                        </div>
                    `;
                } else if (selectionDetails.editedSelection) {
                    // 編集済み選択の場合
                    detailsInfo = `
                        <div class="alert alert-warning">
                            <i class="fas fa-edit"></i> <strong>編集済み選択</strong><br>
                            • 利用可能スタッフ: ${selectionDetails.totalAvailable}名<br>
                            • 編集後の選択: ${selectionDetails.selectedStaff}名
                        </div>
                    `;
                } else {
                    // ランダム選択の場合
                    detailsInfo = `
                        <div class="alert alert-info">
                            <i class="fas fa-dice"></i> <strong>ランダム選択詳細</strong><br>
                            • 利用可能スタッフ: ${selectionDetails.totalAvailable}名<br>
                            • ランナー枠で選択: ${selectionDetails.selectedRunners}名<br>
                            ${selectionDetails.selectedLight > 0 ? `• ライト枠で選択: ${selectionDetails.selectedLight}名<br>` : ''}
                            ${selectionDetails.selectedParents > 0 ? `• 両親枠で選択: ${selectionDetails.selectedParents}名<br>` : ''}
                            • その他枠で選択: ${selectionDetails.selectedOthers}名 (うちランナー ${selectionDetails.runnersInOthers}名)<br>
                            • その他候補者数: ${selectionDetails.otherCandidates}名
                        </div>
                    `;
                }
            }
            
            // 各カテゴリに分ける
            let courseRunners, buffetRunners, lightStaffSelected, parentsStaffSelected, othersSelected;
            
            if (selectionDetails && (selectionDetails.manualSelection || selectionDetails.editedSelection)) {
                // 手動選択または編集済み選択の場合は、スキルに基づいてカテゴリ分け
                courseRunners = selectedStaff.filter(staff => 
                    staff.is_rank === 'ランナー' && 
                    staff.skills.some(skill => skill.task_name === 'コースランナー')
                );
                buffetRunners = selectedStaff.filter(staff => 
                    staff.is_rank === 'ランナー' && 
                    staff.skills.some(skill => skill.task_name === 'ビュッフェランナー') &&
                    !courseRunners.includes(staff) // コースランナーと重複しない
                );
                lightStaffSelected = selectedStaff.filter(staff => 
                    staff.skills.some(skill => skill.task_name === 'ライト') &&
                    !courseRunners.includes(staff) && !buffetRunners.includes(staff) // ランナーと重複しない
                );
                parentsStaffSelected = selectedStaff.filter(staff => 
                    staff.skills.some(skill => skill.task_name === '両親') &&
                    !courseRunners.includes(staff) && !buffetRunners.includes(staff) && !lightStaffSelected.includes(staff) // 他と重複しない
                );
                othersSelected = selectedStaff.filter(staff => 
                    !courseRunners.includes(staff) && !buffetRunners.includes(staff) && 
                    !lightStaffSelected.includes(staff) && !parentsStaffSelected.includes(staff)
                );
            } else {
                // ランダム選択の場合は、選択カテゴリで判定
                courseRunners = selectedStaff.filter(staff => staff.selectionCategory === 'courseRunner');
                buffetRunners = selectedStaff.filter(staff => staff.selectionCategory === 'buffetRunner');
                lightStaffSelected = selectedStaff.filter(staff => staff.selectionCategory === 'light');
                parentsStaffSelected = selectedStaff.filter(staff => staff.selectionCategory === 'parents');
                othersSelected = selectedStaff.filter(staff => staff.selectionCategory === 'other');
            }
            
            // 選択されたスタッフのHTML生成
            let staffHtml = '';
            
            // コースランナーセクション
            if (courseRunners.length > 0) {
                staffHtml += `
                    <div class="col-12 mb-3">
                        <h6 class="text-primary">
                            <i class="fas fa-running"></i> コースランナー (${courseRunners.length}名)
                        </h6>
                    </div>
                `;
                
                courseRunners.forEach((staff, index) => {
                    const genderBadge = staff.gender === 'M' ? '♂' : '♀';
                    const timeDisplay = staff.available_start_time && staff.available_end_time ?
                        `${staff.available_start_time.substr(0, 5)} - ${staff.available_end_time.substr(0, 5)}` : '時間未設定';
                    
                    staffHtml += `
                        <div class="col-md-6 mb-2">
                            <div class="border border-primary rounded p-2 bg-light">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-bold text-primary">${index + 1}. ${staff.name}</div>
                                        <div class="text-muted small">${timeDisplay}</div>
                                    </div>
                                    <span class="badge bg-primary">${genderBadge}</span>
                                </div>
                            </div>
                        </div>
                    `;
                });
            }
            
            // ビュッフェランナーセクション
            if (buffetRunners.length > 0) {
                staffHtml += `
                    <div class="col-12 mb-3 ${courseRunners.length > 0 ? 'mt-3' : ''}">
                        <h6 class="text-warning">
                            <i class="fas fa-utensils"></i> ビュッフェランナー (${buffetRunners.length}名)
                        </h6>
                    </div>
                `;
                
                buffetRunners.forEach((staff, index) => {
                    const genderBadge = staff.gender === 'M' ? '♂' : '♀';
                    const timeDisplay = staff.available_start_time && staff.available_end_time ?
                        `${staff.available_start_time.substr(0, 5)} - ${staff.available_end_time.substr(0, 5)}` : '時間未設定';
                    
                    staffHtml += `
                        <div class="col-md-6 mb-2">
                            <div class="border border-warning rounded p-2 bg-light">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-bold text-warning">${index + 1}. ${staff.name}</div>
                                        <div class="text-muted small">${timeDisplay}</div>
                                    </div>
                                    <span class="badge bg-warning">${genderBadge}</span>
                                </div>
                            </div>
                        </div>
                    `;
                });
            }
            
            // ライトセクション
            if (lightStaffSelected.length > 0) {
                staffHtml += `
                    <div class="col-12 mb-3 ${(courseRunners.length > 0 || buffetRunners.length > 0) ? 'mt-3' : ''}">
                        <h6 class="text-info">
                            <i class="fas fa-lightbulb"></i> ライト要員 (${lightStaffSelected.length}名)
                        </h6>
                    </div>
                `;
                
                lightStaffSelected.forEach((staff, index) => {
                    const genderBadge = staff.gender === 'M' ? '♂' : '♀';
                    const timeDisplay = staff.available_start_time && staff.available_end_time ?
                        `${staff.available_start_time.substr(0, 5)} - ${staff.available_end_time.substr(0, 5)}` : '時間未設定';
                    
                    staffHtml += `
                        <div class="col-md-6 mb-2">
                            <div class="border border-info rounded p-2 bg-light">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-bold text-info">${index + 1}. ${staff.name}</div>
                                        <div class="text-muted small">${timeDisplay}</div>
                                    </div>
                                    <span class="badge bg-info">${genderBadge}</span>
                                </div>
                            </div>
                        </div>
                    `;
                });
            }
            
            // 両親対応セクション
            if (parentsStaffSelected.length > 0) {
                staffHtml += `
                    <div class="col-12 mb-3 ${(courseRunners.length > 0 || buffetRunners.length > 0 || lightStaffSelected.length > 0) ? 'mt-3' : ''}">
                        <h6 class="text-secondary">
                            <i class="fas fa-users-cog"></i> 両親対応 (${parentsStaffSelected.length}名)
                        </h6>
                    </div>
                `;
                
                parentsStaffSelected.forEach((staff, index) => {
                    const genderBadge = staff.gender === 'M' ? '♂' : '♀';
                    const timeDisplay = staff.available_start_time && staff.available_end_time ?
                        `${staff.available_start_time.substr(0, 5)} - ${staff.available_end_time.substr(0, 5)}` : '時間未設定';
                    
                    staffHtml += `
                        <div class="col-md-6 mb-2">
                            <div class="border border-secondary rounded p-2 bg-light">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-bold text-secondary">${index + 1}. ${staff.name}</div>
                                        <div class="text-muted small">${timeDisplay}</div>
                                    </div>
                                    <span class="badge bg-secondary">${genderBadge}</span>
                                </div>
                            </div>
                        </div>
                    `;
                });
            }
            
            // その他セクション（ランナー含む）
            if (othersSelected.length > 0) {
                staffHtml += `
                    <div class="col-12 mb-3 ${(courseRunners.length > 0 || buffetRunners.length > 0 || lightStaffSelected.length > 0 || parentsStaffSelected.length > 0) ? 'mt-3' : ''}">
                        <h6 class="text-success">
                            <i class="fas fa-users"></i> その他 (${othersSelected.length}名)
                            ${othersSelected.filter(s => s.is_rank === 'ランナー').length > 0 ? 
                                `<small class="text-muted">- ランナー ${othersSelected.filter(s => s.is_rank === 'ランナー').length}名含む</small>` : ''}
                        </h6>
                    </div>
                `;
                
                othersSelected.forEach((staff, index) => {
                    const genderBadge = staff.gender === 'M' ? '♂' : '♀';
                    const timeDisplay = staff.available_start_time && staff.available_end_time ?
                        `${staff.available_start_time.substr(0, 5)} - ${staff.available_end_time.substr(0, 5)}` : '時間未設定';
                    
                    // ランナーかどうかで表示を変える
                    const isRunner = staff.is_rank === 'ランナー';
                    const badgeColor = isRunner ? 'bg-info' : 'bg-success';
                    const borderColor = isRunner ? 'border-info' : 'border-success';
                    const textColor = isRunner ? 'text-info' : 'text-success';
                    const rankDisplay = isRunner ? ' (ランナー)' : '';
                    
                    staffHtml += `
                        <div class="col-md-6 mb-2">
                            <div class="${borderColor} rounded p-2 bg-light">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-bold ${textColor}">${index + 1}. ${staff.name}${rankDisplay}</div>
                                        <div class="text-muted small">${timeDisplay}</div>
                                    </div>
                                    <span class="badge ${badgeColor}">${genderBadge}</span>
                                </div>
                            </div>
                        </div>
                    `;
                });
            }
            
            // 不足人数の情報を追加
            const eventSelect = document.getElementById('event_id');
            if (eventSelect && eventSelect.value) {
                const selectedOption = eventSelect.options[eventSelect.selectedIndex];
                const totalStaffRequired = selectedOption.getAttribute('data-total-staff');
                
                if (totalStaffRequired && !isNaN(totalStaffRequired) && totalStaffRequired > 0) {
                    const requiredCount = parseInt(totalStaffRequired);
                    const selectedCount = selectedStaff.length;
                    
                    if (requiredCount > selectedCount) {
                        const shortage = requiredCount - selectedCount;
                        staffHtml += `
                            <div class="col-12 mt-3">
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle"></i> 
                                    <strong>人数不足:</strong> ${shortage}名不足しています 
                                    <small class="text-muted">(必要: ${requiredCount}名 / 選択: ${selectedCount}名)</small>
                                </div>
                            </div>
                        `;
                    } else if (requiredCount === selectedCount) {
                        staffHtml += `
                            <div class="col-12 mt-3">
                                <div class="alert alert-info">
                                    <i class="fas fa-check-circle"></i> 
                                    必要人数がちょうど選択されています 
                                    <small class="text-muted">(${requiredCount}名)</small>
                                </div>
                            </div>
                        `;
                    } else {
                        const surplus = selectedCount - requiredCount;
                        staffHtml += `
                            <div class="col-12 mt-3">
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle"></i> 
                                    必要人数以上が選択されています 
                                    <small class="text-muted">(必要: ${requiredCount}名 / 選択: ${selectedCount}名 / 余裕: ${surplus}名)</small>
                                </div>
                            </div>
                        `;
                    }
                }
            }
            
            // 結果を表示
            selectedStaffList.innerHTML = shortageWarning + detailsInfo + staffHtml;
            
            // 🆕 編集ボタンを追加
            if (!selectionDetails || (!selectionDetails.manualSelection && !selectionDetails.editedSelection)) {
                // ランダム選択の場合のみ編集ボタンを表示（編集済みでない場合）
                const editButtonHtml = `
                    <div class="mt-3 text-center">
                        <button type="button" class="btn btn-outline-warning" onclick="enableEditMode()">
                            ✏️ 選択結果を編集
                        </button>
                        <small class="d-block text-muted mt-1">※出勤可能スタッフから追加/削除できます</small>
                    </div>
                `;
                selectedStaffList.innerHTML += editButtonHtml;
            }
            
            resultArea.style.display = 'block';
            
            // 結果エリアまでスクロール
            resultArea.scrollIntoView({ behavior: 'smooth', block: 'center' });
            
            // アラートメッセージを更新
            const alertDiv = resultArea.querySelector('.alert-success h6');
            const summaryText = [
                courseRunners.length > 0 ? `コース${courseRunners.length}名` : '',
                buffetRunners.length > 0 ? `ビュッフェ${buffetRunners.length}名` : '',
                lightStaffSelected.length > 0 ? `ライト${lightStaffSelected.length}名` : '',
                parentsStaffSelected.length > 0 ? `両親${parentsStaffSelected.length}名` : '',
                othersSelected.length > 0 ? `その他${othersSelected.length}名` : ''
            ].filter(text => text).join('・');
            
            alertDiv.innerHTML = `🎲 ランダム選択されたスタッフ (${totalCount}名中 ${summaryText})`;
        }
        
        // 🆕 結果表示を非表示にする関数
        function hideRandomResult() {
            const resultArea = document.getElementById('randomSelectionResult');
            resultArea.style.display = 'none';
        }
        
        // 🆕 現在選択されているスタッフのデータを保存
        let currentSelectedStaff = [];
        
        // 🆕 ランダムシフト保存機能
        function saveRandomShift() {
            const eventSelect = document.querySelector('select[name="event_id"]');
            const eventId = eventSelect.value;
            
            if (!eventId) {
                alert('イベントが選択されていません');
                return;
            }
            
            if (currentSelectedStaff.length === 0) {
                alert('保存するスタッフが選択されていません');
                return;
            }
            
            if (!confirm(`選択された${currentSelectedStaff.length}名のスタッフでシフトを保存しますか？`)) {
                return;
            }
            
            // 保存ボタンを無効化
            const saveBtn = event.target;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '💾 保存中...';
            
            // フォームデータを作成
            const formData = new FormData();
            formData.append('action', 'save_random_shift');
            formData.append('event_id', eventId);
            formData.append('selected_staff', JSON.stringify(currentSelectedStaff));
            
            // サーバーに送信
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                // ページをリロードして保存結果を表示
                window.location.reload();
            })
            .catch(error => {
                console.error('保存エラー:', error);
                alert('シフトの保存に失敗しました');
                
                // ボタンを元に戻す
                saveBtn.disabled = false;
                saveBtn.innerHTML = '💾 シフト保存';
            });
        }
        
        // 🆕 手動選択状態を更新する関数
        function updateManualSelectionState() {
            const checkboxes = document.querySelectorAll('.staff-checkbox:checked');
            const manualBtn = document.getElementById('manualSelectBtn');
            
            if (manualBtn) {
                if (window.editMode) {
                    // 編集モード中
                    if (checkboxes.length > 0) {
                        manualBtn.disabled = false;
                        manualBtn.innerHTML = `💾 編集完了（変更を保存） (${checkboxes.length}名)`;
                    } else {
                        manualBtn.disabled = true;
                        manualBtn.innerHTML = '💾 編集完了（変更を保存）';
                    }
                } else {
                    // 通常の手動選択モード
                    if (checkboxes.length > 0) {
                        manualBtn.disabled = false;
                        manualBtn.innerHTML = `✅ 選択したスタッフでシフト作成 (${checkboxes.length}名)`;
                    } else {
                        manualBtn.disabled = true;
                        manualBtn.innerHTML = '✅ 選択したスタッフでシフト作成';
                    }
                }
            }
        }
        
        // 🆕 手動選択でシフトを作成する関数
        function manualSelectStaff() {
            const checkboxes = document.querySelectorAll('.staff-checkbox:checked');
            
            if (checkboxes.length === 0) {
                alert('スタッフを選択してください');
                return;
            }
            
            // 選択されたスタッフの情報を収集
            const selectedStaff = [];
            checkboxes.forEach(checkbox => {
                const staffId = parseInt(checkbox.value);
                const staffName = checkbox.getAttribute('data-name');
                const category = checkbox.getAttribute('data-category');
                
                // currentAvailableStaffから詳細情報を取得
                const staffDetails = currentAvailableStaff.find(s => s.id === staffId);
                if (staffDetails) {
                    selectedStaff.push({
                        ...staffDetails,
                        selectionCategory: category
                    });
                }
            });
            
            console.log('手動選択されたスタッフ:', selectedStaff);
            
            // 選択情報を表示
            const selectionDetails = {
                totalAvailable: currentAvailableStaff.length,
                selectedStaff: selectedStaff.length,
                manualSelection: true
            };
            
            showRandomSelectionResult(selectedStaff, currentAvailableStaff.length, [], selectionDetails);
        }
        
        // 🆕 全選択/全解除機能
        function selectAllStaff() {
            const checkboxes = document.querySelectorAll('.staff-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
            updateManualSelectionState();
        }
        
        function deselectAllStaff() {
            const checkboxes = document.querySelectorAll('.staff-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            updateManualSelectionState();
        }
        
        // 🆕 編集モードを有効にする関数
        function enableEditMode() {
            // 現在選択されているスタッフのIDを取得
            const selectedIds = currentSelectedStaff.map(staff => staff.id);
            
            // 出勤可能スタッフエリアのチェックボックスを現在の選択状態に合わせる
            const checkboxes = document.querySelectorAll('.staff-checkbox');
            checkboxes.forEach(checkbox => {
                const staffId = parseInt(checkbox.value);
                checkbox.checked = selectedIds.includes(staffId);
            });
            
            // 手動選択ボタンを有効化し、表示を変更
            const manualBtn = document.getElementById('manualSelectBtn');
            if (manualBtn) {
                manualBtn.disabled = false;
                manualBtn.innerHTML = '💾 編集完了（変更を保存）';
                manualBtn.classList.remove('btn-success');
                manualBtn.classList.add('btn-warning');
                manualBtn.onclick = function() { saveEditChanges(); };
            }
            
            // 編集モード状態を設定
            window.editMode = true;
            
            // 出勤可能スタッフエリアまでスクロール
            const staffArea = document.getElementById('availableStaffArea');
            if (staffArea) {
                staffArea.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            
            // 編集モードの説明を表示
            alert('編集モードが有効になりました。\n\n出勤可能スタッフのチェックボックスで選択を変更し、\n「編集完了」ボタンで変更を保存してください。');
            
            updateManualSelectionState();
        }
        
        // 🆕 編集変更を保存する関数
        function saveEditChanges() {
            const checkboxes = document.querySelectorAll('.staff-checkbox:checked');
            
            if (checkboxes.length === 0) {
                alert('少なくとも1名のスタッフを選択してください');
                return;
            }
            
            // 選択されたスタッフの情報を収集
            const selectedStaff = [];
            checkboxes.forEach(checkbox => {
                const staffId = parseInt(checkbox.value);
                const staffDetails = currentAvailableStaff.find(s => s.id === staffId);
                if (staffDetails) {
                    selectedStaff.push({
                        ...staffDetails,
                        selectionCategory: 'edited' // 編集済みフラグ
                    });
                }
            });
            
            // 編集モードを解除
            window.editMode = false;
            
            // 手動選択ボタンを元に戻す
            const manualBtn = document.getElementById('manualSelectBtn');
            if (manualBtn) {
                manualBtn.innerHTML = '✅ 選択したスタッフでシフト作成';
                manualBtn.classList.remove('btn-warning');
                manualBtn.classList.add('btn-success');
                manualBtn.onclick = function() { manualSelectStaff(); };
            }
            
            // 選択情報を表示
            const selectionDetails = {
                totalAvailable: currentAvailableStaff.length,
                selectedStaff: selectedStaff.length,
                editedSelection: true
            };
            
            showRandomSelectionResult(selectedStaff, currentAvailableStaff.length, [], selectionDetails);
        }
    </script>
</body>
</html>
