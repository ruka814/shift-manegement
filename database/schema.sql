-- シフト管理システム データベース作成スクリプト

-- 既存のデータベースを削除（開発環境用）
DROP DATABASE IF EXISTS shift_management;

CREATE DATABASE IF NOT EXISTS shift_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE shift_management;

-- ユーザー（スタッフ）テーブル
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name TEXT NOT NULL,
    furigana TEXT NOT NULL,
    gender TEXT NOT NULL CHECK (gender IN ('M', 'F')),
    is_highschool BOOLEAN DEFAULT FALSE,
    max_workdays INT DEFAULT 10,
    is_rank TEXT NOT NULL CHECK (is_rank IN ('ランナー', 'ランナー以外')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- タスクの種類テーブル
CREATE TABLE task_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name TEXT NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- スキルテーブル
CREATE TABLE skills (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    task_type_id INT NOT NULL,
    skill_level TEXT NOT NULL CHECK (skill_level IN ('できる', 'まあまあできる', 'できない')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (task_type_id) REFERENCES task_types(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_task (user_id, task_type_id)
);

-- イベントテーブル
CREATE TABLE events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    event_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    event_type TEXT NOT NULL,
    venue VARCHAR(255) NULL COMMENT '会場名',
    needs JSON,
    description TEXT,
    total_staff_required INT DEFAULT NULL COMMENT '総必要人数',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 出勤可能情報テーブル
CREATE TABLE availability (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    work_date DATE NOT NULL,
    event_id INT NULL,
    available BOOLEAN DEFAULT FALSE,
    available_start_time TIME,
    available_end_time TIME,
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_event_date (user_id, work_date, event_id)
);

-- 割当結果テーブル（将来的に使用）
CREATE TABLE assignments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    event_id INT NOT NULL,
    assigned_role TEXT NOT NULL,
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
);

-- スケジュールテーブル（スタッフの基本勤務時間）
CREATE TABLE schedules (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    day_of_week ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 初期データの挿入

-- タスクの種類
INSERT INTO task_types (name, description) VALUES
('両親', '会場内での料理運搬、セッティング'),
('ライト', '軽作業、補助業務'),
('コースランナー', 'コース料理の配膳・サービス（ランナー専用スキル）'),
('ブッフェランナー', 'ブッフェ会場での配膳・補充（ランナー専用スキル）');

-- サンプルユーザー（20名）
INSERT INTO users (name, furigana, gender, is_highschool, max_workdays, is_rank) VALUES
-- ランナー（10名）
('田中太郎', 'たなかたろう', 'M', FALSE, 15, 'ランナー'),
('佐藤花子', 'さとうはなこ', 'F', FALSE, 12, 'ランナー'),
('山田一郎', 'やまだいちろう', 'M', TRUE, 8, 'ランナー'),
('鈴木美香', 'すずきみか', 'F', FALSE, 10, 'ランナー'),
('高橋健太', 'たかはしけんた', 'M', TRUE, 6, 'ランナー'),
('伊藤桜', 'いとうさくら', 'F', FALSE, 14, 'ランナー'),
('渡辺大輔', 'わたなべだいすけ', 'M', FALSE, 13, 'ランナー'),
('中村愛', 'なかむらあい', 'F', TRUE, 7, 'ランナー'),
('小林拓也', 'こばやしたくや', 'M', FALSE, 11, 'ランナー'),
('加藤真理', 'かとうまり', 'F', FALSE, 9, 'ランナー'),

-- ランナー以外（10名）
('吉田和也', 'よしだかずや', 'M', FALSE, 8, 'ランナー以外'),
('松本彩', 'まつもとあや', 'F', TRUE, 5, 'ランナー以外'),
('井上雄介', 'いのうえゆうすけ', 'M', FALSE, 12, 'ランナー以外'),
('木村奈々', 'きむらなな', 'F', FALSE, 10, 'ランナー以外'),
('林信夫', 'はやしのぶお', 'M', FALSE, 15, 'ランナー以外'),
('清水麻衣', 'しみずまい', 'F', TRUE, 6, 'ランナー以外'),
('山口裕太', 'やまぐちゆうた', 'M', FALSE, 9, 'ランナー以外'),
('森下美咲', 'もりしたみさき', 'F', FALSE, 11, 'ランナー以外'),
('池田圭介', 'いけだけいすけ', 'M', TRUE, 7, 'ランナー以外'),
('橋本ゆり', 'はしもとゆり', 'F', FALSE, 13, 'ランナー以外');

-- サンプルスキルデータ
INSERT INTO skills (user_id, task_type_id, skill_level) VALUES
-- ランナーのスキル
(1, 1, 'できる'), (1, 2, 'まあまあできる'), (1, 3, 'できる'), (1, 4, 'まあまあできる'),
(2, 1, 'まあまあできる'), (2, 2, 'できる'), (2, 3, 'できない'), (2, 4, 'できる'),
(3, 1, 'できる'), (3, 2, 'できる'), (3, 3, 'できる'), (3, 4, 'できない'),
(4, 1, 'まあまあできる'), (4, 2, 'できる'), (4, 3, 'できない'), (4, 4, 'できる'),
(5, 1, 'できる'), (5, 2, 'まあまあできる'), (5, 3, 'まあまあできる'), (5, 4, 'できない'),
(6, 1, 'できる'), (6, 2, 'できる'), (6, 3, 'できる'), (6, 4, 'できる'),
(7, 1, 'まあまあできる'), (7, 2, 'できる'), (7, 3, 'できる'), (7, 4, 'まあまあできる'),
(8, 1, 'できる'), (8, 2, 'まあまあできる'), (8, 3, 'できない'), (8, 4, 'できる'),
(9, 1, 'できる'), (9, 2, 'できる'), (9, 3, 'まあまあできる'), (9, 4, 'できない'),
(10, 1, 'まあまあできる'), (10, 2, 'できる'), (10, 3, 'できない'), (10, 4, 'まあまあできる'),

-- ランナー以外のスキル（コース・ブッフェランナーは「できない」）
(11, 1, 'できる'), (11, 2, 'まあまあできる'), (11, 3, 'できない'), (11, 4, 'できない'),
(12, 1, 'まあまあできる'), (12, 2, 'できる'), (12, 3, 'できない'), (12, 4, 'できない'),
(13, 1, 'できる'), (13, 2, 'できる'), (13, 3, 'できない'), (13, 4, 'できない'),
(14, 1, 'まあまあできる'), (14, 2, 'まあまあできる'), (14, 3, 'できない'), (14, 4, 'できない'),
(15, 1, 'できる'), (15, 2, 'できる'), (15, 3, 'できない'), (15, 4, 'できない'),
(16, 1, 'まあまあできる'), (16, 2, 'できる'), (16, 3, 'できない'), (16, 4, 'できない'),
(17, 1, 'できる'), (17, 2, 'まあまあできる'), (17, 3, 'できない'), (17, 4, 'できない'),
(18, 1, 'まあまあできる'), (18, 2, 'できる'), (18, 3, 'できない'), (18, 4, 'できない'),
(19, 1, 'できる'), (19, 2, 'できる'), (19, 3, 'できない'), (19, 4, 'できない'),
(20, 1, 'まあまあできる'), (20, 2, 'まあまあできる'), (20, 3, 'できない'), (20, 4, 'できない');

-- サンプルイベント
INSERT INTO events (event_date, start_time, end_time, event_type, venue, needs, description, total_staff_required) VALUES
('2025-08-15', '18:00:00', '22:00:00', 'ビュッフェ', 'ローズII', '{"両親": "2-3", "ライト": 2}', '企業懇親会', 5),
('2025-08-20', '11:00:00', '15:00:00', '婚礼', 'クリスタル', '{"両親": 4, "ライト": "1-2"}', '結婚披露宴', 6),
('2025-08-25', '19:00:00', '21:30:00', 'コース', 'しらさぎ', '{"両親": 3, "ライト": 1}', '記念パーティー', 4),
('2025-08-30', '17:15:00', '20:45:00', 'ビュッフェ', 'くじゃく', '{"両親": "1-2", "ライト": 2}', '歓送迎会', 4);

-- サンプルスケジュール（出勤時間）データ
INSERT INTO schedules (user_id, day_of_week, start_time, end_time) VALUES
-- 田中太郎（1）- 平日夜と土日終日
(1, 'Monday', '17:00:00', '22:00:00'),
(1, 'Tuesday', '17:00:00', '22:00:00'),
(1, 'Wednesday', '17:00:00', '22:00:00'),
(1, 'Thursday', '17:00:00', '22:00:00'),
(1, 'Friday', '17:00:00', '22:00:00'),
(1, 'Saturday', '09:00:00', '22:00:00'),
(1, 'Sunday', '09:00:00', '22:00:00'),

-- 佐藤花子（2）- 平日昼と週末
(2, 'Monday', '10:00:00', '16:00:00'),
(2, 'Tuesday', '10:00:00', '16:00:00'),
(2, 'Wednesday', '10:00:00', '16:00:00'),
(2, 'Thursday', '10:00:00', '16:00:00'),
(2, 'Friday', '10:00:00', '16:00:00'),
(2, 'Saturday', '09:00:00', '18:00:00'),
(2, 'Sunday', '09:00:00', '18:00:00'),

-- 山田一郎（3）- 週末中心（高校生）
(3, 'Saturday', '10:00:00', '22:00:00'),
(3, 'Sunday', '10:00:00', '22:00:00'),

-- 鈴木美香（4）- 平日夜と土曜日
(4, 'Monday', '18:00:00', '22:00:00'),
(4, 'Tuesday', '18:00:00', '22:00:00'),
(4, 'Wednesday', '18:00:00', '22:00:00'),
(4, 'Thursday', '18:00:00', '22:00:00'),
(4, 'Friday', '18:00:00', '22:00:00'),
(4, 'Saturday', '12:00:00', '22:00:00'),

-- 高橋健太（5）- 平日昼間中心（高校生）
(5, 'Monday', '16:00:00', '21:00:00'),
(5, 'Tuesday', '16:00:00', '21:00:00'),
(5, 'Wednesday', '16:00:00', '21:00:00'),
(5, 'Thursday', '16:00:00', '21:00:00'),
(5, 'Friday', '16:00:00', '21:00:00'),

-- 伊藤桜（6）- フルタイム
(6, 'Monday', '09:00:00', '18:00:00'),
(6, 'Tuesday', '09:00:00', '18:00:00'),
(6, 'Wednesday', '09:00:00', '18:00:00'),
(6, 'Thursday', '09:00:00', '18:00:00'),
(6, 'Friday', '09:00:00', '18:00:00'),
(6, 'Saturday', '09:00:00', '18:00:00'),
(6, 'Sunday', '09:00:00', '18:00:00'),

-- 渡辺大輔（7）- 夜間と週末
(7, 'Monday', '17:00:00', '22:00:00'),
(7, 'Tuesday', '17:00:00', '22:00:00'),
(7, 'Wednesday', '17:00:00', '22:00:00'),
(7, 'Thursday', '17:00:00', '22:00:00'),
(7, 'Friday', '17:00:00', '22:00:00'),
(7, 'Saturday', '10:00:00', '22:00:00'),
(7, 'Sunday', '10:00:00', '22:00:00'),

-- 中村愛（8）- 週3日勤務（高校生）
(8, 'Wednesday', '18:00:00', '21:00:00'),
(8, 'Friday', '18:00:00', '21:00:00'),
(8, 'Saturday', '10:00:00', '18:00:00'),

-- 小林拓也（9）- 平日夜間のみ
(9, 'Monday', '18:00:00', '22:00:00'),
(9, 'Tuesday', '18:00:00', '22:00:00'),
(9, 'Wednesday', '18:00:00', '22:00:00'),
(9, 'Thursday', '18:00:00', '22:00:00'),
(9, 'Friday', '18:00:00', '22:00:00'),

-- 加藤真理（10）- 週末中心
(10, 'Saturday', '10:00:00', '18:00:00'),
(10, 'Sunday', '10:00:00', '18:00:00'),

-- 吉田和也（11）- 平日昼間
(11, 'Monday', '09:00:00', '17:00:00'),
(11, 'Tuesday', '09:00:00', '17:00:00'),
(11, 'Wednesday', '09:00:00', '17:00:00'),
(11, 'Thursday', '09:00:00', '17:00:00'),
(11, 'Friday', '09:00:00', '17:00:00'),

-- 松本彩（12）- パートタイム（高校生）
(12, 'Monday', '16:00:00', '20:00:00'),
(12, 'Wednesday', '16:00:00', '20:00:00'),
(12, 'Friday', '16:00:00', '20:00:00'),
(12, 'Saturday', '10:00:00', '16:00:00'),

-- 井上雄介（13）- 夜間中心
(13, 'Monday', '17:00:00', '22:00:00'),
(13, 'Tuesday', '17:00:00', '22:00:00'),
(13, 'Wednesday', '17:00:00', '22:00:00'),
(13, 'Thursday', '17:00:00', '22:00:00'),
(13, 'Friday', '17:00:00', '22:00:00'),
(13, 'Saturday', '17:00:00', '22:00:00'),

-- 木村奈々（14）- 週末メイン
(14, 'Saturday', '09:00:00', '18:00:00'),
(14, 'Sunday', '09:00:00', '18:00:00'),

-- 林信夫（15）- フルタイム勤務
(15, 'Monday', '08:00:00', '17:00:00'),
(15, 'Tuesday', '08:00:00', '17:00:00'),
(15, 'Wednesday', '08:00:00', '17:00:00'),
(15, 'Thursday', '08:00:00', '17:00:00'),
(15, 'Friday', '08:00:00', '17:00:00'),

-- 清水麻衣（16）- 午前中心（高校生）
(16, 'Saturday', '09:00:00', '15:00:00'),
(16, 'Sunday', '09:00:00', '15:00:00'),

-- 山口裕太（17）- 平日夜間と土曜日
(17, 'Monday', '18:00:00', '22:00:00'),
(17, 'Tuesday', '18:00:00', '22:00:00'),
(17, 'Wednesday', '18:00:00', '22:00:00'),
(17, 'Thursday', '18:00:00', '22:00:00'),
(17, 'Friday', '18:00:00', '22:00:00'),
(17, 'Saturday', '12:00:00', '22:00:00'),

-- 森下美咲（18）- 週3日勤務
(18, 'Tuesday', '10:00:00', '18:00:00'),
(18, 'Thursday', '10:00:00', '18:00:00'),
(18, 'Saturday', '10:00:00', '18:00:00'),

-- 池田圭介（19）- 週末とピーク時間（高校生）
(19, 'Friday', '18:00:00', '21:00:00'),
(19, 'Saturday', '10:00:00', '22:00:00'),
(19, 'Sunday', '10:00:00', '22:00:00'),

-- 橋本ゆり（20）- 学生向けシフト
(20, 'Saturday', '12:00:00', '20:00:00'),
(20, 'Sunday', '12:00:00', '20:00:00');

-- サンプル出勤可能情報データ（イベント非依存・一般的な出勤可能性）
INSERT INTO availability (user_id, work_date, event_id, available, available_start_time, available_end_time, note) VALUES
-- 2025年8月15日の出勤可能情報（ビュッフェイベント 18:00-22:00）
(1, '2025-08-15', NULL, TRUE, '17:00:00', '22:00:00', '夜間のみ可能'),
(2, '2025-08-15', NULL, TRUE, '10:00:00', '22:00:00', '一日中可能'),
(3, '2025-08-15', NULL, TRUE, '16:00:00', '22:00:00', '夕方から可能'),
(4, '2025-08-15', NULL, TRUE, '15:00:00', '22:00:00', '午後から可能'),
(5, '2025-08-15', NULL, TRUE, '16:00:00', '21:00:00', '夕方限定'),
(6, '2025-08-15', NULL, TRUE, '09:00:00', '22:00:00', 'フルタイム'),
(7, '2025-08-15', NULL, TRUE, '17:00:00', '22:00:00', '夜間シフト'),
(11, '2025-08-15', NULL, TRUE, '17:00:00', '22:00:00', '夜間可能'),
(12, '2025-08-15', NULL, TRUE, '16:00:00', '20:00:00', '夕方のみ'),
(13, '2025-08-15', NULL, TRUE, '17:00:00', '22:00:00', '夜間のみ'),

-- 2025年8月20日の出勤可能情報（婚礼 11:00-15:00）
(1, '2025-08-20', NULL, TRUE, '09:00:00', '18:00:00', '土曜日対応'),
(2, '2025-08-20', NULL, TRUE, '10:00:00', '16:00:00', '昼間可能'),
(6, '2025-08-20', NULL, TRUE, '09:00:00', '18:00:00', 'フルタイム'),
(14, '2025-08-20', NULL, TRUE, '09:00:00', '18:00:00', '週末専門'),
(15, '2025-08-20', NULL, TRUE, '08:00:00', '17:00:00', 'フルタイム'),
(16, '2025-08-20', NULL, TRUE, '09:00:00', '15:00:00', '午前中心'),
(19, '2025-08-20', NULL, TRUE, '10:00:00', '22:00:00', '土曜日対応'),
(20, '2025-08-20', NULL, TRUE, '12:00:00', '20:00:00', '週末シフト'),

-- 2025年8月25日の出勤可能情報（コース 19:00-21:30）
(1, '2025-08-25', NULL, TRUE, '17:00:00', '22:00:00', '夜間のみ'),
(2, '2025-08-25', NULL, TRUE, '18:00:00', '22:00:00', '夜間対応'),
(7, '2025-08-25', NULL, TRUE, '17:00:00', '22:00:00', '夜間シフト'),
(9, '2025-08-25', NULL, TRUE, '18:00:00', '22:00:00', '平日夜間'),
(13, '2025-08-25', NULL, TRUE, '17:00:00', '22:00:00', '夜間のみ'),
(17, '2025-08-25', NULL, TRUE, '18:00:00', '22:00:00', '平日夜間'),

-- 2025年8月30日の出勤可能情報（ビュッフェ 17:15-20:45）
(1, '2025-08-30', NULL, TRUE, '17:00:00', '22:00:00', '夜間のみ'),
(2, '2025-08-30', NULL, TRUE, '16:00:00', '22:00:00', '夕方から'),
(4, '2025-08-30', NULL, TRUE, '15:00:00', '22:00:00', '午後から'),
(7, '2025-08-30', NULL, TRUE, '17:00:00', '22:00:00', '夜間シフト'),
(11, '2025-08-30', NULL, TRUE, '17:00:00', '22:00:00', '夜間可能'),
(13, '2025-08-30', NULL, TRUE, '17:00:00', '22:00:00', '夜間のみ'),
(17, '2025-08-30', NULL, TRUE, '18:00:00', '22:00:00', '平日夜間');
(3, '2025-08-15', NULL, FALSE, NULL, NULL, '学校行事のため不可'),
(4, '2025-08-15', NULL, TRUE, '18:00:00', '22:00:00', '夕方から可能'),
(5, '2025-08-15', NULL, TRUE, '16:00:00', '21:00:00', '高校生・短時間勤務'),
(6, '2025-08-15', NULL, TRUE, '09:00:00', '22:00:00', '終日対応可能'),
(7, '2025-08-15', NULL, TRUE, '17:00:00', '22:00:00', 'バイト後から可能'),
(8, '2025-08-15', NULL, FALSE, NULL, NULL, '家族の用事'),

-- 2025年8月20日の出勤可能情報
(1, '2025-08-20', NULL, FALSE, NULL, NULL, '他のバイト'),
(2, '2025-08-20', NULL, TRUE, '09:00:00', '15:00:00', '午前中心に対応'),
(3, '2025-08-20', NULL, TRUE, '10:00:00', '15:00:00', '昼間なら可能'),
(4, '2025-08-20', NULL, TRUE, '12:00:00', '22:00:00', '午後から対応'),
(5, '2025-08-20', NULL, FALSE, NULL, NULL, '学校のテスト'),
(6, '2025-08-20', NULL, TRUE, '09:00:00', '18:00:00', '終日対応可能'),
(7, '2025-08-20', NULL, TRUE, '10:00:00', '22:00:00', '一日中可能'),
(8, '2025-08-20', NULL, TRUE, '10:00:00', '18:00:00', '土曜日は対応可'),

-- 2025年8月25日の出勤可能情報
(1, '2025-08-25', NULL, TRUE, '17:00:00', '22:00:00', '夜間のみ'),
(2, '2025-08-25', NULL, TRUE, '10:00:00', '22:00:00', '一日中可能'),
(3, '2025-08-25', NULL, FALSE, NULL, NULL, '家族旅行'),
(4, '2025-08-25', NULL, TRUE, '18:00:00', '22:00:00', '夕方から'),
(5, '2025-08-25', NULL, TRUE, '16:00:00', '21:00:00', '短時間勤務'),
(6, '2025-08-25', NULL, TRUE, '09:00:00', '22:00:00', '終日対応可能'),
(7, '2025-08-25', NULL, TRUE, '17:00:00', '22:00:00', '夜間勤務'),
(8, '2025-08-25', NULL, FALSE, NULL, NULL, '体調不良'),

-- 2025年8月30日の出勤可能情報
(1, '2025-08-30', NULL, TRUE, '17:00:00', '22:00:00', '平日夜間'),
(2, '2025-08-30', NULL, TRUE, '10:00:00', '21:00:00', '長時間対応可'),
(3, '2025-08-30', NULL, FALSE, NULL, NULL, '学校行事'),
(4, '2025-08-30', NULL, TRUE, '18:00:00', '22:00:00', '夕方以降'),
(5, '2025-08-30', NULL, TRUE, '16:00:00', '21:00:00', '高校生勤務時間'),
(6, '2025-08-30', NULL, TRUE, '09:00:00', '21:00:00', '終日対応'),
(7, '2025-08-30', NULL, TRUE, '17:00:00', '22:00:00', '夜間勤務'),
(8, '2025-08-30', NULL, TRUE, '18:00:00', '21:00:00', '短時間なら可能');
