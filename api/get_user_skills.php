<?php
require_once '../config/database.php';
header('Content-Type: application/json');

if (!isset($_GET['user_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'User ID is required']);
    exit;
}

try {
    // ユーザーIDの存在確認
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$_GET['user_id']]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit;
    }
    
    $stmt = $pdo->prepare("
        SELECT task_type_id, skill_level 
        FROM skills 
        WHERE user_id = ?
    ");
    $stmt->execute([$_GET['user_id']]);
    $skills = $stmt->fetchAll();
    
    echo json_encode($skills);
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
