<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config/database.php';

try {
    $userId = $_GET['user_id'] ?? null;
    
    if (!$userId) {
        throw new Exception('ユーザーIDが指定されていません');
    }
    
    // ユーザー存在確認
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        throw new Exception('指定されたユーザーが見つかりません');
    }
    
    // 🔄 改善：出勤履歴を取得（同じ日付の場合は最新の更新日時のもののみ）
    // 各日付グループの最新レコードのIDを取得してから結合する方式
    $stmt = $pdo->prepare("
        SELECT a.*, e.event_type, e.description as event_description,
               e.venue, a.updated_at
        FROM availability a
        LEFT JOIN events e ON a.event_id = e.id
        INNER JOIN (
            SELECT work_date, MAX(updated_at) as max_updated_at
            FROM availability
            WHERE user_id = ? AND available = 1
            GROUP BY work_date
        ) latest ON a.work_date = latest.work_date 
                AND a.updated_at = latest.max_updated_at
        WHERE a.user_id = ? AND a.available = 1
        ORDER BY a.work_date DESC
        LIMIT 100
    ");
    $stmt->execute([$userId, $userId]);
    $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 統計情報を計算
    $stats = [
        'total_days' => count($attendance),
        'weekend_days' => 0,
        'event_days' => 0,
        'this_month' => 0
    ];
    
    $currentMonth = date('Y-m');
    
    foreach ($attendance as $record) {
        $date = new DateTime($record['work_date']);
        $dayOfWeek = $date->format('w');
        
        // 土日の判定
        if ($dayOfWeek == 0 || $dayOfWeek == 6) {
            $stats['weekend_days']++;
        }
        
        // イベント出勤の判定
        if (!empty($record['event_type'])) {
            $stats['event_days']++;
        }
        
        // 今月の出勤の判定
        if (substr($record['work_date'], 0, 7) === $currentMonth) {
            $stats['this_month']++;
        }
    }
    
    // レスポンスを返す
    echo json_encode([
        'success' => true,
        'user' => $user,
        'attendance' => $attendance,
        'stats' => $stats
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("get_attendance_history.php: エラー - " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log("get_attendance_history.php: データベースエラー - " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'データベースエラーが発生しました'
    ], JSON_UNESCAPED_UNICODE);
}
?>
