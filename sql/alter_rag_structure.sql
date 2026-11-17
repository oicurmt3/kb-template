-- Structured parse / chunk / search_text metadata (run once on existing DBs)
-- Fresh installs: columns are already in sql/schema.sql

ALTER TABLE documents
  ADD COLUMN domain_set ENUM('general','legislation','medical') NOT NULL DEFAULT 'general' AFTER parse_purpose,
  ADD COLUMN issuer VARCHAR(255) DEFAULT NULL AFTER domain_set,
  ADD COLUMN jurisdiction VARCHAR(100) DEFAULT NULL AFTER issuer,
  ADD COLUMN doc_version VARCHAR(100) DEFAULT NULL AFTER jurisdiction,
  ADD COLUMN publication_date VARCHAR(64) DEFAULT NULL AFTER doc_version,
  ADD COLUMN as_at_or_review_date VARCHAR(64) DEFAULT NULL AFTER publication_date,
  ADD COLUMN status_text VARCHAR(255) DEFAULT NULL AFTER as_at_or_review_date,
  ADD COLUMN layout_json JSON DEFAULT NULL AFTER status_text,
  ADD COLUMN intake_json JSON DEFAULT NULL AFTER layout_json,
  ADD COLUMN sidecar_json LONGTEXT DEFAULT NULL AFTER intake_json,
  ADD COLUMN pipeline_stage VARCHAR(32) DEFAULT NULL AFTER sidecar_json,
  ADD COLUMN pipeline_state JSON DEFAULT NULL AFTER pipeline_stage,
  ADD COLUMN pipeline_error TEXT DEFAULT NULL AFTER pipeline_state;

ALTER TABLE chunks
  ADD COLUMN content_type VARCHAR(64) DEFAULT NULL AFTER header_path,
  ADD COLUMN start_line INT UNSIGNED DEFAULT NULL AFTER content_type,
  ADD COLUMN end_line INT UNSIGNED DEFAULT NULL AFTER start_line,
  ADD COLUMN flags JSON DEFAULT NULL AFTER end_line,
  ADD COLUMN extra JSON DEFAULT NULL AFTER flags,
  ADD COLUMN plain_summary TEXT DEFAULT NULL AFTER extra,
  ADD COLUMN sample_questions JSON DEFAULT NULL AFTER plain_summary,
  ADD COLUMN keywords JSON DEFAULT NULL AFTER sample_questions,
  ADD COLUMN self_contained TINYINT(1) DEFAULT NULL AFTER keywords,
  ADD COLUMN doc_version VARCHAR(100) DEFAULT NULL AFTER self_contained,
  ADD COLUMN as_at_or_review_date VARCHAR(64) DEFAULT NULL AFTER doc_version,
  ADD COLUMN doc_status_text VARCHAR(255) DEFAULT NULL AFTER as_at_or_review_date,
  ADD COLUMN domain_set ENUM('general','legislation','medical') DEFAULT NULL AFTER doc_status_text;
