<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

echo "<h2>🔧 シフト作成デバッグテスト</h2>";

try {
    // 1. イベント数確認
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM events");
    $eventCount = $stmt->fetch()['count'];
    echo "<p><strong>✅ イベント数:</strong> {$eventCount}件</p>";
    
    // 2. 最新のイベントを取得
    $stmt = $pdo->query("SELECT * FROM events ORDER BY id DESC LIMIT 1");
    $event = $stmt->fetch();
    
    if (!$event) {
        echo "<p style='color: red;'>❌ イベントが見つかりません</p>";
        exit;
    }
    
    echo "<p><strong>✅ テスト対象イベント:</strong> ID {$event['id']} - {$event['event_type']}</p>";
    echo "<p><strong>日付:</strong> {$event['event_date']}</p>";
    echo "<p><strong>必要人数:</strong> {$event['needs']}</p>";
    
    // 3. 必要人数の解析テスト
    $needs = parseNeeds($event['needs']);
    echo "<p><strong>✅ 必要人数解析結果:</strong></p>";
    echo "<pre>" . print_r($needs, true) . "</pre>";
    
    // 4. 出勤可能スタッフの確認
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM availability 
        WHERE work_date = ? AND available = 1
    ");
    $stmt->execute([$event['event_date']]);
    $availableCount = $stmt->fetch()['count'];
    
    echo "<p><strong>✅ 出勤可能スタッフ数:</strong> {$availableCount}人</p>";
    
    if ($availableCount == 0) {
        echo "<p style='color: orange;'>⚠️ 出勤可能スタッフがいません。サンプルデータを作成します...</p>";
        
        // サンプル出勤データを作成
        $stmt = $pdo->query("SELECT id FROM users LIMIT 5");
        $users = $stmt->fetchAll();
        
        foreach ($users as $user) {
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO availability (user_id, work_date, available, available_start_time, available_end_time)
                VALUES (?, ?, 1, '10:00:00', '22:00:00')
            ");
            $stmt->execute([$user['id'], $event['event_date']]);
        }
        
        echo "<p style='color: green;'>✅ サンプル出勤データを作成しました</p>";
        
        // 再確認
        $stmt->execute([$event['event_date']]);
        $availableCount = $stmt->fetch()['count'];
        echo "<p><strong>新しい出勤可能スタッフ数:</strong> {$availableCount}人</p>";
    }
    
    // 5. 出勤可能スタッフの詳細
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.name, u.furigana, u.gender, u.is_rank
        FROM users u
        JOIN availability a ON u.id = a.user_id
        WHERE a.work_date = ? AND a.available = 1
    ");
    $stmt->execute([$event['event_date']]);
    $availableUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p><strong>✅ 出勤可能スタッフ詳細:</strong></p>";
    echo "<ul>";
    foreach ($availableUsers as $user) {
        echo "<li>{$user['name']} (ID: {$user['id']}, ランク: {$user['is_rank']})</li>";
    }
    echo "</ul>";
    
    // 6. performAutoAssignment関数のテスト
    echo "<h3>🎯 自動割当テスト</h3>";
    try {
        $result = performAutoAssignment($pdo, $event['id']);
        echo "<p style='color: green;'>✅ performAutoAssignment関数は正常に動作しました</p>";
        
        echo "<p><strong>割当結果:</strong></p>";
        foreach ($result['assignments'] as $role => $assignments) {
            echo "<p><strong>{$role}:</strong> " . count($assignments) . "人</p>";
            foreach ($assignments as $assignment) {
                echo "<p>&nbsp;&nbsp;- {$assignment['user']['name']} (スキル: {$assignment['skill_level']})</p>";
            }
        }
        
        // 7. シミュレーション結果の表示
        echo "<h3>📋 期待される表示内容</h3>";
        
        // 出勤者名のリスト
        $allAssignedUsers = [];
        foreach ($result['assignments'] as $role => $roleAssignments) {
            foreach ($roleAssignments as $assignment) {
                if (!in_array($assignment['user']['name'], $allAssignedUsers)) {
                    $allAssignedUsers[] = $assignment['user']['name'];
                }
            }
        }
        
        $namesList = implode('、', $allAssignedUsers);
        $eventDate = formatDate($event['event_date']);
        
        echo "<div style='background-color: #d1f2eb; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        echo "<h4>🎉 シフトを自動作成しました</h4>";
        echo "<p><strong>出勤日:</strong> {$eventDate}</p>";
        echo "<p><strong>出勤者:</strong> {$namesList}</p>";
        echo "<p><small>総" . count($allAssignedUsers) . "名の役割を割当完了</small></p>";
        echo "</div>";
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ performAutoAssignment関数でエラー: " . $e->getMessage() . "</p>";
        
        // エラーの詳細分析
        echo "<h4>🔍 エラー詳細分析</h4>";
        
        // スキルデータの確認
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM skills");
        $skillCount = $stmt->fetch()['count'];
        echo "<p>スキルデータ数: {$skillCount}件</p>";
        
        if ($skillCount == 0) {
            echo "<p style='color: orange;'>⚠️ スキルデータがありません。作成します...</p>";
            
            // 基本スキルを作成
            foreach ($availableUsers as $user) {
                foreach ($needs as $role => $need) {
                    $stmt = $pdo->prepare("
                        INSERT IGNORE INTO skills (user_id, task_type_id, skill_level)
                        SELECT ?, tt.id, 'できる'
                        FROM task_types tt WHERE tt.name = ?
                    ");
                    $stmt->execute([$user['id'], $role]);
                }
            }
            echo "<p style='color: green;'>✅ 基本スキルデータを作成しました</p>";
        }
        
        // 再テスト
        try {
            $result = performAutoAssignment($pdo, $event['id']);
            echo "<p style='color: green;'>✅ 修正後: performAutoAssignment関数が正常に動作しました</p>";
        } catch (Exception $e2) {
            echo "<p style='color: red;'>❌ 修正後もエラー: " . $e2->getMessage() . "</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>システムエラー: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='shift_assignment.php'>シフト作成ページに戻る</a></p>";
?>
