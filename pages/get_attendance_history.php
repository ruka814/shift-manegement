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
    
    // 🔄 改善：出勤履歴を取得（出勤可能日と実際の割当日の両方）
    // 1. 出勤可能として登録された日
    $stmt = $pdo->prepare("
        SELECT 
            a.work_date,
            a.available_start_time,
            a.available_end_time,
            a.note,
            a.updated_at,
            e.event_type,
            e.description as event_description,
            e.venue,
            e.start_time,
            e.end_time,
            'availability' as record_type,
            '出勤可能' as status,
            NULL as assigned_role
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
    ");
    $stmt->execute([$userId, $userId]);
    $availabilityRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. 実際にシフトに割り当てられた日
    $stmt = $pdo->prepare("
        SELECT 
            e.event_date as work_date,
            e.start_time as available_start_time,
            e.end_time as available_end_time,
            CONCAT('役割: ', a.assigned_role, 
                   CASE WHEN a.note IS NOT NULL AND a.note != '' 
                        THEN CONCAT(' (', a.note, ')') 
                        ELSE '' END) as note,
            a.created_at as updated_at,
            e.event_type,
            e.description as event_description,
            e.venue,
            e.start_time,
            e.end_time,
            'assignment' as record_type,
            '出勤確定' as status,
            a.assigned_role
        FROM assignments a
        JOIN events e ON a.event_id = e.id
        WHERE a.user_id = ?
    ");
    $stmt->execute([$userId]);
    $assignmentRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 両方のデータを統合し、日付でユニーク化（assignmentを優先）
    $attendanceMap = [];
    
    // まず出勤可能日を追加
    foreach ($availabilityRecords as $record) {
        $attendanceMap[$record['work_date']] = $record;
    }
    
    // 次に割当日を追加（重複する場合は割当日を優先）
    foreach ($assignmentRecords as $record) {
        $attendanceMap[$record['work_date']] = $record;
    }
    
    // 配列として再構成し、日付順でソート
    $attendance = array_values($attendanceMap);
    usort($attendance, function($a, $b) {
        return strcmp($b['work_date'], $a['work_date']); // 降順
    });
    
    // 最新100件に制限
    $attendance = array_slice($attendance, 0, 100);
    
    // 統計情報を計算
    $stats = [
        'total_days' => count($attendance),
        'weekend_days' => 0,
        'event_days' => 0,
        'assignment_days' => 0,
        'availability_days' => 0,
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
        
        // 記録タイプの集計
        if ($record['record_type'] === 'assignment') {
            $stats['assignment_days']++;
        } else {
            $stats['availability_days']++;
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
