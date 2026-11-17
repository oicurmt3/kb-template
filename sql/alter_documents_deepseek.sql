-- Run once on existing installs that already have `documents`.
-- Safe to skip ADD COLUMN lines that already exist (run the matching bits only).

ALTER TABLE documents
  ADD COLUMN ingest_mode ENUM('local','deepseek','ocr') NOT NULL DEFAULT 'local' AFTER content_hash,
  ADD COLUMN deepseek_file_id VARCHAR(128) DEFAULT NULL AFTER ingest_mode,
  ADD COLUMN parse_purpose VARCHAR(64) DEFAULT NULL AFTER deepseek_file_id,
  ADD KEY idx_ds_file (deepseek_file_id);

-- If columns already exist from an earlier alter (without ocr), only run:
-- ALTER TABLE documents MODIFY COLUMN ingest_mode ENUM('local','deepseek','ocr') NOT NULL DEFAULT 'local';
