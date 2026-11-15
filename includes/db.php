<?php
declare(strict_types=1);

class DB {
    private static ?PDO $pdo = null;

    public static function init(array $cfg): void {
        $dsn = "mysql:host={$cfg['host']};dbname={$cfg['name']};charset={$cfg['charset']}";
        self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public static function pdo(): PDO {
        if (!self::$pdo) throw new RuntimeException('DB not initialized');
        return self::$pdo;
    }

    public static function run(string $sql, array $args = []): PDOStatement {
        $st = self::pdo()->prepare($sql);
        $st->execute($args);
        return $st;
    }

    public static function one(string $sql, array $args = []): ?array {
        $row = self::run($sql, $args)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $args = []): array {
        return self::run($sql, $args)->fetchAll();
    }

    public static function lastId(): int {
        return (int)self::pdo()->lastInsertId();
    }
}
