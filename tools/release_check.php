<?php
declare(strict_types=1);

/** Read-only release gate for local/CI use.
 * Usage: php tools/release_check.php [--production]
 */
$root = dirname(__DIR__);
$production = in_array('--production', $argv, true);
$_SERVER['HTTP_HOST'] ??= 'localhost';
require_once $root . '/config.php';
require_once $root . '/app/db.php';

$errors = []; $warnings = []; $passes = [];
$pass = static function (string $message) use (&$passes): void { $passes[] = $message; };
$warn = static function (string $message) use (&$warnings): void { $warnings[] = $message; };
$fail = static function (string $message) use (&$errors): void { $errors[] = $message; };

is_file($root . '/app/index.html') ? $pass('React production shell exists.') : $fail('React production shell is missing. Build frontend/ and copy dist/* to app/.');
is_dir($root . '/app/assets') ? $pass('React production assets exist.') : $fail('React production assets are missing at app/assets/.');
foreach (['uploads', 'protected_uploads', 'storage/cache'] as $directory) {
    $path = $root . '/' . $directory;
    if (!is_dir($path)) $fail("Required runtime directory is missing: {$directory}/");
    elseif (!is_writable($path)) $fail("PHP cannot write to runtime directory: {$directory}/");
    else $pass("Runtime directory is writable: {$directory}/");
}
is_file($root . '/uploads/.htaccess') ? $pass('Upload execution guard exists.') : $fail('uploads/.htaccess is missing.');
class_exists('ZipArchive') ? $pass('ZipArchive is available.') : $warn('ZipArchive is unavailable; upload backup/restore will be disabled.');

try {
    db()->query('SELECT 1');
    $pass('Database connection succeeds.');
    $migrationFiles = glob($root . '/database/upgrade*.sql') ?: [];
    $migrationNames = array_map('basename', $migrationFiles);
    sort($migrationNames, SORT_NATURAL);
    $applied = []; $partials = [];
    $tableExists = (bool)db()->query("SHOW TABLES LIKE 'db_migrations'")->fetchColumn();
    if ($tableExists) {
        foreach (db()->query('SELECT migration, status FROM db_migrations')->fetchAll() as $row) {
            $applied[] = (string)$row['migration'];
            if ($row['status'] !== 'applied') $partials[] = (string)$row['migration'];
        }
    }
    $missing = array_values(array_diff($migrationNames, $applied));
    if (!$tableExists) $fail('db_migrations does not exist. Run migrations from Admin -> Backups.');
    elseif ($missing) $fail('Unapplied migrations: ' . implode(', ', $missing));
    elseif ($partials) $fail('Partially applied migrations require review: ' . implode(', ', $partials));
    else $pass('All ' . count($migrationNames) . ' migration files are recorded as applied.');
} catch (Throwable $e) {
    $fail('Database release check failed: ' . $e->getMessage());
}

if ($production) {
    DEV_MODE ? $fail('DEV_MODE must be disabled in production.') : $pass('DEV_MODE is disabled.');
    CRON_SECRET === '' ? $fail('CRON_SECRET is empty.') : $pass('CRON_SECRET is configured.');
    DB_USER === 'root' && DB_PASS === '' ? $fail('Default local database credentials are active.') : $pass('Database credentials are not local defaults.');
    getenv('APP_BASE_URL') === false ? $warn('APP_BASE_URL is not explicitly configured; confirm the production domain root.') : $pass('APP_BASE_URL is explicitly configured.');
}

foreach ($passes as $message) echo "[PASS] {$message}\n";
foreach ($warnings as $message) echo "[WARN] {$message}\n";
foreach ($errors as $message) echo "[FAIL] {$message}\n";
echo sprintf("\nRelease check: %d passed, %d warning(s), %d failure(s).\n", count($passes), count($warnings), count($errors));
exit($errors ? 1 : 0);
