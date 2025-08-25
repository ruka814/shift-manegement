<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

$message = '';

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
           MIN(a.created_at) as shift_created_at
    FROM events e
    JOIN assignments a ON e.id = a.event_id
    GROUP BY e.id, e.event_date, e.start_time, e.end_time, e.event_type, e.venue, e.needs, e.description, e.created_at, e.updated_at
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
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.gender, u.is_rank, a.assigned_role
        FROM assignments a
        JOIN users u ON a.user_id = u.id
        WHERE a.event_id = ?
        ORDER BY a.assigned_role, u.furigana
    ");
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

// 出勤可能だが割当されなかったスタッフを取得
function getUnassignedAvailableStaff($pdo, $eventId) {
    // イベント情報を取得
    $stmt = $pdo->prepare("SELECT event_date FROM events WHERE id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    
    if (!$event) {
        return [];
    }
    
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.name, u.gender, u.is_rank,
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
    return $stmt->fetchAll();
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
                                <div class="col-md-6">
                                    <div class="d-flex align-items-center p-2 bg-success bg-opacity-10 border border-success rounded">
                                        <div class="me-3">
                                            <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 14px; font-weight: bold;">
                                                <?= $index + 1 ?>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="fw-bold text-dark"><?= h($staff['name']) ?></div>
                                            <div class="d-flex gap-1 mt-1">
                                                <?php if ($staff['is_rank'] === 'ランナー'): ?>
                                                <span class="badge bg-primary">ランナー</span>
                                                <?php else: ?>
                                                <span class="badge bg-secondary">その他</span>
                                                <?php endif; ?>
                                                <span class="badge bg-success"><?= $staff['gender'] === 'M' ? '♂' : '♀' ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- 未割当の出勤可能スタッフ -->
                        <?php if (!empty($unassignedStaff)): ?>
                        <div class="mb-3">
                            <h6 class="text-warning mb-2">⚠️ 出勤可能だが未割当 (<?= count($unassignedStaff) ?>名)</h6>
                            <div class="row g-3">
                                <?php foreach ($unassignedStaff as $index => $staff): ?>
                                <div class="col-md-6">
                                    <div class="d-flex align-items-center p-2 bg-warning bg-opacity-10 border border-warning rounded">
                                        <div class="me-3">
                                            <div class="bg-warning text-dark rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 14px; font-weight: bold;">
                                                <?= $index + 1 ?>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="fw-bold text-dark"><?= h($staff['name']) ?></div>
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
                        
                        <div class="text-muted small">
                            <strong>保存日時:</strong><br>
                            <?= date('Y/m/d H:i', strtotime($shift['shift_created_at'])) ?>
                    </div>
                    <div class="card-footer bg-light text-end">
                        <form method="POST" class="d-inline" 
                              onsubmit="return confirm('このシフトを削除しますか？\n\nこの操作は取り消せません。')">
                            <input type="hidden" name="action" value="delete_shift">
                            <input type="hidden" name="event_id" value="<?= $shift['id'] ?>">
                            <button type="submit" class="btn btn-outline-danger">
                                <i class="fas fa-trash-alt me-1"></i>削除
                            </button>
                        </form>
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
                        <div class="col-md-3">
                            <div class="stat-number"><?= count($savedShifts) ?></div>
                            <div class="stat-label">総シフト数</div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-number"><?= array_sum(array_column($savedShifts, 'assigned_count')) ?></div>
                            <div class="stat-label">総割当人数</div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-number">
                                <?= number_format(array_sum(array_column($savedShifts, 'assigned_count')) / max(count($savedShifts), 1), 1) ?>
                            </div>
                            <div class="stat-label">平均人数/シフト</div>
                        </div>
                        <div class="col-md-3">
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
                </div>
            </div>
        </div>
        
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
