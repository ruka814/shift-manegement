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
        $gender = $_POST['gender'] ?? '';
        $max_workdays = $_POST['max_workdays'] ?? 0;
        $is_rank = $_POST['is_rank'] ?? '';
        
        error_log("users.php: 受信データ - name: '{$name}', gender: '{$gender}', is_rank: '{$is_rank}'");
        
        if (empty($name)) {
            throw new Exception('名前は必須項目です。');
        }
        if (empty($gender)) {
            throw new Exception('性別は必須項目です。');
        }
        if (empty($is_rank)) {
            throw new Exception('ランクは必須項目です。');
        }
        
        $stmt = $pdo->prepare("INSERT INTO users (name, gender, is_highschool, max_workdays, is_rank) VALUES (?, ?, ?, ?, ?)");
        $result = $stmt->execute([
            $name,
            $gender,
            isset($_POST['is_highschool']) ? 1 : 0,
            $max_workdays,
            $is_rank
        ]);
        
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

// PHP側で五十音順にソート
$users = sortUsersByRankAndName($users);

// タスクタイプ取得
$stmt = $pdo->query("SELECT * FROM task_types ORDER BY name COLLATE utf8mb4_unicode_ci");
$taskTypes = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>スタッフ管理 - シフト管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
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
                                <th>出勤上限</th>
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
                                <td colspan="7" class="text-center fw-bold">
                                    <i class="fas fa-minus"></i> ランナー以外 <i class="fas fa-minus"></i>
                                </td>
                            </tr>
                            <?php 
                                elseif ($previousRank === null && $user['is_rank'] === 'ランナー'):
                            ?>
                            <tr class="table-primary">
                                <td colspan="7" class="text-center fw-bold">
                                    <i class="fas fa-star"></i> ランナー <i class="fas fa-star"></i>
                                </td>
                            </tr>
                            <?php 
                                elseif ($previousRank === null && $user['is_rank'] !== 'ランナー'):
                            ?>
                            <tr class="table-secondary">
                                <td colspan="7" class="text-center fw-bold">
                                    <i class="fas fa-minus"></i> ランナー以外 <i class="fas fa-minus"></i>
                                </td>
                            </tr>
                            <?php 
                                endif;
                                $previousRank = $user['is_rank'];
                            ?>
                            <tr>
                                <td><?= h($user['name']) ?></td>
                                <td><?= getGenderText($user['gender']) ?></td>
                                <td><?= $user['is_highschool'] ? 'はい' : 'いいえ' ?></td>
                                <td><?= h($user['max_workdays']) ?>日</td>
                                <td><?= getRankBadge($user['is_rank']) ?></td>
                                <td>
                                    <div class="skills-compact">
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
                            <label class="form-label">出勤上限日数</label>
                            <input type="number" class="form-control" name="max_workdays" value="10" min="1" max="31">
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
                                        <select class="form-select" name="skills[<?= $taskType['id'] ?>]">
                                            <option value="">未設定</option>
                                            <option value="できる" class="text-success">✓ できる</option>
                                            <option value="まあまあできる" class="text-warning">○ まあまあできる</option>
                                            <option value="できない" class="text-danger">× できない</option>
                                        </select>
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
    </script>
</body>
</html>
