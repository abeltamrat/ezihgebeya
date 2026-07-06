<?php
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function row(string $sql, array $params = []) { return q($sql, $params)->fetch() ?: null; }
function rows(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(); }
function val(string $sql, array $params = []) { return q($sql, $params)->fetchColumn(); }
