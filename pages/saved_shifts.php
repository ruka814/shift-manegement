<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

$message = '';

// AJAX リクエスト処理
if ($_GET['action'] ?? '' === 'get_personal_shift') {
    $userId = $_GET['user_id'] ?? '';
    
    // デバッグ用ログ
    error_log("Personal shift request - User ID: " . $userId);
    
    if ($userId) {
        try {
            $personalShift = getPersonalShiftDetail($pdo, $userId);
            error_log("Personal shift data retrieved: " . ($personalShift ? "Success" : "No data"));
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'data' => $personalShift
            ]);
            exit;
        } catch (Exception $e) {
            error_log("Error in personal shift: " . $e->getMessage());
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'データ取得エラー: ' . $e->getMessage()
            ]);
            exit;
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'パラメータが不正です'
        ]);
        exit;
    }
}

// 🆕 スタッフ追加処理
if ($_POST['action'] ?? '' === 'add_staff') {
    try {
        $eventId = $_POST['event_id'];
        $userId = $_POST['user_id'];
        
        // 既に割当されていないかチェック
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE event_id = ? AND user_id = ?");
        $stmt->execute([$eventId, $userId]);
        if ($stmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'error' => 'このスタッフは既に割当されています']);
            exit;
        }
        
        // ユーザー情報を取得してroleを決定
        $stmt = $pdo->prepare("SELECT is_rank FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        $role = $user['is_rank'] ?? 'その他';
        
        // スタッフを追加
        $stmt = $pdo->prepare("
            INSERT INTO assignments (event_id, user_id, assigned_role, note, created_at) 
            VALUES (?, ?, ?, '手動追加', NOW())
        ");
        $stmt->execute([$eventId, $userId, $role]);
        
        echo json_encode(['success' => true, 'message' => 'スタッフを追加しました']);
    } catch(Exception $e) {
        echo json_encode(['success' => false, 'error' => '追加エラー: ' . $e->getMessage()]);
    }
    exit;
}

// 🆕 スタッフ削除処理
if ($_POST['action'] ?? '' === 'remove_staff') {
    try {
        $eventId = $_POST['event_id'];
        $userId = $_POST['user_id'];
        
        $stmt = $pdo->prepare("DELETE FROM assignments WHERE event_id = ? AND user_id = ?");
        $stmt->execute([$eventId, $userId]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'スタッフを削除しました']);
        } else {
            echo json_encode(['success' => false, 'error' => '削除対象が見つかりません']);
        }
    } catch(Exception $e) {
        echo json_encode(['success' => false, 'error' => '削除エラー: ' . $e->getMessage()]);
    }
    exit;
}

// シフト削除処理
if ($_POST['action'] ?? '' === 'delete_shift') {
    try {
        $eventId = $_POST['event_id'];
        $stmt = $pdo->prepare("DELETE FROM assignments WHERE event_id = ?");
        $stmt->execute([$eventId]);
        $message = showAlert('success', 'シフトを削除しました。');
    } catch(Exception $e) {
        $message = showAlert('danger', '削除エラー: ' . $e->getMessage());
    }
}

// 保存済みシフト一覧取得
$stmt = $pdo->query("
    SELECT e.*, COUNT(a.id) as assigned_count,
           MIN(a.created_at) as shift_created_at,
           e.total_staff_required,
           e.course_runner_count,
           e.buffet_runner_count,
           e.light_count,
           e.parents_count
    FROM events e
    JOIN assignments a ON e.id = a.event_id
    GROUP BY e.id, e.event_date, e.start_time, e.end_time, e.event_type, e.venue, e.needs, e.description, 
             e.created_at, e.updated_at, e.total_staff_required, e.course_runner_count, 
             e.buffet_runner_count, e.light_count, e.parents_count
    ORDER BY e.event_date DESC, e.start_time DESC
");
$savedShifts = $stmt->fetchAll();

// シフト作成方法を取得
function getCreationMethod($pdo, $eventId) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT note FROM assignments WHERE event_id = ? LIMIT 1
    ");
    $stmt->execute([$eventId]);
    $result = $stmt->fetch();
    
    if ($result && $result['note']) {
        $note = $result['note'];
        if (strpos($note, 'ランダム選択') !== false) {
            return ['type' => 'random', 'badge' => 'bg-primary', 'text' => '🎲 ランダム選択'];
        } elseif (strpos($note, '自動割当') !== false) {
            return ['type' => 'auto', 'badge' => 'bg-success', 'text' => '🎯 自動割当'];
        } else {
            return ['type' => 'manual', 'badge' => 'bg-secondary', 'text' => '✏️ 手動作成'];
        }
    }
    
    return ['type' => 'unknown', 'badge' => 'bg-secondary', 'text' => '📝 不明'];
}

// 割当されたスタッフ一覧を取得
function getAssignedStaff($pdo, $eventId) {
    // イベント情報を取得
    $stmt = $pdo->prepare("SELECT event_date, start_time, end_time FROM events WHERE id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.gender, u.is_rank, a.assigned_role,
               av.available_start_time, av.available_end_time
        FROM assignments a
        JOIN users u ON a.user_id = u.id
        LEFT JOIN availability av ON u.id = av.user_id AND av.work_date = ?
        WHERE a.event_id = ?
        ORDER BY a.assigned_role, u.furigana
    ");
    $stmt->execute([$event['event_date'], $eventId]);
    $staff = $stmt->fetchAll();
    
    // 各スタッフに時間重複情報を追加
    foreach ($staff as &$member) {
        $member['event_start_time'] = $event['start_time'];
        $member['event_end_time'] = $event['end_time'];
        
        if ($member['available_start_time'] && $member['available_end_time'] &&
            $event['start_time'] && $event['end_time']) {
            $member['overlap_info'] = checkTimeOverlapForSavedShift(
                $event['start_time'], $event['end_time'],
                $member['available_start_time'], $member['available_end_time']
            );
        } else {
            $member['overlap_info'] = ['type' => 'unknown', 'hasOverlap' => false];
        }
    }
    
    return $staff;
}

// 🆕 時間重複チェック関数（保存済みシフト用）
function checkTimeOverlapForSavedShift($eventStart, $eventEnd, $availableStart, $availableEnd) {
    // 時間文字列をDateオブジェクトに変換（同じ日付で比較）
    $baseDate = '2024-01-01 ';
    $eventStartTime = strtotime($baseDate . $eventStart);
    $eventEndTime = strtotime($baseDate . $eventEnd);
    $availableStartTime = strtotime($baseDate . $availableStart);
    $availableEndTime = strtotime($baseDate . $availableEnd);
    
    // 重複なし
    if ($eventEndTime <= $availableStartTime || $eventStartTime >= $availableEndTime) {
        return ['hasOverlap' => false, 'type' => 'none'];
    }
    
    // 完全に含む（出勤時間が宴会時間を完全にカバー）
    if ($availableStartTime <= $eventStartTime && $availableEndTime >= $eventEndTime) {
        return ['hasOverlap' => true, 'type' => 'complete'];
    }
    
    // 一部重複
    return ['hasOverlap' => true, 'type' => 'partial'];
}

// 出勤可能だが割当されなかったスタッフを取得
function getUnassignedAvailableStaff($pdo, $eventId) {
    // イベント情報を取得
    $stmt = $pdo->prepare("SELECT event_date, start_time, end_time FROM events WHERE id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    
    if (!$event) {
        return [];
    }
    
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.name, u.gender, u.is_rank, u.furigana,
               av.available_start_time, av.available_end_time
        FROM users u
        JOIN availability av ON u.id = av.user_id
        WHERE av.work_date = ? 
        AND av.available = 1
        AND u.id NOT IN (
            SELECT user_id FROM assignments WHERE event_id = ?
        )
        ORDER BY u.is_rank DESC, u.furigana
    ");
    $stmt->execute([$event['event_date'], $eventId]);
    $staff = $stmt->fetchAll();
    
    // 各スタッフに時間重複情報を追加
    foreach ($staff as &$member) {
        $member['event_start_time'] = $event['start_time'];
        $member['event_end_time'] = $event['end_time'];
        
        if ($member['available_start_time'] && $member['available_end_time'] &&
            $event['start_time'] && $event['end_time']) {
            $member['overlap_info'] = checkTimeOverlapForSavedShift(
                $event['start_time'], $event['end_time'],
                $member['available_start_time'], $member['available_end_time']
            );
        } else {
            $member['overlap_info'] = ['type' => 'unknown', 'hasOverlap' => false];
        }
    }
    
    return $staff;
}

// 個人の全シフト詳細情報を取得
function getPersonalShiftDetail($pdo, $userId) {
    error_log("getPersonalShiftDetail called with userId: " . $userId);
    
    try {
        // ユーザー基本情報
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $userInfo = $stmt->fetch();
        
        if (!$userInfo) {
            error_log("User not found for ID: " . $userId);
            return null;
        }
        
        error_log("User found: " . $userInfo['name']);
        
        // その人が関わっている全てのシフトを取得
        $stmt = $pdo->prepare("
            SELECT 
                e.id as event_id,
                e.event_type,
                e.event_date,
                e.start_time,
                e.end_time,
                e.venue,
                a.assigned_role,
                a.note as assignment_note,
                a.created_at as assignment_created_at,
                'システム作成' as shift_name
            FROM assignments a
            JOIN events e ON a.event_id = e.id
            WHERE a.user_id = ?
            ORDER BY e.event_date DESC, a.created_at DESC
        ");
        $stmt->execute([$userId]);
        $shifts = $stmt->fetchAll();
        
        error_log("Shifts found: " . count($shifts));
        
        // 各シフトの出勤可能情報も取得
        foreach ($shifts as &$shift) {
            $stmt = $pdo->prepare("
                SELECT available, available_start_time, available_end_time, note
                FROM availability
                WHERE user_id = ? AND work_date = ?
            ");
            $stmt->execute([$userId, $shift['event_date']]);
            $availability = $stmt->fetch();
            $shift['availability'] = $availability;
        }
        
        // スキル情報を取得
        $stmt = $pdo->prepare("
            SELECT tt.name as task_name, s.skill_level
            FROM skills s
            JOIN task_types tt ON s.task_type_id = tt.id
            WHERE s.user_id = ?
            ORDER BY tt.name
        ");
        $stmt->execute([$userId]);
        $skills = $stmt->fetchAll();
        
        error_log("Skills found: " . count($skills));
        
        return [
            'user' => $userInfo,
            'shifts' => $shifts,
            'skills' => $skills
        ];
        
    } catch (Exception $e) {
        error_log("Exception in getPersonalShiftDetail: " . $e->getMessage());
        throw $e;
    }
}

// 不足情報を計算
function calculateShiftShortage($shift) {
    $requiredCount = (int)($shift['total_staff_required'] ?? 0);
    $assignedCount = (int)$shift['assigned_count'];
    
    $result = [
        'required' => $requiredCount,
        'assigned' => $assignedCount,
        'shortage' => $requiredCount - $assignedCount,
        'status' => 'unknown',
        'badge_class' => 'bg-secondary',
        'text' => '情報なし'
    ];
    
    if ($requiredCount > 0) {
        if ($result['shortage'] > 0) {
            $result['status'] = 'shortage';
            $result['badge_class'] = 'bg-warning text-dark';
            $result['text'] = $result['shortage'] . '名不足';
        } elseif ($result['shortage'] === 0) {
            $result['status'] = 'exact';
            $result['badge_class'] = 'bg-success';
            $result['text'] = '過不足なし';
        } else {
            $result['status'] = 'surplus';
            $result['badge_class'] = 'bg-info';
            $result['text'] = abs($result['shortage']) . '名余裕';
        }
    }
    
    return $result;
}

// 婚礼イベントの詳細不足情報を計算
function calculateWeddingShortage($pdo, $eventId, $shift) {
    if ($shift['event_type'] !== '婚礼') {
        return null;
    }
    
    $lightRequired = (int)($shift['light_count'] ?? 0);
    $parentsRequired = (int)($shift['parents_count'] ?? 0);
    
    if ($lightRequired === 0 && $parentsRequired === 0) {
        return null;
    }
    
    // 割当されたスタッフのスキル情報を取得
    $stmt = $pdo->prepare("
        SELECT u.is_rank, s.skill_level, tt.name as task_name
        FROM assignments a
        JOIN users u ON a.user_id = u.id
        LEFT JOIN skills s ON u.id = s.user_id
        LEFT JOIN task_types tt ON s.task_type_id = tt.id
        WHERE a.event_id = ?
    ");
    $stmt->execute([$eventId]);
    $assignedStaff = $stmt->fetchAll();
    
    $lightAssigned = 0;
    $parentsAssigned = 0;
    
    foreach ($assignedStaff as $staff) {
        if ($staff['task_name'] && strpos($staff['task_name'], 'ライト') !== false) {
            $lightAssigned++;
        }
        if ($staff['task_name'] && strpos($staff['task_name'], '接客') !== false || $staff['is_rank'] === 'ランナー') {
            $parentsAssigned++;
        }
    }
    
    $details = [];
    
    if ($lightRequired > 0) {
        $lightShortage = $lightRequired - $lightAssigned;
        if ($lightShortage > 0) {
            $details[] = "ライト要員 {$lightShortage}名不足";
        } elseif ($lightShortage === 0) {
            $details[] = "ライト要員 充足";
        } else {
            $details[] = "ライト要員 " . abs($lightShortage) . "名余裕";
        }
    }
    
    if ($parentsRequired > 0) {
        $parentsShortage = $parentsRequired - $parentsAssigned;
        if ($parentsShortage > 0) {
            $details[] = "両親対応 {$parentsShortage}名不足";
        } elseif ($parentsShortage === 0) {
            $details[] = "両親対応 充足";
        } else {
            $details[] = "両親対応 " . abs($parentsShortage) . "名余裕";
        }
    }
    
    return $details;
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>保存済みシフト一覧 - シフト管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
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
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="../index.php">シフト管理システム</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="users.php">スタッフ管理</a>
                <a class="nav-link" href="events.php">イベント管理</a>
                <a class="nav-link" href="availability.php">出勤入力</a>
                <a class="nav-link" href="shift_assignment.php">シフト作成</a>
                <a class="nav-link active" href="saved_shifts.php">保存済みシフト</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <?= $message ?>
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>📋 保存済みシフト一覧</h2>
            <a href="shift_assignment.php" class="btn btn-primary">
                ➕ 新規シフト作成
            </a>
        </div>
        
        <!-- 🆕 時間重複の凡例 -->
        <div class="card mb-4">
            <div class="card-body py-2">
                <div class="d-flex align-items-center gap-4 small">
                    <span class="text-muted fw-bold">時間重複表示:</span>
                    <div class="d-flex align-items-center">
                        <i class="fas fa-check-circle text-success me-1"></i>
                        <span>完全重複</span>
                    </div>
                    <div class="d-flex align-items-center">
                        <i class="fas fa-info-circle text-info me-1"></i>
                        <span>一部重複</span>
                    </div>
                    <div class="d-flex align-items-center">
                        <i class="fas fa-exclamation-triangle text-warning me-1"></i>
                        <span>重複なし</span>
                    </div>
                    <div class="d-flex align-items-center">
                        <i class="fas fa-question-circle text-secondary me-1"></i>
                        <span>時間情報不明</span>
                    </div>
                </div>
            </div>
        </div>

        <?php if (empty($savedShifts)): ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <h5 class="text-muted">保存済みシフトがありません</h5>
                <p class="text-muted">シフト作成ページで自動作成後、保存してください。</p>
                <a href="shift_assignment.php" class="btn btn-primary">
                    シフト作成へ
                </a>
            </div>
        </div>
        <?php else: ?>
        
        <div class="row">
            <?php foreach ($savedShifts as $shift): ?>
            <?php 
                $creationMethod = getCreationMethod($pdo, $shift['id']);
                $assignedStaff = getAssignedStaff($pdo, $shift['id']);
                $unassignedStaff = getUnassignedAvailableStaff($pdo, $shift['id']);
                $shortageInfo = calculateShiftShortage($shift);
                $weddingDetails = calculateWeddingShortage($pdo, $shift['id'], $shift);
            ?>
            <div class="col-12 mb-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h5 class="mb-1 text-primary"><?= h($shift['event_type']) ?></h5>
                                <div class="d-flex align-items-center gap-3">
                                    <span class="fw-bold text-dark"><?= formatDate($shift['event_date']) ?></span>
                                    <span class="text-muted"><?= formatTime($shift['start_time']) ?> - <?= formatTime($shift['end_time']) ?></span>
                                    <?php if ($shift['venue']): ?>
                                    <span class="badge bg-info"><?= h($shift['venue']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-4 text-end">
                                <div class="d-flex flex-column align-items-end gap-1">
                                    <span class="badge <?= $creationMethod['badge'] ?> fs-6"><?= $creationMethod['text'] ?></span>
                                    <span class="badge bg-primary"><?= $shift['assigned_count'] ?>名割当</span>
                                    
                                    <?php if (count($unassignedStaff) > 0): ?>
                                    <span class="badge bg-warning text-dark"><?= count($unassignedStaff) ?>名未割当</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- 割当スタッフ一覧 -->
                        <div class="mb-4">
                            <h6 class="text-success mb-2">✅ 割当スタッフ (<?= count($assignedStaff) ?>名)</h6>
                            <div class="row g-3">
                                <?php foreach ($assignedStaff as $index => $staff): ?>
                                <?php
                                    // 🆕 時間重複に基づく色とアイコンの設定
                                    $overlapClass = '';
                                    $overlapIcon = '';
                                    
                                    if (isset($staff['overlap_info'])) {
                                        switch ($staff['overlap_info']['type']) {
                                            case 'complete':
                                                $overlapClass = 'border-success bg-light-success';
                                                $overlapIcon = '<i class="fas fa-check-circle text-success me-1" title="完全重複：宴会時間を完全にカバー"></i>';
                                                break;
                                            case 'partial':
                                                $overlapClass = 'border-info bg-light-info';
                                                $overlapIcon = '<i class="fas fa-info-circle text-info me-1" title="一部重複：宴会時間と一部重複"></i>';
                                                break;
                                            case 'none':
                                                $overlapClass = 'border-warning bg-light-warning';
                                                $overlapIcon = '<i class="fas fa-exclamation-triangle text-warning me-1" title="重複なし：時間調整が必要"></i>';
                                                break;
                                            default:
                                                $overlapClass = 'border-secondary bg-light';
                                                $overlapIcon = '<i class="fas fa-question-circle text-secondary me-1" title="時間情報不明"></i>';
                                        }
                                    } else {
                                        $overlapClass = 'border-success bg-success bg-opacity-10';
                                        $overlapIcon = '';
                                    }
                                ?>
                                <div class="col-md-6">
                                    <div class="d-flex align-items-center p-2 <?= $overlapClass ?> rounded">
                                        <div class="me-3">
                                            <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 14px; font-weight: bold;">
                                                <?= $index + 1 ?>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="fw-bold text-dark">
                                                <a href="#" class="text-decoration-none text-dark" 
                                                   onclick="showPersonalShift(<?= $staff['id'] ?>)">
                                                    <?= $overlapIcon ?><?= h($staff['name']) ?>
                                                </a>
                                            </div>
                                            <div class="d-flex gap-1 mt-1">
                                                <?php if ($staff['is_rank'] === 'ランナー'): ?>
                                                <span class="badge bg-primary">ランナー</span>
                                                <?php else: ?>
                                                <span class="badge bg-secondary">その他</span>
                                                <?php endif; ?>
                                                <span class="badge bg-success"><?= $staff['gender'] === 'M' ? '♂' : '♀' ?></span>
                                                <?php if ($staff['available_start_time'] && $staff['available_end_time']): ?>
                                                <span class="badge bg-dark"><?= substr($staff['available_start_time'], 0, 5) ?>-<?= substr($staff['available_end_time'], 0, 5) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- 不足情報表示 -->
                        <?php if ($shortageInfo['required'] > 0): ?>
                        <div class="mb-4">
                            <div class="card border-<?= $shortageInfo['status'] === 'shortage' ? 'warning' : ($shortageInfo['status'] === 'exact' ? 'success' : 'info') ?>">
                                <div class="card-body text-center py-3">
                                    <div class="row align-items-center">
                                        <div class="col-md-4">
                                            <div class="h5 mb-1 text-<?= $shortageInfo['status'] === 'shortage' ? 'warning' : ($shortageInfo['status'] === 'exact' ? 'success' : 'info') ?>">
                                                <?= $shortageInfo['required'] ?>名
                                            </div>
                                            <small class="text-muted">必要人数</small>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="h5 mb-1 text-primary">
                                                <?= $shortageInfo['assigned'] ?>名
                                            </div>
                                            <small class="text-muted">割当済み</small>
                                        </div>
                                        <div class="col-md-4">
                                            <span class="badge <?= $shortageInfo['badge_class'] ?> fs-6 px-3 py-2">
                                                <?= $shortageInfo['text'] ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <!-- 婚礼特別要件の詳細表示 -->
                                    <?php if ($weddingDetails): ?>
                                    <hr class="my-3">
                                    <div class="text-start">
                                        <h6 class="text-info mb-2">💒 婚礼特別要件</h6>
                                        <div class="row g-2">
                                            <?php foreach ($weddingDetails as $detail): ?>
                                            <div class="col-auto">
                                                <?php 
                                                $detailClass = 'bg-info';
                                                if (strpos($detail, '不足') !== false) {
                                                    $detailClass = 'bg-warning text-dark';
                                                } elseif (strpos($detail, '充足') !== false) {
                                                    $detailClass = 'bg-success';
                                                }
                                                ?>
                                                <span class="badge <?= $detailClass ?>"><?= h($detail) ?></span>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- 未割当の出勤可能スタッフ -->
                        <?php if (!empty($unassignedStaff)): ?>
                        <div class="mb-3">
                            <h6 class="text-warning mb-2">⚠️ 出勤可能だが未割当 (<?= count($unassignedStaff) ?>名)</h6>
                            <div class="row g-3">
                                <?php foreach ($unassignedStaff as $index => $staff): ?>
                                <?php
                                    // 🆕 時間重複に基づく色とアイコンの設定
                                    $overlapClass = '';
                                    $overlapIcon = '';
                                    
                                    if (isset($staff['overlap_info'])) {
                                        switch ($staff['overlap_info']['type']) {
                                            case 'complete':
                                                $overlapClass = 'border-success bg-light-success';
                                                $overlapIcon = '<i class="fas fa-check-circle text-success me-1" title="完全重複：宴会時間を完全にカバー"></i>';
                                                break;
                                            case 'partial':
                                                $overlapClass = 'border-info bg-light-info';
                                                $overlapIcon = '<i class="fas fa-info-circle text-info me-1" title="一部重複：宴会時間と一部重複"></i>';
                                                break;
                                            case 'none':
                                                $overlapClass = 'border-warning bg-light-warning';
                                                $overlapIcon = '<i class="fas fa-exclamation-triangle text-warning me-1" title="重複なし：時間調整が必要"></i>';
                                                break;
                                            default:
                                                $overlapClass = 'border-warning bg-warning bg-opacity-10';
                                                $overlapIcon = '<i class="fas fa-question-circle text-warning me-1" title="時間情報不明"></i>';
                                        }
                                    } else {
                                        $overlapClass = 'border-warning bg-warning bg-opacity-10';
                                        $overlapIcon = '';
                                    }
                                ?>
                                <div class="col-md-6">
                                    <div class="d-flex align-items-center p-2 <?= $overlapClass ?> rounded">
                                        <div class="me-3">
                                            <div class="bg-warning text-dark rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 14px; font-weight: bold;">
                                                <?= $index + 1 ?>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="fw-bold text-dark">
                                                <a href="#" class="text-decoration-none text-dark" 
                                                   onclick="showPersonalShift(<?= $staff['id'] ?>)">
                                                    <?= $overlapIcon ?><?= h($staff['name']) ?>
                                                </a>
                                            </div>
                                            <div class="d-flex gap-1 mt-1">
                                                <?php if ($staff['is_rank'] === 'ランナー'): ?>
                                                <span class="badge bg-primary">ランナー</span>
                                                <?php else: ?>
                                                <span class="badge bg-secondary">その他</span>
                                                <?php endif; ?>
                                                <span class="badge bg-secondary"><?= $staff['gender'] === 'M' ? '♂' : '♀' ?></span>
                                                <?php if ($staff['available_start_time'] && $staff['available_end_time']): ?>
                                                <span class="badge bg-info"><?= substr($staff['available_start_time'], 0, 5) ?>-<?= substr($staff['available_end_time'], 0, 5) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer bg-light">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="text-muted small">
                                <strong>保存日時:</strong><br>
                                <?= date('Y/m/d H:i', strtotime($shift['shift_created_at'])) ?>
                            </div>
                            <div>
                                <button type="button" class="btn btn-outline-success btn-sm me-2" 
                                        onclick="toggleEditMode(<?= $shift['id'] ?>)">
                                    <i class="fas fa-edit me-1"></i>クイック編集
                                </button>
                                <a href="shift_assignment.php?event_id=<?= $shift['id'] ?>" class="btn btn-outline-primary btn-sm me-2">
                                    <i class="fas fa-external-link-alt me-1"></i>詳細編集
                                </a>
                                <form method="POST" class="d-inline" 
                                      onsubmit="return confirm('このシフトを削除しますか？\n\nこの操作は取り消せません。')">
                                    <input type="hidden" name="action" value="delete_shift">
                                    <input type="hidden" name="event_id" value="<?= $shift['id'] ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm">
                                        <i class="fas fa-trash-alt me-1"></i>削除
                                    </button>
                                </form>
                            </div>
                        </div>
                        
                        <!-- 🆕 インライン編集エリア -->
                        <div id="editArea_<?= $shift['id'] ?>" class="mt-3" style="display: none;">
                            <div class="border-top pt-3">
                                <h6 class="text-primary mb-3">✏️ スタッフ割当編集</h6>
                                
                                <!-- 現在の割当スタッフ -->
                                <div class="mb-3">
                                    <h6 class="text-success mb-2">現在の割当スタッフ</h6>
                                    <div id="currentAssigned_<?= $shift['id'] ?>" class="row g-2">
                                        <?php foreach ($assignedStaff as $staff): ?>
                                        <div class="col-md-6">
                                            <div class="d-flex align-items-center justify-content-between p-2 bg-success bg-opacity-10 border border-success rounded">
                                                <span><?= h($staff['name']) ?> (<?= h($staff['assigned_role']) ?>)</span>
                                                <button type="button" class="btn btn-sm btn-outline-danger" 
                                                        onclick="removeStaffFromShift(<?= $shift['id'] ?>, <?= $staff['id'] ?>, '<?= h($staff['name']) ?>')">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                
                                <!-- 追加可能スタッフ -->
                                <?php if (!empty($unassignedStaff)): ?>
                                <div class="mb-3">
                                    <h6 class="text-warning mb-2">追加可能スタッフ</h6>
                                    <div id="availableStaff_<?= $shift['id'] ?>" class="row g-2">
                                        <?php foreach ($unassignedStaff as $staff): ?>
                                        <div class="col-md-6">
                                            <div class="d-flex align-items-center justify-content-between p-2 bg-warning bg-opacity-10 border border-warning rounded">
                                                <span><?= h($staff['name']) ?> 
                                                    <?php if ($staff['available_start_time'] && $staff['available_end_time']): ?>
                                                    <small class="text-muted">(<?= substr($staff['available_start_time'], 0, 5) ?>-<?= substr($staff['available_end_time'], 0, 5) ?>)</small>
                                                    <?php endif; ?>
                                                </span>
                                                <button type="button" class="btn btn-sm btn-outline-success" 
                                                        onclick="addStaffToShift(<?= $shift['id'] ?>, <?= $staff['id'] ?>, '<?= h($staff['name']) ?>')">
                                                    <i class="fas fa-plus"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <div class="text-end">
                                    <button type="button" class="btn btn-secondary btn-sm me-2" 
                                            onclick="toggleEditMode(<?= $shift['id'] ?>)">
                                        キャンセル
                                    </button>
                                    <button type="button" class="btn btn-success btn-sm" 
                                            onclick="saveShiftChanges(<?= $shift['id'] ?>)">
                                        変更を保存
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="mt-4">
            <div class="card">
                <div class="card-body">
                    <h6>📊 保存済みシフト統計</h6>
                    <div class="row text-center">
                        <div class="col-md-2">
                            <div class="stat-number"><?= count($savedShifts) ?></div>
                            <div class="stat-label">総シフト数</div>
                        </div>
                        <div class="col-md-2">
                            <div class="stat-number"><?= array_sum(array_column($savedShifts, 'assigned_count')) ?></div>
                            <div class="stat-label">総割当人数</div>
                        </div>
                        <div class="col-md-2">
                            <div class="stat-number">
                                <?php
                                $totalRequired = 0;
                                $shiftsWithRequirement = 0;
                                foreach ($savedShifts as $shift) {
                                    if ($shift['total_staff_required'] > 0) {
                                        $totalRequired += $shift['total_staff_required'];
                                        $shiftsWithRequirement++;
                                    }
                                }
                                echo $totalRequired;
                                ?>
                            </div>
                            <div class="stat-label">総必要人数</div>
                        </div>
                        <div class="col-md-2">
                            <div class="stat-number">
                                <?php
                                $shortageCount = 0;
                                foreach ($savedShifts as $shift) {
                                    $shortageInfo = calculateShiftShortage($shift);
                                    if ($shortageInfo['shortage'] > 0) {
                                        $shortageCount++;
                                    }
                                }
                                echo $shortageCount;
                                ?>
                            </div>
                            <div class="stat-label">不足シフト数</div>
                        </div>
                        <div class="col-md-2">
                            <div class="stat-number">
                                <?= number_format(array_sum(array_column($savedShifts, 'assigned_count')) / max(count($savedShifts), 1), 1) ?>
                            </div>
                            <div class="stat-label">平均人数/シフト</div>
                        </div>
                        <div class="col-md-2">
                            <div class="stat-number">
                                <?php
                                $recentShifts = array_filter($savedShifts, function($shift) {
                                    return strtotime($shift['event_date']) >= strtotime('-30 days');
                                });
                                echo count($recentShifts);
                                ?>
                            </div>
                            <div class="stat-label">過去30日</div>
                        </div>
                    </div>
                    
                    <!-- 不足状況サマリー -->
                    <?php if ($shiftsWithRequirement > 0): ?>
                    <hr class="my-4">
                    <h6 class="text-warning mb-3">⚠️ 不足状況サマリー</h6>
                    <div class="row">
                        <?php 
                        $statusCounts = ['shortage' => 0, 'exact' => 0, 'surplus' => 0];
                        foreach ($savedShifts as $shift) {
                            $shortageInfo = calculateShiftShortage($shift);
                            if ($shortageInfo['required'] > 0) {
                                $statusCounts[$shortageInfo['status']]++;
                            }
                        }
                        ?>
                        <div class="col-md-4">
                            <div class="d-flex align-items-center justify-content-center p-3 bg-warning bg-opacity-10 border border-warning rounded">
                                <div class="text-center">
                                    <div class="h4 text-warning mb-1"><?= $statusCounts['shortage'] ?></div>
                                    <div class="small text-warning">不足シフト</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex align-items-center justify-content-center p-3 bg-success bg-opacity-10 border border-success rounded">
                                <div class="text-center">
                                    <div class="h4 text-success mb-1"><?= $statusCounts['exact'] ?></div>
                                    <div class="small text-success">過不足なし</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex align-items-center justify-content-center p-3 bg-info bg-opacity-10 border border-info rounded">
                                <div class="text-center">
                                    <div class="h4 text-info mb-1"><?= $statusCounts['surplus'] ?></div>
                                    <div class="small text-info">余裕シフト</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <?php endif; ?>
    </div>

    <!-- 個人シフト詳細モーダル -->
    <div class="modal fade" id="personalShiftModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">👤 個人シフト詳細</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="personalShiftContent">
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">読み込み中...</span>
                        </div>
                        <p class="mt-2">情報を取得中...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 個人シフト詳細を表示
        function showPersonalShift(userId) {
            const modal = new bootstrap.Modal(document.getElementById('personalShiftModal'));
            const content = document.getElementById('personalShiftContent');
            
            // ローディング表示
            content.innerHTML = `
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">読み込み中...</span>
                    </div>
                    <p class="mt-2">情報を取得中...</p>
                </div>
            `;
            
            modal.show();
            
            // AJAX でデータ取得
            fetch(`?action=get_personal_shift&user_id=${userId}`)
                .then(response => response.json())
                .then(data => {
                    console.log('Response data:', data);
                    if (data.success && data.data) {
                        displayPersonalShift(data.data);
                    } else {
                        content.innerHTML = `
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle"></i>
                                データの取得に失敗しました。<br>
                                エラー: ${data.error || '不明なエラー'}
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    content.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i>
                            エラーが発生しました。<br>
                            詳細: ${error.message}
                        </div>
                    `;
                });
        }
        
        // 個人シフト詳細を表示
        function displayPersonalShift(data) {
            const content = document.getElementById('personalShiftContent');
            const user = data.user;
            const shifts = data.shifts;
            const skills = data.skills;
            
            let html = `
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card border-primary">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0"><i class="fas fa-user"></i> スタッフ情報</h6>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="fw-bold">名前:</td>
                                        <td>${user.name}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">ふりがな:</td>
                                        <td>${user.furigana}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">性別:</td>
                                        <td><span class="badge bg-${user.gender === 'M' ? 'primary' : 'danger'}">${user.gender === 'M' ? '♂ 男性' : '♀ 女性'}</span></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">ランク:</td>
                                        <td><span class="badge bg-${user.is_rank === 'ランナー' ? 'primary' : 'secondary'}">${user.is_rank}</span></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">高校生:</td>
                                        <td><span class="badge bg-${user.is_highschool ? 'warning' : 'info'}">${user.is_highschool ? 'はい' : 'いいえ'}</span></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card border-secondary">
                            <div class="card-header bg-secondary text-white">
                                <h6 class="mb-0"><i class="fas fa-star"></i> スキル情報</h6>
                            </div>
                            <div class="card-body">
            `;
            
            if (skills && skills.length > 0) {
                skills.forEach(skill => {
                    const badgeClass = skill.skill_level === 'できる' ? 'bg-success' : 
                                     skill.skill_level === 'まあまあできる' ? 'bg-warning text-dark' : 'bg-danger';
                    html += `
                        <div class="mb-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fw-bold">${skill.task_name}:</span>
                                <span class="badge ${badgeClass}">${skill.skill_level}</span>
                            </div>
                        </div>
                    `;
                });
            } else {
                html += '<p class="text-muted">スキル情報が登録されていません。</p>';
            }
            
            html += `
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-12">
                        <div class="card border-info">
                            <div class="card-header bg-info text-white">
                                <h6 class="mb-0"><i class="fas fa-calendar-alt"></i> 参加シフト一覧 (${shifts.length}件)</h6>
                            </div>
                            <div class="card-body">
            `;
            
            if (shifts && shifts.length > 0) {
                shifts.forEach((shift, index) => {
                    const availability = shift.availability;
                    html += `
                        <div class="card mb-3 ${index === 0 ? 'border-primary' : 'border-light'}">
                            <div class="card-header ${index === 0 ? 'bg-primary text-white' : 'bg-light'}">
                                <div class="row align-items-center">
                                    <div class="col-md-8">
                                        <h6 class="mb-1">${shift.event_type}</h6>
                                        <small>${shift.shift_name || 'シフト'}</small>
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <span class="badge bg-${shift.assigned_role === 'ランナー' ? 'primary' : 'secondary'}">${shift.assigned_role}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <td class="fw-bold">日付:</td>
                                                <td>${new Date(shift.event_date).toLocaleDateString('ja-JP')}</td>
                                            </tr>
                                            <tr>
                                                <td class="fw-bold">時間:</td>
                                                <td>${shift.start_time.substr(0,5)} - ${shift.end_time.substr(0,5)}</td>
                                            </tr>
                                            <tr>
                                                <td class="fw-bold">会場:</td>
                                                <td>${shift.venue || '未設定'}</td>
                                            </tr>
                                            <tr>
                                                <td class="fw-bold">作成日:</td>
                                                <td>${new Date(shift.assignment_created_at).toLocaleDateString('ja-JP')}</td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <td class="fw-bold">出勤可能:</td>
                                                <td><span class="badge bg-${availability && availability.available ? 'success' : 'danger'}">${availability && availability.available ? '可能' : '不可'}</span></td>
                                            </tr>
                                            ${availability && availability.available ? `
                                            <tr>
                                                <td class="fw-bold">可能時間:</td>
                                                <td>${availability.available_start_time ? availability.available_start_time.substr(0,5) + ' - ' + availability.available_end_time.substr(0,5) : '未設定'}</td>
                                            </tr>
                                            ` : ''}
                                            <tr>
                                                <td class="fw-bold">備考:</td>
                                                <td>${availability && availability.note || '特になし'}</td>
                                            </tr>
                                            ${shift.assignment_note ? `
                                            <tr>
                                                <td class="fw-bold">割当備考:</td>
                                                <td>${shift.assignment_note}</td>
                                            </tr>
                                            ` : ''}
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                });
            } else {
                html += '<p class="text-muted text-center">参加しているシフトがありません。</p>';
            }
            
            html += `
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            content.innerHTML = html;
        }
        
        // 🆕 編集モードの切り替え
        function toggleEditMode(eventId) {
            const editArea = document.getElementById(`editArea_${eventId}`);
            if (editArea.style.display === 'none') {
                editArea.style.display = 'block';
            } else {
                editArea.style.display = 'none';
            }
        }
        
        // 🆕 スタッフをシフトに追加
        function addStaffToShift(eventId, userId, userName) {
            if (!confirm(`${userName}をシフトに追加しますか？`)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'add_staff');
            formData.append('event_id', eventId);
            formData.append('user_id', userId);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    window.location.reload(); // ページを更新して変更を反映
                } else {
                    alert('エラー: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('通信エラーが発生しました');
            });
        }
        
        // 🆕 スタッフをシフトから削除
        function removeStaffFromShift(eventId, userId, userName) {
            if (!confirm(`${userName}をシフトから削除しますか？`)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'remove_staff');
            formData.append('event_id', eventId);
            formData.append('user_id', userId);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    window.location.reload(); // ページを更新して変更を反映
                } else {
                    alert('エラー: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('通信エラーが発生しました');
            });
        }
        
        // 🆕 シフト変更を保存
        function saveShiftChanges(eventId) {
            alert('変更が保存されました');
            toggleEditMode(eventId);
        }
    </script>
</body>
</html>
