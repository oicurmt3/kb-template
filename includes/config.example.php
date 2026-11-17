<?php
// Copy to config.php and edit. Do not commit real credentials.

return [
    'db' => [
        'host'    => 'localhost',
        'name'    => 'deepseek_chatbot',
        'user'    => 'dbuser',
        'pass'    => 'dbpass',
        'charset' => 'utf8mb4',
    ],
    'deepseek' => [
        'api_key'     => 'sk-REPLACE_ME',
        'base_url'    => 'https://api.deepseek.com',
        'model'       => 'deepseek-chat',   // default chat
        'flash_model' => 'deepseek-flash',  // used for web tools / titles / deep thinking
    ],
    'admin' => [
        // Generate with install.php's hash tool, or:
        // php -r "echo password_hash('yourpass', PASSWORD_BCRYPT), PHP_EOL;"
        'password_hash' => '$2y$10$REPLACE_ME',
    ],
    'app' => [
        'title'          => 'WANDA',
        'session_cookie' => 'dscb_sid',
        'system_prompt'  => "You are WANDA, a helpful, concise assistant. Answer clearly in plain text only — no Markdown (no **, #, `, bullet lists with -, or code fences). Use normal sentences and paragraphs. If you don't know something, say so.",
        'rag_system_prompt' => "You are WANDA, a helpful Western Australian workers compensation document assistant in a normal multi-turn conversation. Use the provided Act/Regulations excerpts together with chat history. Resolve follow-ups using prior turns. Answer conversationally and specifically in plain text only — no Markdown (no **, #, `, bullet lists with -, numbered Markdown lists, or code fences). Use normal sentences and paragraphs. Do not include citation numbers such as [1], [2], or [n] in your reply — sources are shown separately in the UI. You may name the Act, Regulations, section, regulation, part, or division numbers in plain words when helpful.\n\nSource hierarchy: the Workers Compensation and Injury Management Act and its Regulations are authoritative law; fact sheets and guides are practical WorkCover guidance only. If both legal excerpts and a guide appear, lead with the Act/Regulations and you may add how a guide summarises the process. If both Act and Regulations excerpts are present and both apply, cover both.\n\nWhen answering: (1) give a direct answer; (2) state the legal basis (section/regulation numbers when the excerpts support them); (3) check related excerpts — definitions, exceptions, procedures, Act↔Regulations cross-references — and include them when they matter; (4) if the role is clear (worker, employer, insurer), phrase duties for that role; (5) if excerpts do not fully cover the question, say what is missing. Only refuse when excerpts and history do not cover the question.",
        'rate_limit'     => [
            'login_max'   => 10,
            'login_window'=> 600,
            'chat_max'    => 60,
            'chat_window' => 60,
        ],
        // Chat caps (overridable in Admin → Chats). 0 = unlimited.
        'chat_max_per_session' => 50,
        'chat_max_global'      => 500,
        'chat_retention_days'  => 0,
        'chat_list_limit'      => 100,
        // latest_reminder — https://api-docs.deepseek.com/guides/latest_reminder/
        'timezone'       => 'Australia/Perth',
        'user_location'  => 'Western Australia, Australia',
        'user_platform'  => 'Web',
        'user_language'  => 'English',
    ],
    'rag' => [
        'top_k'                 => 14,
        'max_per_document'      => 5,
        // Adaptive RAG (admin can override). false = legacy fixed-size retrieval.
        'adaptive_retrieval'    => false,
        'top_k_slim'            => 8,
        'top_k_slim_tagged'     => 6,
        'max_per_document_slim' => 3,
        'activate_docs_slim'    => 3,
        'max_history_messages'  => 24,
        'chunk_max_chars'       => 3200,
        'chunk_overlap'         => 200,
        'chunk_target_tokens'   => 800,
        'chunk_max_tokens'      => 2000,
        // Structure parse (G1/G2) after OCR; admin Re-parse then Re-chunk for migration
        'structure_parse'       => true,
        'structure_parse_force' => false,
        'pages_per_batch'       => 4,
        'model_chunker'         => false,
        'enrich_chunks'         => false,
        'audit_chunks'          => false,
        'max_upload_bytes'      => 5000000,
        // Local chunking (RAG markers preserved)
        'allowed_ext'           => ['md', 'txt', 'csv', 'json', 'html', 'htm'],
        // DeepSeek OCR — https://api-docs.deepseek.com/guides/ocr (returns text → local chunks)
        'ocr_ext'               => [
            'pdf', 'doc', 'docx', 'ppt', 'pptx',
            'png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp', 'tif', 'tiff',
        ],
        // DeepSeek Files parse — https://api-docs.deepseek.com/guides/document_parsing/ (file_id in chat)
        'parser_ext'            => [
            'pdf', 'doc', 'docx', 'ppt', 'pptx',
            'png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp', 'tif', 'tiff',
            'xls', 'xlsx', 'epub',
        ],
        'max_parser_bytes'      => 25000000,
        'parse_purpose'         => 'text_recognition', // or rich_layout_recognition
        'binary_ingest'         => 'ocr', // default for PDF/Office/images: ocr | deepseek
    ],
    // DeepSeek Search & Fetch: https://api-docs.deepseek.com/guides/search
    // Web tools on chat: https://api-docs.deepseek.com/guides/web_tools/
    'web' => [
        'enabled'           => true,
        'timeout'           => 30,
        'max_context_chars' => 12000,
        'search_results'    => 5,
        // Default hosts when admin has not saved an override (Admin → Web search & fetch domains)
        'allowed_hosts'     => ['workcover.wa.gov.au'],
    ],
];
