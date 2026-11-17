<?php
declare(strict_types=1);

/** Cached column presence checks for optional RAG structure migrations. */
class SchemaCompat {
    /** @var array<string,bool>|null */
    private static ?array $docCols = null;
    /** @var array<string,bool>|null */
    private static ?array $chunkCols = null;

    public static function reset(): void {
        self::$docCols = null;
        self::$chunkCols = null;
    }

    public static function hasDocumentColumn(string $name): bool {
        if (self::$docCols === null) {
            self::$docCols = self::loadColumns('documents');
        }
        return !empty(self::$docCols[$name]);
    }

    public static function hasChunkColumn(string $name): bool {
        if (self::$chunkCols === null) {
            self::$chunkCols = self::loadColumns('chunks');
        }
        return !empty(self::$chunkCols[$name]);
    }

    public static function hasRagStructure(): bool {
        return self::hasDocumentColumn('pipeline_stage') && self::hasChunkColumn('domain_set');
    }

    /** @return array<string,bool> */
    private static function loadColumns(string $table): array {
        try {
            $rows = DB::all('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
            $out = [];
            foreach ($rows as $r) {
                $f = (string)($r['Field'] ?? '');
                if ($f !== '') $out[$f] = true;
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }
}
