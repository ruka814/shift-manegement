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
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>保存済みシフト一覧 - シフト管理システム</title>
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
            ?>
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-1"><?= h($shift['event_type']) ?></h6>
                                <small class="text-muted">
                                    ID: <?= $shift['id'] ?>
                                </small>
                            </div>
                            <div class="d-flex flex-column align-items-end">
                                <span class="badge bg-success mb-1">保存済み</span>
                                <span class="badge <?= $creationMethod['badge'] ?>"><?= $creationMethod['text'] ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="mb-2">
                            <span class="text-primary fw-bold"><?= formatDate($shift['event_date']) ?></span><br>
                            <span class="text-secondary"><?= formatTime($shift['start_time']) ?> - <?= formatTime($shift['end_time']) ?></span>
                        </div>
                        
                        <div class="mb-3">
                            <span class="badge bg-primary"><?= $shift['assigned_count'] ?>人割当</span>
                            <?php if ($shift['venue']): ?>
                            <span class="badge bg-info ms-1"><?= h($shift['venue']) ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <!-- スタッフ一覧表示 -->
                        <div class="mb-3">
                            <h6 class="mb-2">👥 割当スタッフ (<?= count($assignedStaff) ?>名)</h6>
                            <div class="row g-2">
                                <?php foreach ($assignedStaff as $index => $staff): ?>
                                <div class="col-12">
                                    <div class="border rounded p-2 bg-light">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <div class="fw-bold small"><?= $index + 1 ?>. <?= h($staff['name']) ?></div>
                                                <div class="small">
                                                    <?php if ($staff['is_rank'] === 'ランナー'): ?>
                                                    <span class="badge bg-primary btn-sm">ランナー</span>
                                                    <?php else: ?>
                                                    <span class="badge bg-secondary btn-sm">その他</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <span class="badge bg-success"><?= $staff['gender'] === 'M' ? '♂' : '♀' ?></span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="text-muted small">
                            <strong>保存日時:</strong><br>
                            <?= date('Y/m/d H:i', strtotime($shift['shift_created_at'])) ?>
                    </div>
                    <div class="card-footer">
                        <form method="POST" class="w-100" 
                              onsubmit="return confirm('シフトを削除しますか？この操作は取り消せません。')">
                            <input type="hidden" name="action" value="delete_shift">
                            <input type="hidden" name="event_id" value="<?= $shift['id'] ?>">
                            <button type="submit" class="btn btn-outline-danger w-100">
                                🗑️ 削除
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
