-- シフト管理システム データベース削除スクリプト
-- 全てのテーブルとデータベースを削除します

-- 外部キー制約チェックを無効化
SET FOREIGN_KEY_CHECKS = 0;

-- テーブルを削除（依存関係の逆順）
DROP TABLE IF EXISTS assignments;
DROP TABLE IF EXISTS availability;
DROP TABLE IF EXISTS skills;
DROP TABLE IF EXISTS events;
DROP TABLE IF EXISTS task_types;
DROP TABLE IF EXISTS users;

-- 外部キー制約チェックを再有効化
SET FOREIGN_KEY_CHECKS = 1;

-- データベースを削除（オプション - コメントアウト）
-- DROP DATABASE IF EXISTS shift_management;
