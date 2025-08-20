-- availabilityテーブルのevent_idカラムをNULL許可に変更
ALTER TABLE availability MODIFY COLUMN event_id INT NULL;

-- 変更を確認
DESCRIBE availability;
