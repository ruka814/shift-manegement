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

// 各シフトの詳細情報取得
function getShiftSummary($pdo, $eventId) {
    $stmt = $pdo->prepare("
        SELECT a.assigned_role, COUNT(*) as count,
               GROUP_CONCAT(u.name ORDER BY u.furigana SEPARATOR ', ') as staff_names
        FROM assignments a
        JOIN users u ON a.user_id = u.id
        WHERE a.event_id = ?
        GROUP BY a.assigned_role
    ");
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

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
                $shiftSummary = getShiftSummary($pdo, $shift['id']); 
                $creationMethod = getCreationMethod($pdo, $shift['id']);
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
                        <div class="mb-3">
                            <strong>📅 日時</strong><br>
                            <span class="text-primary"><?= formatDate($shift['event_date']) ?></span><br>
                            <span class="text-secondary"><?= formatTime($shift['start_time']) ?> - <?= formatTime($shift['end_time']) ?></span>
                        </div>
                        
                        <?php if ($shift['venue']): ?>
                        <div class="mb-3">
                            <strong>📍 会場</strong><br>
                            <span class="badge bg-info"><?= h($shift['venue']) ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <strong>👥 割当人数</strong>
                            <span class="badge bg-primary ms-2"><?= $shift['assigned_count'] ?>人</span>
                        </div>
                        
                        <div class="mb-3">
                            <strong>📋 役割別人数</strong><br>
                            <?php foreach ($shiftSummary as $summary): ?>
                            <div class="d-flex justify-content-between mt-1">
                                <span class="fw-bold"><?= h($summary['assigned_role']) ?>:</span>
                                <span class="badge bg-secondary"><?= $summary['count'] ?>人</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if ($shift['description']): ?>
                        <div class="mb-3">
                            <strong>📝 説明</strong><br>
                            <small class="text-muted"><?= h($shift['description']) ?></small>
                        </div>
                        <?php endif; ?>
                        
                        <div class="text-muted small">
                            <strong>保存日時:</strong><br>
                            <?= date('Y/m/d H:i', strtotime($shift['shift_created_at'])) ?>
                        </div>
                    </div>
                    <div class="card-footer">
                        <div class="d-grid gap-2">
                            <a href="shift_assignment.php?event_id=<?= $shift['id'] ?>&load_saved=1" 
                               class="btn btn-outline-primary">
                                👁️ 詳細表示
                            </a>
                            <div class="d-flex gap-2">
                                <a href="shift_assignment.php?event_id=<?= $shift['id'] ?>" 
                                   class="btn btn-outline-secondary flex-fill">
                                    ✏️ 再作成
                                </a>
                                <form method="POST" class="flex-fill" 
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
