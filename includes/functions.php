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
    $stmt = $pdo->prepare("
        SELECT a.available, a.available_start_time, a.available_end_time,
               e.start_time, e.end_time
        FROM availability a
        JOIN events e ON a.event_id = e.id
        WHERE a.user_id = ? AND a.event_id = ? AND a.available = 1
    ");
    $stmt->execute([$userId, $eventId]);
    $availability = $stmt->fetch();
    
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
        return compareJapaneseName($a['name'], $b['name']);
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
?>
