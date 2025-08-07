<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

// シフト自動作成画面
$selectedEventId = $_GET['event_id'] ?? '';
$message = '';
$assignmentResult = null;

// 自動割当処理
if ($_POST['action'] ?? '' === 'auto_assign') {
    try {
        $eventId = $_POST['event_id'];
        $assignmentResult = performAutoAssignment($pdo, $eventId);
        $message = showAlert('success', 'シフトを自動作成しました。');
    } catch(Exception $e) {
        $message = showAlert('danger', 'エラーが発生しました: ' . $e->getMessage());
    }
}

// イベント一覧取得
$stmt = $pdo->query("SELECT id, event_date, start_time, end_time, event_type, description, needs FROM events ORDER BY event_date, start_time");
$events = $stmt->fetchAll();

// 選択されたイベント情報取得
$selectedEvent = null;
if ($selectedEventId) {
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$selectedEventId]);
    $selectedEvent = $stmt->fetch();
}

/**
 * 自動割当処理
 */
function performAutoAssignment($pdo, $eventId) {
    // イベント情報取得
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    
    if (!$event) {
        throw new Exception('イベントが見つかりません');
    }
    
    // 必要人数解析
    $needs = parseNeeds($event['needs']);
    
    // 出勤可能なスタッフ取得
    $stmt = $pdo->prepare("
        SELECT u.*, a.available_start_time, a.available_end_time
        FROM users u
        JOIN availability a ON u.id = a.user_id
        WHERE a.event_id = ? AND a.available = 1
    ");
    $stmt->execute([$eventId]);
    $availableUsers = $stmt->fetchAll();
    
    // PHP側で五十音順にソート
    $availableUsers = sortUsersByRankAndName($availableUsers);
    
    // 時間の重複チェック
    $validUsers = [];
    foreach ($availableUsers as $user) {
        if (isTimeOverlap(
            $event['start_time'], 
            $event['end_time'],
            $user['available_start_time'], 
            $user['available_end_time']
        )) {
            $validUsers[] = $user;
        }
    }
    
    // 各ユーザーのスキル情報取得
    foreach ($validUsers as &$user) {
        $stmt = $pdo->prepare("
            SELECT tt.name as task_name, s.skill_level
            FROM skills s
            JOIN task_types tt ON s.task_type_id = tt.id
            WHERE s.user_id = ?
        ");
        $stmt->execute([$user['id']]);
        $skills = $stmt->fetchAll();
        
        $user['skills'] = [];
        foreach ($skills as $skill) {
            $user['skills'][$skill['task_name']] = $skill['skill_level'];
        }
    }
    
    // 自動割当アルゴリズム
    $assignments = [];
    $usedUsers = [];
    
    // ランナーを優先して割当
    foreach ($needs as $role => $requirement) {
        $roleAssignments = [];
        $neededCount = $requirement['min']; // 最小値を優先
        
        // スキルを持つユーザーを優先度順に並び替え
        $candidates = [];
        foreach ($validUsers as $user) {
            if (in_array($user['id'], $usedUsers)) continue;
            
            $skillLevel = $user['skills'][$role] ?? 'できない';
            $priority = 0;
            
            // スキルレベルによる優先度
            if ($skillLevel === 'できる') $priority += 3;
            elseif ($skillLevel === 'まあまあできる') $priority += 2;
            elseif ($skillLevel === 'できない') $priority += 0;
            
            // ランナーかどうかによる優先度
            if ($user['is_rank'] === 'ランナー') $priority += 1;
            
            $candidates[] = [
                'user' => $user,
                'priority' => $priority,
                'skill_level' => $skillLevel
            ];
        }
        
        // 優先度順にソート
        usort($candidates, function($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });
        
        // 必要人数分を割当
        $assigned = 0;
        foreach ($candidates as $candidate) {
            if ($assigned >= $neededCount) break;
            if ($candidate['skill_level'] === 'できない') continue; // スキルがない場合は割当しない
            
            $roleAssignments[] = [
                'user' => $candidate['user'],
                'skill_level' => $candidate['skill_level']
            ];
            $usedUsers[] = $candidate['user']['id'];
            $assigned++;
        }
        
        $assignments[$role] = $roleAssignments;
    }
    
    return [
        'event' => $event,
        'needs' => $needs,
        'assignments' => $assignments,
        'available_users' => $validUsers,
        'total_assigned' => count($usedUsers)
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
                        
                        <form method="POST" class="mt-3">
                            <input type="hidden" name="action" value="auto_assign">
                            <input type="hidden" name="event_id" value="<?= $selectedEventId ?>">
                            <button type="submit" class="btn btn-success w-100">
                                🎯 自動シフト作成
                            </button>
                        </form>
                        
                        <?php if ($assignmentResult): ?>
                        <div class="mt-3">
                            <button class="btn btn-outline-primary w-100" onclick="window.print()">
                                🖨️ 印刷
                            </button>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <?php if ($assignmentResult): ?>
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
                    <div class="card-header">
                        <h5>🎯 シフト割当結果</h5>
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
                <div class="card">
                    <div class="card-body text-center">
                        <h5>🎯 シフト自動作成</h5>
                        <p class="text-muted">「自動シフト作成」ボタンをクリックして、最適なシフトを自動生成します。</p>
                        <div class="alert alert-info">
                            <strong>自動割当の条件:</strong><br>
                            ✅ 出勤可能時間がイベント時間と重複<br>
                            ✅ スキルレベル（できる > まあまあできる > できない）<br>
                            ✅ ランク（ランナー優先）<br>
                            ✅ 必要最小人数を優先配置
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
</body>
</html>
