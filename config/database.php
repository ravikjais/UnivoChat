<?php
require_once __DIR__ . '/config.php';

class Database {
    private static ?self $instance = null;
    private PDO $conn;

    private function __construct() {
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
            $this->conn = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);

            // Pin the connection to the application timezone (IST). Without
            // this the server's own zone decides what NOW() means and what a
            // TIMESTAMP column reads back as — UTC on most shared hosts — while
            // PHP formats those values as Asia/Kolkata, putting every message
            // 5½ hours out. TIMESTAMP columns are stored as UTC internally, so
            // this also corrects rows written before the fix.
            $this->conn->exec("SET time_zone = '" . tz_offset() . "'");
        } catch (PDOException $e) {
            db_fail($e);
        }
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO {
        return $this->conn;
    }
}

/**
 * Single place where a database failure is turned into a response. Details are
 * only ever shown on a development box; production gets a generic message and
 * the real error goes to the PHP error log.
 */
function db_fail(Throwable $e, bool $connection = true): never {
    error_log('[UnivoChat] Database error: ' . $e->getMessage());

    $detail  = APP_DEBUG ? $e->getMessage() : '';
    $message = $connection
        ? 'Database connection failed. Please make sure MySQL is running and that the '
          . 'database has been created (run setup/install.php).'
        : 'Something went wrong while talking to the database. Please try again.';

    if (is_api_request()) {
        json_response(array_filter(['error' => $message, 'detail' => $detail]), 500);
    }

    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Database error</title>'
       . '<div style="font:16px/1.6 system-ui,sans-serif;max-width:640px;margin:12vh auto;padding:2rem;'
       . 'border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.12)">'
       . '<h1 style="color:#FF4444;font-size:1.3rem;margin:0 0 .75rem">Database unavailable</h1>'
       . '<p style="margin:0 0 1rem;color:#54656F">' . e($message) . '</p>'
       . ($detail ? '<pre style="background:#F0F2F5;padding:.85rem;border-radius:8px;white-space:pre-wrap;'
                  . 'font-size:.85rem;color:#c0392b">' . e($detail) . '</pre>' : '')
       . '</div>';
    exit;
}

function db(): PDO {
    return Database::getInstance()->getConnection();
}

/**
 * Any uncaught database (or other) exception becomes a readable page/JSON body
 * instead of a blank screen or a stack trace leaking credentials.
 */
set_exception_handler(static function (Throwable $e): void {
    db_fail($e, $e instanceof PDOException ? str_contains($e->getMessage(), 'SQLSTATE[HY000] [2') : false);
});

function query(string $sql, array $params = []): PDOStatement {
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        // Logged here, rethrown so callers can handle expected failures such as
        // a unique-key violation; anything unhandled hits the handler above.
        error_log('[UnivoChat] Query failed: ' . $e->getMessage() . ' — SQL: ' . $sql);
        throw $e;
    }
}

function fetch_one(string $sql, array $params = []): ?array {
    $row = query($sql, $params)->fetch();
    return $row ?: null;
}

function fetch_all(string $sql, array $params = []): array {
    return query($sql, $params)->fetchAll();
}

function fetch_col(string $sql, array $params = []): array {
    return query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
}
