-- シフト管理システム データベース作成スクリプト

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

-- 初期データの挿入

-- タスクの種類
INSERT INTO task_types (name, description) VALUES
('両親', '会場内での料理運搬、セッティング'),
('ライト', '軽作業、補助業務'),
('コースランナー', 'コース料理の配膳・サービス（ランナー専用スキル）'),
('ブッフェランナー', 'ブッフェ会場での配膳・補充（ランナー専用スキル）');

-- サンプルユーザー
-- INSERT INTO users (name, gender, is_highschool, max_workdays, is_rank) VALUES
-- ('田中太郎', 'M', FALSE, 15, 'ランナー'),
-- ('佐藤花子', 'F', FALSE, 12, 'ランナー以外'),
-- ('山田一郎', 'M', TRUE, 8, 'ランナー'),
-- ('鈴木美香', 'F', FALSE, 10, 'ランナー以外'),
-- ('高橋健太', 'M', TRUE, 6, 'ランナー');

-- サンプルスキルデータ
-- INSERT INTO skills (user_id, task_type_id, skill_level) VALUES
-- (1, 1, 'できる'),
-- (1, 2, 'まあまあできる'),
-- (2, 1, 'まあまあできる'),
-- (2, 2, 'できる'),
-- (3, 2, 'できる'),
-- (4, 1, 'できる'),
-- (5, 2, 'まあまあできる');

-- サンプルイベント
INSERT INTO events (event_date, start_time, end_time, event_type, venue, needs, description, total_staff_required) VALUES
('2025-08-15', '18:00:00', '22:00:00', 'ビュッフェ', 'ローズII', '{"両親": "2-3", "ライト": 2}', '企業懇親会', 5),
('2025-08-20', '11:00:00', '15:00:00', '婚礼', 'クリスタル', '{"両親": 4, "ライト": "1-2"}', '結婚披露宴', 6),
('2025-08-25', '19:00:00', '21:30:00', 'コース', 'しらさぎ', '{"両親": 3, "ライト": 1}', '記念パーティー', 4),
('2025-08-30', '17:15:00', '20:45:00', 'ビュッフェ', 'くじゃく', '{"両親": "1-2", "ライト": 2}', '歓送迎会', 4);
