<?php
// 共通関数

/**
 * HTMLエスケープ
 */
function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * 日付フォーマット
 */
function formatDate($date) {
    return date('Y年m月d日', strtotime($date));
}

/**
 * 時間フォーマット
 */
function formatTime($time) {
    return date('H:i', strtotime($time));
}

/**
 * スキルレベルの表示
 */
function getSkillBadge($level) {
    switch($level) {
        case 'できる':
            return '<span class="badge bg-success">できる</span>';
        case 'まあまあできる':
            return '<span class="badge bg-warning">まあまあできる</span>';
        case 'できない':
            return '<span class="badge bg-danger">できない</span>';
        default:
            return '<span class="badge bg-secondary">未設定</span>';
    }
}

/**
 * 性別の表示
 */
function getGenderText($gender) {
    return $gender === 'M' ? '男性' : '女性';
}

/**
 * ランク表示
 */
function getRankBadge($rank) {
    return $rank === 'ランナー' 
        ? '<span class="badge bg-primary">ランナー</span>'
        : '<span class="badge bg-info">ランナー以外</span>';
}

/**
 * JSONの必要人数を解析
 */
function parseNeeds($needsJson) {
    $needs = json_decode($needsJson, true);
    if (!$needs) return [];
    
    $result = [];
    foreach ($needs as $role => $count) {
        if (is_string($count) && strpos($count, '-') !== false) {
            // 範囲指定の場合（例: "1-2"）
            $range = explode('-', $count);
            $result[$role] = [
                'min' => (int)$range[0],
                'max' => (int)$range[1],
                'display' => $count . '人'
            ];
        } else {
            // 固定数の場合
            $result[$role] = [
                'min' => (int)$count,
                'max' => (int)$count,
                'display' => $count . '人'
            ];
        }
    }
    return $result;
}

/**
 * 出勤可能時間チェック
 */
function isTimeOverlap($eventStart, $eventEnd, $availStart, $availEnd) {
    $eventStartTime = strtotime($eventStart);
    $eventEndTime = strtotime($eventEnd);
    $availStartTime = strtotime($availStart);
    $availEndTime = strtotime($availEnd);
    
    return !($eventEndTime <= $availStartTime || $eventStartTime >= $availEndTime);
}

/**
 * スタッフがイベントに参加可能かチェック
 */
function canUserWorkAtEvent($pdo, $userId, $eventId) {
    // まず一般的な出勤可能情報（event_id IS NULL または event_id = 0）を確認
    $stmt = $pdo->prepare("
        SELECT a.available, a.available_start_time, a.available_end_time,
               e.start_time, e.end_time, e.event_date
        FROM availability a
        JOIN events e ON e.id = ?
        WHERE a.user_id = ? AND (a.event_id IS NULL OR a.event_id = 0) AND a.work_date = e.event_date AND a.available = 1
    ");
    $stmt->execute([$eventId, $userId]);
    $availability = $stmt->fetch();
    
    if (!$availability) {
        // 一般的な出勤情報がない場合、特定のイベント用の出勤情報を確認
        $stmt = $pdo->prepare("
            SELECT a.available, a.available_start_time, a.available_end_time,
                   e.start_time, e.end_time
            FROM availability a
            JOIN events e ON a.event_id = e.id
            WHERE a.user_id = ? AND a.event_id = ? AND a.available = 1
        ");
        $stmt->execute([$userId, $eventId]);
        $availability = $stmt->fetch();
    }
    
    if (!$availability) return false;
    
    return isTimeOverlap(
        $availability['start_time'],
        $availability['end_time'],
        $availability['available_start_time'],
        $availability['available_end_time']
    );
}

/**
 * 15分単位の時間選択肢を生成
 */
function generateTimeOptions($selectedTime = '') {
    $options = '<option value="">選択してください</option>';
    
    for ($hour = 0; $hour < 24; $hour++) {
        for ($minute = 0; $minute < 60; $minute += 15) {
            $timeValue = sprintf('%02d:%02d', $hour, $minute);
            $timeDisplay = sprintf('%02d:%02d', $hour, $minute);
            $selected = ($timeValue === $selectedTime) ? 'selected' : '';
            $options .= "<option value=\"{$timeValue}\" {$selected}>{$timeDisplay}</option>";
        }
    }
    
    return $options;
}

/**
 * 時間の選択肢を生成（0-23時）
 */
function generateHourOptions($selectedHour = '') {
    $options = '<option value="">時間</option>';
    
    for ($hour = 0; $hour < 24; $hour++) {
        $hourValue = sprintf('%02d', $hour);
        $selected = ($hourValue === $selectedHour) ? 'selected' : '';
        $options .= "<option value=\"{$hourValue}\" {$selected}>{$hour}時</option>";
    }
    
    return $options;
}

/**
 * 時間の選択肢を生成（高校生用制限あり）
 */
function generateHourOptionsForHighSchool($selectedHour = '') {
    $options = '<option value="">時間</option>';
    
    for ($hour = 0; $hour < 24; $hour++) {
        // 高校生は22時01分から4時59分の間は選択不可（22:00までは可能）
        if ($hour > 22 || $hour < 5) {
            continue;
        }
        
        $hourValue = sprintf('%02d', $hour);
        $selected = ($hourValue === $selectedHour) ? 'selected' : '';
        $options .= "<option value=\"{$hourValue}\" {$selected}>{$hour}時</option>";
    }
    
    return $options;
}

/**
 * 分の選択肢を生成（15分単位）
 */
function generateMinuteOptions($selectedMinute = '') {
    $options = '<option value="">分</option>';
    
    for ($minute = 0; $minute < 60; $minute += 15) {
        $minuteValue = sprintf('%02d', $minute);
        $selected = ($minuteValue === $selectedMinute) ? 'selected' : '';
        $options .= "<option value=\"{$minuteValue}\" {$selected}>{$minute}分</option>";
    }
    
    return $options;
}

/**
 * 時間文字列から時と分を分離
 */
function parseTimeString($timeString) {
    if (empty($timeString)) {
        return ['hour' => '', 'minute' => ''];
    }
    
    $parts = explode(':', $timeString);
    return [
        'hour' => $parts[0] ?? '',
        'minute' => $parts[1] ?? ''
    ];
}

/**
 * 15分単位の時間かチェック
 */
function isValidQuarterTime($time) {
    if (empty($time)) return true; // 空の場合は有効とする
    
    $timeParts = explode(':', $time);
    if (count($timeParts) !== 2) return false;
    
    $minutes = (int)$timeParts[1];
    return $minutes % 15 === 0;
}

/**
 * 高校生の時間制限チェック
 */
function isValidHighSchoolTime($hour) {
    if (empty($hour)) return true; // 空の場合は有効とする
    
    $hourInt = (int)$hour;
    // 高校生は22時01分から4時59分の間は選択不可（22:00までは可能）
    return !($hourInt > 22 || $hourInt < 5);
}

/**
 * 時間バリデーションエラーメッセージ
 */
function validateTimeFormat($time, $fieldName = '時間') {
    if (!isValidQuarterTime($time)) {
        return "{$fieldName}は15分単位（00, 15, 30, 45）で入力してください";
    }
    return null;
}

/**
 * 高校生の時間制限バリデーション
 */
function validateHighSchoolTime($hour, $isHighSchool, $fieldName = '時間') {
    if ($isHighSchool && !isValidHighSchoolTime($hour)) {
        return "高校生は23時から4時の間は選択できません";
    }
    return null;
}

/**
 * 日本語名前を五十音順でソートするための比較関数
 */
function compareJapaneseName($a, $b) {
    // ひらがなに変換して比較
    $nameA = mb_convert_kana($a, 'c', 'UTF-8'); // カタカナをひらがなに変換
    $nameB = mb_convert_kana($b, 'c', 'UTF-8');
    
    // 五十音順の基本的な比較
    return strcmp($nameA, $nameB);
}

/**
 * ユーザー配列をランク順かつ五十音順でソート
 */
function sortUsersByRankAndName($users) {
    usort($users, function($a, $b) {
        // まずランクで比較（ランナーが先）
        $rankA = ($a['is_rank'] === 'ランナー') ? 0 : 1;
        $rankB = ($b['is_rank'] === 'ランナー') ? 0 : 1;
        
        if ($rankA !== $rankB) {
            return $rankA - $rankB;
        }
        
        // 同じランク内では五十音順
        // ふりがながある場合はふりがなで、ない場合は名前で比較
        $nameA = !empty($a['furigana']) ? $a['furigana'] : $a['name'];
        $nameB = !empty($b['furigana']) ? $b['furigana'] : $b['name'];
        
        // ひらがなに統一して比較
        $nameA = mb_convert_kana($nameA, 'c', 'UTF-8');
        $nameB = mb_convert_kana($nameB, 'c', 'UTF-8');
        
        return strcmp($nameA, $nameB);
    });
    
    return $users;
}

/**
 * ユーザー配列を純粋に五十音順でソート（ランク無視）
 */
function sortUsersByNameOnly($users) {
    usort($users, function($a, $b) {
        // ふりがながある場合はふりがなで、ない場合は名前で比較
        $nameA = !empty($a['furigana']) ? $a['furigana'] : $a['name'];
        $nameB = !empty($b['furigana']) ? $b['furigana'] : $b['name'];
        
        // ひらがなに統一して比較
        $nameA = mb_convert_kana($nameA, 'c', 'UTF-8');
        $nameB = mb_convert_kana($nameB, 'c', 'UTF-8');
        
        return strcmp($nameA, $nameB);
    });
    
    return $users;
}

/**
 * アラートメッセージの表示
 */
function showAlert($type, $message) {
    return "<div class='alert alert-{$type} alert-dismissible fade show' role='alert'>
                {$message}
                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
            </div>";
}

/**
 * ページネーション
 */
function getPagination($currentPage, $totalPages, $url) {
    if ($totalPages <= 1) return '';
    
    $html = '<nav><ul class="pagination justify-content-center">';
    
    // 前のページ
    if ($currentPage > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $url . '?page=' . ($currentPage - 1) . '">前へ</a></li>';
    }
    
    // ページ番号
    for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++) {
        $active = $i == $currentPage ? 'active' : '';
        $html .= '<li class="page-item ' . $active . '"><a class="page-link" href="' . $url . '?page=' . $i . '">' . $i . '</a></li>';
    }
    
    // 次のページ
    if ($currentPage < $totalPages) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $url . '?page=' . ($currentPage + 1) . '">次へ</a></li>';
    }
    
    $html .= '</ul></nav>';
    return $html;
}

/**
 * ランナーのスキル表示（コース・ブッフェ能力を特別表示）
 */
function getRunnerSkillDisplay($userId, $pdo) {
    // ランナー専用スキル（コース・ブッフェ）で「できる」もののみ取得
    $runnerSkillsStmt = $pdo->prepare("
        SELECT tt.name, s.skill_level 
        FROM skills s 
        JOIN task_types tt ON s.task_type_id = tt.id 
        WHERE s.user_id = ? AND tt.name IN ('コースランナー', 'ブッフェランナー') AND s.skill_level = 'できる'
        ORDER BY tt.name
    ");
    $runnerSkillsStmt->execute([$userId]);
    $runnerSkills = $runnerSkillsStmt->fetchAll();
    
    // 一般スキルを取得
    $generalSkillsStmt = $pdo->prepare("
        SELECT tt.name, s.skill_level 
        FROM skills s 
        JOIN task_types tt ON s.task_type_id = tt.id 
        WHERE s.user_id = ? AND tt.name NOT IN ('コースランナー', 'ブッフェランナー')
        ORDER BY tt.name
    ");
    $generalSkillsStmt->execute([$userId]);
    $generalSkills = $generalSkillsStmt->fetchAll();
    
    $html = '';
    
    // ランナースキルを特別表示（「できる」の場合のみ）
    if (count($runnerSkills) > 0) {
        $html .= '<div class="runner-skills mb-2">';
        $html .= '<div class="fw-bold text-primary small mb-1"><i class="fas fa-running"></i> ランナースキル</div>';
        
        $courseSkill = false;
        $buffetSkill = false;
        
        foreach ($runnerSkills as $skill) {
            if ($skill['name'] === 'コースランナー') {
                $courseSkill = true;
            } elseif ($skill['name'] === 'ブッフェランナー') {
                $buffetSkill = true;
            }
        }
        
        $html .= '<div class="d-flex gap-1 flex-wrap">';
        
        // コースランナー表示（できる場合のみ）
        if ($courseSkill) {
            $html .= "<span class=\"badge bg-success text-white me-1\"><i class=\"fas fa-utensils\"></i> コース</span>";
        }
        
        // ブッフェランナー表示（できる場合のみ）
        if ($buffetSkill) {
            $html .= "<span class=\"badge bg-warning text-dark\"><i class=\"fas fa-server\"></i> ブッフェ</span>";
        }
        
        // 両方できる場合の特別表示
        if ($courseSkill && $buffetSkill) {
            $html .= '<span class="badge bg-primary text-white ms-1"><i class="fas fa-crown"></i> 両方対応</span>';
        }
        
        $html .= '</div></div>';
    }
    
    // 一般スキルの表示
    if (count($generalSkills) > 0) {
        $html .= '<div class="general-skills">';
        $html .= '<div class="fw-bold text-secondary small mb-1"><i class="fas fa-tools"></i> 一般スキル</div>';
        foreach ($generalSkills as $skill) {
            $html .= '<div class="skill-item d-flex align-items-center mb-1">';
            $html .= '<small class="text-muted me-2">' . h($skill['name']) . ':</small>';
            $html .= getSkillBadge($skill['skill_level']);
            $html .= '</div>';
        }
        $html .= '</div>';
    }
    
    return $html ?: '<small class="text-muted">スキル未設定</small>';
}

/**
 * スキルレベルに応じたバッジクラスを取得
 */
function getSkillBadgeClass($skillLevel) {
    switch ($skillLevel) {
        case 'できる':
            return 'bg-success';
        case 'まあまあできる':
            return 'bg-warning text-dark';
        case 'できない':
            return 'bg-danger';
        default:
            return 'bg-secondary';
    }
}

/**
 * ランナーかどうかを判定
 */
function isRunner($rank) {
    return $rank === 'ランナー';
}

/**
 * 日本語曜日の表示
 */
function formatJapaneseWeekday($date) {
    $weekdays = ['日', '月', '火', '水', '木', '金', '土'];
    $dayOfWeek = date('w', strtotime($date));
    return $weekdays[$dayOfWeek] . '曜日';
}

/**
 * 会場に応じたバッジクラスを取得
 */
function getVenueBadgeClass($venue) {
    switch ($venue) {
        case 'ローズ全':
            return 'bg-danger';
        case 'ローズI':
            return 'bg-warning text-dark';
        case 'ローズII':
            return 'bg-info';
        case 'クリスタル':
            return 'bg-primary';
        case 'しらさぎ':
            return 'bg-light text-dark';
        case 'くじゃく':
            return 'bg-success';
        case 'ちどり':
            return 'bg-secondary';
        case 'グラン':
            return 'bg-dark';
        default:
            return 'bg-secondary';
    }
}

/**
 * 会場バッジの表示
 */
function getVenueBadge($venue) {
    if (empty($venue)) {
        return '<span class="text-muted">未設定</span>';
    }
    
    $badgeClass = getVenueBadgeClass($venue);
    return "<span class=\"badge {$badgeClass}\">" . h($venue) . "</span>";
}

/**
 * 自動シフト作成機能
 */
function performAutoAssignment($pdo, $eventId) {
    // イベント情報取得
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    
    if (!$event) {
        throw new Exception('イベントが見つかりません');
    }
    
    // 必要人数の解析
    $needs = parseNeeds($event['needs']);
    if (empty($needs)) {
        throw new Exception('必要人数が設定されていません');
    }
    
    // 出勤可能なスタッフを取得
    $availableUsers = getAvailableUsers($pdo, $eventId, $event['event_date'], $event['start_time'], $event['end_time']);
    
    if (empty($availableUsers)) {
        throw new Exception('出勤可能なスタッフがいません');
    }
    
    // 役割別に自動割当を実行
    $assignments = [];
    $assignedUserIds = [];
    
    foreach ($needs as $role => $need) {
        $assignments[$role] = [];
        
        // この役割に対応可能なスタッフを取得
        $candidateUsers = getCandidateUsersForRole($pdo, $availableUsers, $role, $assignedUserIds);
        
        // スキルレベルとランクでソート（優先度順）
        $candidateUsers = sortCandidatesByPriority($candidateUsers, $role);
        
        // 必要最小人数まで割当
        $assignedCount = 0;
        foreach ($candidateUsers as $user) {
            if ($assignedCount >= $need['min'] || in_array($user['id'], $assignedUserIds)) {
                continue;
            }
            
            // スキルレベルを取得
            $skillLevel = getUserSkillLevel($pdo, $user['id'], $role);
            if ($skillLevel === 'できない') {
                continue; // 「できない」スタッフは割当しない
            }
            
            $assignments[$role][] = [
                'user' => $user,
                'skill_level' => $skillLevel
            ];
            
            $assignedUserIds[] = $user['id'];
            $assignedCount++;
        }
    }
    
    return [
        'event' => $event,
        'assignments' => $assignments,
        'needs' => $needs,
        'available_users' => $availableUsers,
        'is_saved' => false
    ];
}

/**
 * 出勤可能なスタッフを取得
 */
function getAvailableUsers($pdo, $eventId, $eventDate, $eventStartTime, $eventEndTime) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.name, u.furigana, u.gender, u.is_rank, u.is_highschool,
               a.available_start_time, a.available_end_time
        FROM users u
        JOIN availability a ON u.id = a.user_id
        WHERE a.work_date = ? 
          AND a.available = 1
          AND (a.event_id IS NULL OR a.event_id = 0 OR a.event_id = ?)
          AND TIME(a.available_start_time) <= TIME(?)
          AND TIME(a.available_end_time) >= TIME(?)
        ORDER BY u.is_rank DESC, u.furigana
    ");
    $stmt->execute([$eventDate, $eventId, $eventEndTime, $eventStartTime]);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 特定の役割に対応可能なスタッフを取得
 */
function getCandidateUsersForRole($pdo, $availableUsers, $role, $excludeUserIds = []) {
    $candidates = [];
    
    foreach ($availableUsers as $user) {
        if (in_array($user['id'], $excludeUserIds)) {
            continue;
        }
        
        // スキルレベルをチェック
        $skillLevel = getUserSkillLevel($pdo, $user['id'], $role);
        if ($skillLevel !== 'できない') {
            $user['skill_level'] = $skillLevel;
            $candidates[] = $user;
        }
    }
    
    return $candidates;
}

/**
 * ユーザーの特定役割に対するスキルレベルを取得
 */
function getUserSkillLevel($pdo, $userId, $role) {
    $stmt = $pdo->prepare("
        SELECT s.skill_level
        FROM skills s
        JOIN task_types tt ON s.task_type_id = tt.id
        WHERE s.user_id = ? AND tt.name = ?
    ");
    $stmt->execute([$userId, $role]);
    
    $result = $stmt->fetch();
    return $result ? $result['skill_level'] : 'できない';
}

/**
 * 候補者を優先度順にソート
 */
function sortCandidatesByPriority($candidates, $role) {
    usort($candidates, function($a, $b) {
        // 1. スキルレベル優先（できる > まあまあできる > できない）
        $skillPriorityA = getSkillPriority($a['skill_level']);
        $skillPriorityB = getSkillPriority($b['skill_level']);
        
        if ($skillPriorityA !== $skillPriorityB) {
            return $skillPriorityB - $skillPriorityA; // 高い優先度が先
        }
        
        // 2. ランク優先（ランナー > その他）
        $rankPriorityA = ($a['is_rank'] === 'ランナー') ? 1 : 0;
        $rankPriorityB = ($b['is_rank'] === 'ランナー') ? 1 : 0;
        
        if ($rankPriorityA !== $rankPriorityB) {
            return $rankPriorityB - $rankPriorityA;
        }
        
        // 3. 五十音順
        $nameA = !empty($a['furigana']) ? $a['furigana'] : $a['name'];
        $nameB = !empty($b['furigana']) ? $b['furigana'] : $b['name'];
        
        return strcmp(mb_convert_kana($nameA, 'c', 'UTF-8'), mb_convert_kana($nameB, 'c', 'UTF-8'));
    });
    
    return $candidates;
}

/**
 * スキルレベルの優先度を数値で取得
 */
function getSkillPriority($skillLevel) {
    switch ($skillLevel) {
        case 'できる':
            return 3;
        case 'まあまあできる':
            return 2;
        case 'できない':
            return 1;
        default:
            return 0;
    }
}

?>
