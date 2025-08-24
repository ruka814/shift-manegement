<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

// ユーザー管理画面
$action = $_GET['action'] ?? 'list';
$message = '';

// デバッグ: POSTデータの確認
if (!empty($_POST)) {
    error_log("users.php: 受信したPOSTデータ: " . json_encode($_POST));
    error_log("users.php: action値: " . ($_POST['action'] ?? 'null'));
}

// ユーザー追加処理
if (($_POST['action'] ?? '') === 'add_user') {
    error_log("users.php: ユーザー追加処理を実行");
    try {
        // バリデーション
        $name = trim($_POST['name'] ?? '');
        $furigana = trim($_POST['furigana'] ?? '');
        $gender = $_POST['gender'] ?? '';
        $max_workdays = 10; // デフォルト値を10日に設定
        $is_rank = $_POST['is_rank'] ?? '';
        
        error_log("users.php: 受信データ - name: '{$name}', furigana: '{$furigana}', gender: '{$gender}', is_rank: '{$is_rank}'");
        
        if (empty($name)) {
            throw new Exception('名前は必須項目です。');
        }
        if (empty($gender)) {
            throw new Exception('性別は必須項目です。');
        }
        if (empty($is_rank)) {
            throw new Exception('ランクは必須項目です。');
        }
        
        // furigana列が存在するかチェック
        try {
            $checkStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'furigana'");
            $hasFurigana = $checkStmt->rowCount() > 0;
        } catch (Exception $e) {
            $hasFurigana = false;
        }
        
        if ($hasFurigana) {
            if (empty($furigana)) {
                throw new Exception('ふりがなは必須項目です。');
            }
            $stmt = $pdo->prepare("INSERT INTO users (name, furigana, gender, is_highschool, max_workdays, is_rank) VALUES (?, ?, ?, ?, ?, ?)");
            $result = $stmt->execute([
                $name,
                $furigana,
                $gender,
                isset($_POST['is_highschool']) ? 1 : 0,
                $max_workdays,
                $is_rank
            ]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO users (name, gender, is_highschool, max_workdays, is_rank) VALUES (?, ?, ?, ?, ?)");
            $result = $stmt->execute([
                $name,
                $gender,
                isset($_POST['is_highschool']) ? 1 : 0,
                $max_workdays,
                $is_rank
            ]);
        }
        
        if ($result) {
            $newUserId = $pdo->lastInsertId();
            $message = showAlert('success', "スタッフを追加しました。（ID: {$newUserId}）");
        } else {
            throw new Exception('ユーザーの追加に失敗しました。');
        }
    } catch(Exception $e) {
        $message = showAlert('danger', $e->getMessage());
    } catch(PDOException $e) {
        $message = showAlert('danger', 'エラーが発生しました: ' . $e->getMessage());
    }
}
// ユーザー削除処理
elseif (($_POST['action'] ?? '') === 'delete_user') {
    error_log("users.php: ユーザー削除処理を実行");
    try {
        $user_id = $_POST['user_id'] ?? null;
        
        if (empty($user_id)) {
            throw new Exception('削除するユーザーIDが指定されていません。');
        }
        
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('指定されたユーザーが見つかりませんでした。');
        }
        
        $message = showAlert('success', 'スタッフを削除しました。');
    } catch(Exception $e) {
        $message = showAlert('danger', $e->getMessage());
    } catch(PDOException $e) {
        $message = showAlert('danger', 'エラーが発生しました: ' . $e->getMessage());
    }
}
// スキル更新処理
elseif (($_POST['action'] ?? '') === 'update_skills') {
    error_log("users.php: スキル更新処理を実行");
    try {
        $pdo->beginTransaction();
        
        // バリデーション
        $user_id = $_POST['user_id'] ?? null;
        $skills = $_POST['skills'] ?? [];
        
        // デバッグ情報をログに記録
        error_log("users.php: スキル更新開始 - User ID: {$user_id}");
        error_log("users.php: 受信したスキルデータ: " . json_encode($skills));
        
        if (empty($user_id)) {
            throw new Exception('ユーザーIDが指定されていません。');
        }
        
        // ユーザーIDの存在確認（詳細情報付き）
        $stmt = $pdo->prepare("SELECT id, name FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        if (!$user) {
            // より詳細なデバッグ情報
            $stmt = $pdo->query("SELECT id, name FROM users ORDER BY id");
            $allUsers = $stmt->fetchAll();
            $userIds = array_column($allUsers, 'id');
            error_log("users.php: 存在するユーザーID一覧: " . implode(', ', $userIds));
            error_log("users.php: 要求されたユーザーID {$user_id} が見つかりません");
            
            throw new Exception("指定されたユーザー（ID: {$user_id}）が見つかりません。データベースを確認してください。");
        }
        
        error_log("users.php: ユーザー確認成功 - {$user['name']} (ID: {$user['id']})");
        
        // 既存のスキルを削除
        $stmt = $pdo->prepare("DELETE FROM skills WHERE user_id = ?");
        $deleteResult = $stmt->execute([$user_id]);
        $deletedCount = $stmt->rowCount();
        error_log("users.php: 既存スキル削除 - {$deletedCount}件削除");
        
        // 新しいスキルを追加
        $stmt = $pdo->prepare("INSERT INTO skills (user_id, task_type_id, skill_level) VALUES (?, ?, ?)");
        $insertedCount = 0;
        foreach ($skills as $taskTypeId => $skillLevel) {
            if (!empty($skillLevel)) {
                // task_type_idの存在確認
                $checkStmt = $pdo->prepare("SELECT id FROM task_types WHERE id = ?");
                $checkStmt->execute([$taskTypeId]);
                if ($checkStmt->fetch()) {
                    $insertResult = $stmt->execute([$user_id, $taskTypeId, $skillLevel]);
                    if ($insertResult) {
                        $insertedCount++;
                        error_log("users.php: スキル追加成功 - TaskType: {$taskTypeId}, Level: {$skillLevel}");
                    }
                } else {
                    error_log("users.php: 無効なタスクタイプID: {$taskTypeId}");
                }
            }
        }
        
        $pdo->commit();
        error_log("users.php: スキル更新完了 - {$insertedCount}件のスキルを追加");
        $message = showAlert('success', "{$user['name']}さんのスキル情報を更新しました。（{$insertedCount}件のスキルを設定）");
    } catch(Exception $e) {
        $pdo->rollback();
        $message = showAlert('danger', 'エラーが発生しました: ' . $e->getMessage());
    } catch(PDOException $e) {
        $pdo->rollback();
        $message = showAlert('danger', 'エラーが発生しました: ' . $e->getMessage());
    }
}

// どの条件にもマッチしない場合のデバッグ
if (!empty($_POST) && !in_array($_POST['action'] ?? '', ['add_user', 'delete_user', 'update_skills'])) {
    error_log("users.php: 未知のaction値: " . ($_POST['action'] ?? 'null'));
    error_log("users.php: 全POSTデータ: " . json_encode($_POST));
    $message = showAlert('warning', "未知の操作が送信されました: " . ($_POST['action'] ?? 'null'));
}

// ユーザー一覧取得
$stmt = $pdo->query("SELECT * FROM users");
$users = $stmt->fetchAll();

// PHP側でランク別かつ五十音順にソート
$users = sortUsersByRankAndName($users);

// タスクタイプ取得
$stmt = $pdo->query("SELECT * FROM task_types ORDER BY name COLLATE utf8mb4_unicode_ci");
$taskTypes = $stmt->fetchAll();

// furigana列が存在するかチェック
try {
    $checkStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'furigana'");
    $hasFuriganaColumn = $checkStmt->rowCount() > 0;
} catch (Exception $e) {
    $hasFuriganaColumn = false;
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>スタッフ管理 - シフト管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* スタッフ名のクリック可能スタイル */
        .fw-bold a {
            color: #0d6efd;
            transition: color 0.2s ease;
        }
        
        .fw-bold a:hover {
            color: #0a58ca;
            text-decoration: underline !important;
        }
        
        /* 統計数値のスタイル */
        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: #0d6efd;
        }
        
        .stat-label {
            font-size: 0.875rem;
            color: #6c757d;
        }
        
        /* 出勤履歴テーブルのレスポンシブ対応 */
        @media (max-width: 768px) {
            .table-responsive {
                font-size: 0.875rem;
            }
            
            .stat-number {
                font-size: 1.25rem;
            }
        }
        
        /* ローディングアニメーション */
        .spinner-border {
            color: #0d6efd;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="../index.php">シフト管理システム</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link active" href="users.php">スタッフ管理</a>
                <a class="nav-link" href="events.php">イベント管理</a>
                <a class="nav-link" href="availability.php">出勤入力</a>
                <a class="nav-link" href="shift_assignment.php">シフト作成</a>
                <a class="nav-link" href="saved_shifts.php">保存済みシフト</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <?= $message ?>
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>👥 スタッフ管理</h1>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                新規スタッフ追加
            </button>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>名前</th>
                                <th>性別</th>
                                <th>高校生</th>
                                <th>ランク</th>
                                <th>スキル</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $previousRank = null;
                            foreach ($users as $user): 
                                // ランナーからランナー以外に変わるタイミングで区切り行を追加
                                if ($previousRank === 'ランナー' && $user['is_rank'] !== 'ランナー'):
                            ?>
                            <tr class="table-secondary">
                                <td colspan="6" class="text-center fw-bold">
                                    <i class="fas fa-minus"></i> ランナー以外 <i class="fas fa-minus"></i>
                                </td>
                            </tr>
                            <?php 
                                elseif ($previousRank === null && $user['is_rank'] === 'ランナー'):
                            ?>
                            <tr class="table-primary">
                                <td colspan="6" class="text-center fw-bold">
                                    <i class="fas fa-star"></i> ランナー <i class="fas fa-star"></i>
                                </td>
                            </tr>
                            <?php 
                                elseif ($previousRank === null && $user['is_rank'] !== 'ランナー'):
                            ?>
                            <tr class="table-secondary">
                                <td colspan="6" class="text-center fw-bold">
                                    <i class="fas fa-minus"></i> ランナー以外 <i class="fas fa-minus"></i>
                                </td>
                            </tr>
                            <?php 
                                endif;
                                $previousRank = $user['is_rank'];
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-bold">
                                        <a href="#" class="text-decoration-none" 
                                           onclick="showAttendanceHistory(<?= $user['id'] ?>, '<?= h($user['name']) ?>')"
                                           title="出勤履歴を表示">
                                            <?= h($user['name']) ?>
                                        </a>
                                    </div>
                                    <?php if (isset($user['furigana']) && !empty($user['furigana'])): ?>
                                    <small class="text-muted"><?= h($user['furigana']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= getGenderText($user['gender']) ?></td>
                                <td><?= $user['is_highschool'] ? 'はい' : 'いいえ' ?></td>
                                <td><?= getRankBadge($user['is_rank']) ?></td>
                                <td>
                                    <div class="skills-compact">
                                        <?php if (isRunner($user['is_rank'])): ?>
                                            <?= getRunnerSkillDisplay($user['id'], $pdo) ?>
                                        <?php else: ?>
                                            <?php
                                            $stmt = $pdo->prepare("
                                                SELECT tt.name, s.skill_level 
                                                FROM skills s 
                                                JOIN task_types tt ON s.task_type_id = tt.id 
                                                WHERE s.user_id = ?
                                                ORDER BY tt.name
                                            ");
                                            $stmt->execute([$user['id']]);
                                            $skills = $stmt->fetchAll();
                                            
                                            if (count($skills) > 0):
                                                foreach ($skills as $skill):
                                            ?>
                                                <div class="skill-item">
                                                    <small class="text-muted"><?= h($skill['name']) ?>:</small>
                                                    <?= getSkillBadge($skill['skill_level']) ?>
                                                </div>
                                            <?php 
                                                endforeach;
                                            else:
                                            ?>
                                                <small class="text-muted">スキル未設定</small>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <button class="btn btn-sm btn-outline-primary" 
                                                onclick="editSkills(<?= $user['id'] ?>, '<?= h($user['name']) ?>')">
                                            スキル編集
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" 
                                                onclick="deleteUser(<?= $user['id'] ?>, '<?= h($user['name']) ?>')">
                                            削除
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- 新規スタッフ追加モーダル -->
    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">新規スタッフ追加</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_user">
                        
                        <div class="mb-3">
                            <label class="form-label">名前</label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        
                        <?php if ($hasFuriganaColumn): ?>
                        <div class="mb-3">
                            <label class="form-label">ふりがな</label>
                            <input type="text" class="form-control" name="furigana" placeholder="ひらがなで入力してください" required>
                        </div>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label class="form-label">性別</label>
                            <select class="form-select" name="gender" required>
                                <option value="">選択してください</option>
                                <option value="M">男性</option>
                                <option value="F">女性</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_highschool" id="is_highschool">
                                <label class="form-check-label" for="is_highschool">
                                    高校生
                                </label>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">ランク</label>
                            <select class="form-select" name="is_rank" required>
                                <option value="">選択してください</option>
                                <option value="ランナー">ランナー</option>
                                <option value="ランナー以外">ランナー以外</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-primary">追加</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- スキル編集モーダル -->
    <div class="modal fade" id="skillModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">スキル編集: <span id="skillUserName"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_skills">
                        <input type="hidden" name="user_id" id="skillUserId">
                        
                        <div class="alert alert-info">
                            <small><i class="fas fa-info-circle"></i> 各タスクに対するスキルレベルを設定してください。未設定の場合は「できない」として扱われます。</small>
                        </div>
                        
                        <div id="skillForm" class="row">
                            <?php foreach ($taskTypes as $index => $taskType): ?>
                            <div class="col-md-6 mb-3">
                                <div class="card border-light">
                                    <div class="card-body p-3">
                                        <label class="form-label fw-bold"><?= h($taskType['name']) ?></label>
                                        <?php if (in_array($taskType['name'], ['コースランナー', 'ブッフェランナー'])): ?>
                                        <!-- ランナースキルは二択 -->
                                        <select class="form-select" name="skills[<?= $taskType['id'] ?>]">
                                            <option value="">できない</option>
                                            <option value="できる" class="text-success">✓ できる</option>
                                        </select>
                                        <?php else: ?>
                                        <!-- 一般スキルは従来通り -->
                                        <select class="form-select" name="skills[<?= $taskType['id'] ?>]">
                                            <option value="">未設定</option>
                                            <option value="できる" class="text-success">✓ できる</option>
                                            <option value="まあまあできる" class="text-warning">○ まあまあできる</option>
                                            <option value="できない" class="text-danger">× できない</option>
                                        </select>
                                        <?php endif; ?>
                                        <?php if (!empty($taskType['description'])): ?>
                                        <small class="text-muted"><?= h($taskType['description']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-primary">更新</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 削除確認モーダル -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">スタッフ削除確認</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="user_id" id="deleteUserId">
                        <p><span id="deleteUserName"></span>を削除しますか？</p>
                        <div class="alert alert-warning">
                            <strong>注意:</strong> この操作は取り消せません。関連するスキル情報も削除されます。
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-danger">削除</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 出勤履歴表示モーダル -->
    <div class="modal fade" id="attendanceModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">📅 出勤履歴 - <span id="attendanceUserName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="attendanceContent">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">読み込み中...</span>
                        </div>
                        <p class="mt-2">出勤履歴を読み込んでいます...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
                    <button type="button" class="btn btn-primary" id="addAttendanceBtn" onclick="goToAttendanceInput()">
                        ➕ 新規出勤入力
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editSkills(userId, userName) {
            console.log(`editSkills called with userId: ${userId}, userName: ${userName}`);
            
            // ユーザーIDの妥当性チェック
            if (!userId || userId === 'undefined' || userId === 'null') {
                alert('無効なユーザーIDです。ページを再読み込みしてください。');
                return;
            }
            
            document.getElementById('skillUserId').value = userId;
            document.getElementById('skillUserName').textContent = userName;
            
            console.log(`Setting user ID in hidden field: ${userId}`);
            
            // 現在のスキル情報を取得して設定
            fetch(`../api/get_user_skills.php?user_id=${userId}`)
                .then(response => {
                    console.log(`API response status: ${response.status}`);
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(skills => {
                    console.log('Received skills data:', skills);
                    
                    // フォームをリセット
                    document.querySelectorAll('#skillForm select').forEach(select => {
                        select.value = '';
                    });
                    
                    // 取得したスキル情報を設定
                    skills.forEach(skill => {
                        const select = document.querySelector(`select[name="skills[${skill.task_type_id}]"]`);
                        if (select) {
                            select.value = skill.skill_level;
                            console.log(`Set skill for task ${skill.task_type_id}: ${skill.skill_level}`);
                        }
                    });
                })
                .catch(error => {
                    console.error('Error fetching user skills:', error);
                    alert(`スキル情報の取得に失敗しました: ${error.message}\nページを再読み込みしてください。`);
                });
            
            new bootstrap.Modal(document.getElementById('skillModal')).show();
        }
        
        function deleteUser(userId, userName) {
            document.getElementById('deleteUserId').value = userId;
            document.getElementById('deleteUserName').textContent = userName;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }
        
        // 出勤履歴表示機能
        function showAttendanceHistory(userId, userName) {
            console.log(`showAttendanceHistory called with userId: ${userId}, userName: ${userName}`);
            
            // ユーザーIDの妥当性チェック
            if (!userId || userId === 'undefined' || userId === 'null') {
                alert('無効なユーザーIDです。ページを再読み込みしてください。');
                return;
            }
            
            // モーダルのタイトルを設定
            document.getElementById('attendanceUserName').textContent = userName;
            
            // ローディング状態を表示
            document.getElementById('attendanceContent').innerHTML = `
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">読み込み中...</span>
                    </div>
                    <p class="mt-2">出勤履歴を読み込んでいます...</p>
                </div>
            `;
            
            // モーダルを表示
            const modal = new bootstrap.Modal(document.getElementById('attendanceModal'));
            modal.show();
            
            // 出勤履歴データを取得
            fetch(`get_attendance_history.php?user_id=${userId}`)
                .then(response => {
                    console.log(`API response status: ${response.status}`);
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Received attendance data:', data);
                    displayAttendanceHistory(data);
                })
                .catch(error => {
                    console.error('Error fetching attendance history:', error);
                    document.getElementById('attendanceContent').innerHTML = `
                        <div class="alert alert-danger">
                            <h6>エラーが発生しました</h6>
                            <p>出勤履歴の取得に失敗しました: ${error.message}</p>
                            <small>ページを再読み込みして再度お試しください。</small>
                        </div>
                    `;
                });
        }
        
        // 出勤履歴の表示
        function displayAttendanceHistory(data) {
            const content = document.getElementById('attendanceContent');
            
            if (!data.attendance || data.attendance.length === 0) {
                content.innerHTML = `
                    <div class="text-center py-4">
                        <div class="text-muted">
                            <i class="fas fa-calendar-times fa-3x mb-3"></i>
                            <h5>出勤履歴がありません</h5>
                            <p>まだ出勤予定が入力されていません。</p>
                        </div>
                    </div>
                `;
                return;
            }
            
            let html = `
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6>📊 出勤履歴（${data.attendance.length}件）</h6>
                        <small class="text-muted">※出勤可能として入力された日程</small>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>日付</th>
                                <th>曜日</th>
                                <th>出勤時間</th>
                                <th>イベント</th>
                                <th>更新日時</th>
                                <th>備考</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            data.attendance.forEach(record => {
                const eventInfo = record.event_type ? 
                    `<span class="badge bg-primary">${record.event_type}</span>` : 
                    '<span class="text-muted">一般出勤</span>';
                
                const timeInfo = record.available_start_time && record.available_end_time ?
                    `${record.available_start_time.substr(0, 5)} - ${record.available_end_time.substr(0, 5)}` :
                    '<span class="text-muted">時間未設定</span>';
                
                const weekday = new Date(record.work_date).toLocaleDateString('ja-JP', { weekday: 'short' });
                
                // 更新日時の表示
                const updatedAt = record.updated_at ? 
                    new Date(record.updated_at).toLocaleString('ja-JP', {
                        month: 'numeric',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    }) : 
                    '<span class="text-muted">不明</span>';
                
                html += `
                    <tr>
                        <td>
                            <strong>${record.work_date}</strong>
                        </td>
                        <td>
                            <span class="badge ${weekday === '土' || weekday === '日' ? 'bg-warning text-dark' : 'bg-light text-dark'}">${weekday}</span>
                        </td>
                        <td>${timeInfo}</td>
                        <td>${eventInfo}</td>
                        <td>
                            <small class="text-muted">${updatedAt}</small>
                        </td>
                        <td>
                            ${record.note ? `<small class="text-muted">${record.note}</small>` : '-'}
                        </td>
                    </tr>
                `;
            });
            
            html += `
                        </tbody>
                    </table>
                </div>
            `;
            
            // 統計情報を追加
            const stats = data.stats || {};
            html += `
                <div class="mt-4">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="card-title">📈 出勤統計</h6>
                            <div class="row text-center">
                                <div class="col-md-3">
                                    <div class="stat-number">${stats.total_days || 0}</div>
                                    <div class="stat-label">総出勤予定日数</div>
                                </div>
                                <div class="col-md-3">
                                    <div class="stat-number">${stats.weekend_days || 0}</div>
                                    <div class="stat-label">土日出勤</div>
                                </div>
                                <div class="col-md-3">
                                    <div class="stat-number">${stats.event_days || 0}</div>
                                    <div class="stat-label">イベント出勤</div>
                                </div>
                                <div class="col-md-3">
                                    <div class="stat-number">${stats.this_month || 0}</div>
                                    <div class="stat-label">今月の予定</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            content.innerHTML = html;
        }
        
        // 出勤入力ページへ移動
        function goToAttendanceInput() {
            window.open('availability.php', '_blank');
        }
    </script>
</body>
</html>
