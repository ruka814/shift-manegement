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
$stmt = $pdo->query("SELECT id, event_date, start_time, end_time, event_type, description, needs FROM events ORDER BY event_date, start_time");
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
                        </div>
                        
                        <!-- 出勤可能スタッフ表示エリア -->
                        <div id="availableStaffArea" class="mt-3"></div>
                        
                        <!-- シフト作成ボタンエリア -->
                        <div class="mt-3">
                            <!-- ランダム選択ボタン -->
                            <button type="button" class="btn btn-primary w-100" id="randomSelectBtn" onclick="randomSelectStaff()" disabled>
                                🎲 ランダム選択
                            </button>
                            <small class="text-muted d-block mt-1">※出勤可能スタッフからランダムで選択</small>
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
                    <div class="col-md-3">
                        <div class="card text-center stat-card">
                            <div class="stat-number"><?= $stats['total_assigned'] ?></div>
                            <div class="stat-label">総割当人数</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center stat-card">
                            <div class="gender-ratio">
                                <span class="badge bg-primary">男性: <?= $stats['male_count'] ?></span>
                                <span class="badge bg-danger">女性: <?= $stats['female_count'] ?></span>
                            </div>
                            <div class="stat-label">性別比率</div>
                        </div>
                    </div>
                    <div class="col-md-3">
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
                            <small class="text-muted">♂${data.stats.male_count} ♀${data.stats.female_count}</small>
                        </div>
                    </div>
                    <div class="card-body p-3">
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
                    
                    html += `
                        <div class="col-md-6">
                            <div class="border rounded p-2">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-bold small">${staff.name}</div>
                                        <div class="text-muted" style="font-size: 0.75rem;">${timeDisplay}</div>
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
                    
                    html += `
                        <div class="col-md-6">
                            <div class="border rounded p-2">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-bold small">${staff.name}</div>
                                        <div class="text-muted" style="font-size: 0.75rem;">${timeDisplay}</div>
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
            staffArea.innerHTML = html;
            
            // 🆕 ランダム選択ボタンを有効化
            const randomBtn = document.getElementById('randomSelectBtn');
            if (randomBtn) {
                randomBtn.disabled = false;
            }
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
            const runnerCount = currentAvailableStaff.filter(s => s.is_rank === 'ランナー').length;
            const nonRunnerCount = currentAvailableStaff.length - runnerCount;
            
            const modalHtml = `
                <div class="modal fade" id="randomSelectionModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">🎲 ランダム選択設定</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">選択方法</label>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="selectionMode" id="modeTotal" value="total" checked>
                                        <label class="form-check-label" for="modeTotal">
                                            全体から選択
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="selectionMode" id="modeCategory" value="category">
                                        <label class="form-check-label" for="modeCategory">
                                            カテゴリ別に選択
                                        </label>
                                    </div>
                                </div>
                                
                                <div id="totalSelection">
                                    <div class="mb-3">
                                        <label for="totalCount" class="form-label">選択人数</label>
                                        <input type="number" class="form-control" id="totalCount" min="1" max="${currentAvailableStaff.length}" value="3">
                                        <small class="text-muted">出勤可能: ${currentAvailableStaff.length}名（ランナー${runnerCount}名、その他${nonRunnerCount}名）</small>
                                    </div>
                                </div>
                                
                                <div id="categorySelection" style="display: none;">
                                    <div class="row">
                                        <div class="col-6">
                                            <div class="mb-3">
                                                <label for="runnerCount" class="form-label">ランナー</label>
                                                <input type="number" class="form-control" id="runnerCount" min="0" max="${runnerCount}" value="0">
                                                <small class="text-muted">最大${runnerCount}名</small>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="mb-3">
                                                <label for="nonRunnerCount" class="form-label">その他</label>
                                                <input type="number" class="form-control" id="nonRunnerCount" min="0" max="${nonRunnerCount}" value="0">
                                                <small class="text-muted">最大${nonRunnerCount}名</small>
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
            
            // 選択方法の切り替え
            document.querySelectorAll('input[name="selectionMode"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    if (this.value === 'total') {
                        document.getElementById('totalSelection').style.display = 'block';
                        document.getElementById('categorySelection').style.display = 'none';
                    } else {
                        document.getElementById('totalSelection').style.display = 'none';
                        document.getElementById('categorySelection').style.display = 'block';
                    }
                });
            });
        }
        
        function executeRandomSelection() {
            const mode = document.querySelector('input[name="selectionMode"]:checked').value;
            const balanceGender = document.getElementById('balanceGender').checked;
            let selectedStaff = [];
            
            if (mode === 'total') {
                const count = parseInt(document.getElementById('totalCount').value);
                if (count < 1 || count > currentAvailableStaff.length) {
                    alert(`選択人数は1名から${currentAvailableStaff.length}名の間で入力してください`);
                    return;
                }
                
                if (balanceGender) {
                    selectedStaff = selectWithGenderBalance(currentAvailableStaff, count);
                } else {
                    const shuffled = [...currentAvailableStaff].sort(() => 0.5 - Math.random());
                    selectedStaff = shuffled.slice(0, count);
                }
            } else {
                const runnerCount = parseInt(document.getElementById('runnerCount').value);
                const nonRunnerCount = parseInt(document.getElementById('nonRunnerCount').value);
                
                if (runnerCount + nonRunnerCount === 0) {
                    alert('最低1名は選択してください');
                    return;
                }
                
                const runners = currentAvailableStaff.filter(s => s.is_rank === 'ランナー');
                const nonRunners = currentAvailableStaff.filter(s => s.is_rank !== 'ランナー');
                
                if (runnerCount > runners.length || nonRunnerCount > nonRunners.length) {
                    alert('選択人数が利用可能人数を超えています');
                    return;
                }
                
                const selectedRunners = runners.sort(() => 0.5 - Math.random()).slice(0, runnerCount);
                const selectedNonRunners = nonRunners.sort(() => 0.5 - Math.random()).slice(0, nonRunnerCount);
                
                selectedStaff = [...selectedRunners, ...selectedNonRunners];
            }
            
            // モーダルを閉じる
            const modal = bootstrap.Modal.getInstance(document.getElementById('randomSelectionModal'));
            modal.hide();
            
            // 結果を表示
            showRandomSelectionResult(selectedStaff, currentAvailableStaff.length);
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
        
        function showRandomSelectionResult(selectedStaff, totalCount) {
            // メインの結果表示エリアを表示
            const resultArea = document.getElementById('randomSelectionResult');
            const selectedStaffList = document.getElementById('selectedStaffList');
            
            // 選択されたスタッフのHTML生成
            let staffHtml = '';
            selectedStaff.forEach((staff, index) => {
                const genderBadge = staff.gender === 'M' ? '♂' : '♀';
                const timeDisplay = staff.available_start_time && staff.available_end_time ?
                    `${staff.available_start_time.substr(0, 5)} - ${staff.available_end_time.substr(0, 5)}` : '時間未設定';
                const rankBadge = staff.is_rank === 'ランナー' ? 
                    '<span class="badge bg-primary btn-sm">ランナー</span>' : 
                    '<span class="badge bg-secondary btn-sm">その他</span>';
                
                staffHtml += `
                    <div class="col-md-6 mb-2">
                        <div class="border border-success rounded p-2 bg-light">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="fw-bold text-success">${index + 1}. ${staff.name}</div>
                                    <div class="text-muted small">${timeDisplay}</div>
                                    <div class="mt-1">${rankBadge}</div>
                                </div>
                                <span class="badge bg-success">${genderBadge}</span>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            // 結果を表示
            selectedStaffList.innerHTML = staffHtml;
            resultArea.style.display = 'block';
            
            // 結果エリアまでスクロール
            resultArea.scrollIntoView({ behavior: 'smooth', block: 'center' });
            
            // アラートメッセージを更新
            const alertDiv = resultArea.querySelector('.alert-success h6');
            alertDiv.innerHTML = `🎲 ランダム選択されたスタッフ (${totalCount}名中 ${selectedStaff.length}名)`;
        }
        
        // 🆕 結果表示を非表示にする関数
        function hideRandomResult() {
            const resultArea = document.getElementById('randomSelectionResult');
            resultArea.style.display = 'none';
        }
    </script>
</body>
</html>
