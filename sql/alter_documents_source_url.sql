-- Add clickable source URL for document citations
ALTER TABLE documents
  ADD COLUMN source_url VARCHAR(500) DEFAULT NULL AFTER tags;
