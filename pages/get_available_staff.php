<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config/database.php';

try {
    $eventId = $_GET['event_id'] ?? null;
    
    if (!$eventId) {
        throw new Exception('イベントIDが指定されていません');
    }
    
    // イベント情報を取得
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    
    if (!$event) {
        throw new Exception('指定されたイベントが見つかりません');
    }
    
    // 出勤可能スタッフを取得（最新の更新データのみ）
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.*, a.available_start_time, a.available_end_time, a.updated_at
        FROM users u
        INNER JOIN availability a ON u.id = a.user_id
        INNER JOIN (
            SELECT user_id, work_date, MAX(updated_at) as max_updated_at
            FROM availability
            WHERE work_date = ? AND available = 1
            GROUP BY user_id, work_date
        ) latest ON a.user_id = latest.user_id 
                AND a.work_date = latest.work_date
                AND a.updated_at = latest.max_updated_at
        WHERE a.work_date = ? AND a.available = 1
        ORDER BY u.is_rank DESC, u.furigana ASC
    ");
    $stmt->execute([$event['event_date'], $event['event_date']]);
    $availableStaff = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ランナーと非ランナーに分類
    $runners = array_filter($availableStaff, function($staff) { 
        return $staff['is_rank'] === 'ランナー'; 
    });
    $nonRunners = array_filter($availableStaff, function($staff) { 
        return $staff['is_rank'] !== 'ランナー'; 
    });
    
    // スキル情報も取得
    foreach ($availableStaff as &$staff) {
        $skillStmt = $pdo->prepare("
            SELECT tt.name as task_name, s.skill_level
            FROM skills s
            JOIN task_types tt ON s.task_type_id = tt.id
            WHERE s.user_id = ? AND s.skill_level = 'できる'
        ");
        $skillStmt->execute([$staff['id']]);
        $staff['skills'] = $skillStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 統計情報
    $stats = [
        'total_available' => count($availableStaff),
        'runners_count' => count($runners),
        'non_runners_count' => count($nonRunners),
        'male_count' => count(array_filter($availableStaff, function($s) { return $s['gender'] === 'M'; })),
        'female_count' => count(array_filter($availableStaff, function($s) { return $s['gender'] === 'F'; }))
    ];
    
    // レスポンスを返す
    echo json_encode([
        'success' => true,
        'event' => $event,
        'available_staff' => $availableStaff,
        'runners' => array_values($runners),
        'non_runners' => array_values($nonRunners),
        'stats' => $stats
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("get_available_staff.php: エラー - " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log("get_available_staff.php: データベースエラー - " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'データベースエラーが発生しました'
    ], JSON_UNESCAPED_UNICODE);
}
?>
