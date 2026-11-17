-- If you already ran alter_documents_deepseek.sql without `ocr` in the enum:
ALTER TABLE documents
  MODIFY COLUMN ingest_mode ENUM('local','deepseek','ocr') NOT NULL DEFAULT 'local';
