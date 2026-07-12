<?php
/**
 * POST handlers for the newer admin sections (included from admin.php's action block):
 * verification requests (§5.2), locations (§14), content pages (§16.2), admins & roles,
 * backups, ad credit adjustments (§9.4). Runs with $u (admin), $do, $id in scope.
 */

$isSuper = $u['account_type'] === 'super_admin';

if ($do === 'vr_review' && in_array($_POST['status'] ?? '', ['approved', 'rejected', 'changes_requested'], true)) {
    $req = row("SELECT vr.*, b.business_name FROM verification_requests vr JOIN businesses b ON b.id = vr.business_id WHERE vr.id = ?", [$id]);
    if ($req) {
        $note = trim($_POST['admin_note'] ?? '') ?: null;
        q("UPDATE verification_requests SET status = ?, admin_note = ?, reviewed_by = ? WHERE id = ?",
          [$_POST['status'], $note, $u['id'], $id]);
        if ($_POST['status'] === 'approved') {
            q("UPDATE businesses SET verification_status = ? WHERE id = ?", [$req['requested_level'], $req['business_id']]);
            notify_business((int)$req['business_id'], 'verification_approved',
                'Verification approved — ' . str_replace('_', ' ', $req['requested_level']) . ' badge is now active', 'vendor/verification', '', true);
        } elseif ($_POST['status'] === 'rejected') {
            notify_business((int)$req['business_id'], 'verification_rejected', 'Your verification request was rejected', 'vendor/verification', $note ?? '');
        } else {
            notify_business((int)$req['business_id'], 'verification_rejected', 'Your verification request needs changes', 'vendor/verification', $note ?? '');
        }
        flash('Verification request ' . str_replace('_', ' ', $_POST['status']) . '.');
    }

} elseif ($do === 'loc_add' && trim($_POST['name'] ?? '') !== '') {
    $level = in_array($_POST['level'] ?? '', ['country', 'region', 'city', 'subcity', 'woreda', 'area'], true) ? $_POST['level'] : 'city';
    q("INSERT INTO locations (parent_id, name, level, latitude, longitude) VALUES (?,?,?,?,?)",
      [(int)($_POST['parent_id'] ?? 0) ?: null, trim($_POST['name']), $level,
       (float)($_POST['latitude'] ?? 0) ?: null, (float)($_POST['longitude'] ?? 0) ?: null]);
    flash('Location added.');
} elseif ($do === 'loc_toggle') {
    q("UPDATE locations SET status = IF(status='active','inactive','active') WHERE id = ?", [$id]);
    flash('Location toggled.');

} elseif ($do === 'page_save') {
    $title = trim($_POST['title'] ?? '');
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $_POST['slug'] ?? ''), '-'));
    $body = trim($_POST['body'] ?? '');
    $status = ($_POST['page_status'] ?? '') === 'draft' ? 'draft' : 'published';
    if ($title === '' || $slug === '') {
        flash('Title and slug are required.', 'error');
    } elseif ($id) {
        q("UPDATE content_pages SET slug = ?, title = ?, body = ?, status = ?, updated_by = ? WHERE id = ?",
          [$slug, $title, $body, $status, $u['id'], $id]);
        flash('Page updated.');
    } else {
        q("INSERT INTO content_pages (slug, title, body, status, updated_by) VALUES (?,?,?,?,?)",
          [$slug, $title, $body, $status, $u['id']]);
        flash('Page created.');
    }

} elseif ($do === 'admin_create' && $isSuper) {
    $name = trim($_POST['full_name'] ?? '');
    $phone = preg_replace('/[^\d+]/', '', $_POST['phone'] ?? '');
    $pass = $_POST['password'] ?? '';
    $role = ($_POST['role'] ?? '') === 'super_admin' ? 'super_admin' : 'admin';
    if (mb_strlen($name) < 2 || strlen($phone) < 9 || strlen($pass) < 8) {
        flash('Name, phone and a password of at least 8 characters are required.', 'error');
    } elseif (val("SELECT COUNT(*) FROM users WHERE phone = ?", [$phone])) {
        flash('That phone is already registered.', 'error');
    } else {
        q("INSERT INTO users (full_name, phone, password, account_type, status, phone_verified_at)
           VALUES (?,?,?,?, 'active', NOW())", [$name, $phone, password_hash($pass, PASSWORD_BCRYPT), $role]);
        flash(ucfirst(str_replace('_', ' ', $role)) . ' account created for ' . $name . '.');
    }
} elseif ($do === 'admin_revoke' && $isSuper && $id !== (int)$u['id']) {
    q("UPDATE users SET account_type = 'customer' WHERE id = ? AND account_type = 'admin'", [$id]);
    flash('Admin rights revoked.');

} elseif ($do === 'backup_download' && $isSuper) {
    // Pure-PHP SQL dump (§16.2 Backups / launch checklist "Backup configured") — deliberately
    // does not shell out to the mysqldump binary: shared hosts commonly disable exec()/passthru(),
    // and a hardcoded local binary path would never work in production anyway.
    $schemaOnly = ($_POST['backup_mode'] ?? '') === 'schema';
    $file = 'ezihgebeya-' . ($schemaOnly ? 'schema' : 'backup') . '-' . date('Ymd-His') . '.sql';
    $sql = db_tool_dump_sql(db(), DB_NAME, $schemaOnly);
    audit('backup_download', 'database', null, $file);
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . strlen($sql));
    echo $sql;
    exit;

} elseif ($do === 'backup_restore' && $isSuper) {
    if (($_POST['confirm_restore'] ?? '') !== 'RESTORE DATABASE') {
        flash('Type RESTORE DATABASE to confirm — this overwrites existing tables with the same names.', 'error');
    } elseif (empty($_FILES['restore_file']['tmp_name']) || $_FILES['restore_file']['error'] !== UPLOAD_ERR_OK) {
        flash('Choose a .sql backup file to restore.', 'error');
    } else {
        try {
            $sql = (string)file_get_contents($_FILES['restore_file']['tmp_name']);
            if (trim($sql) === '') throw new RuntimeException('Uploaded file is empty.');
            $ran = db_tool_exec_sql(db(), $sql, false);
            audit('backup_restore', 'database', null, $ran['executed'] . ' statements');
            flash('Restore complete: ' . $ran['executed'] . ' statements executed.');
        } catch (Throwable $e) {
            flash('Restore failed: ' . $e->getMessage(), 'error');
        }
    }

} elseif ($do === 'uploads_backup_download' && $isSuper) {
    if (!class_exists('ZipArchive')) {
        flash('PHP ZipArchive extension is not available on this server — back up the uploads/ directory manually via FTP/SFTP.', 'error');
    } else {
        $zipPath = sys_get_temp_dir() . '/ezihgebeya-uploads-' . date('Ymd-His') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            flash('Could not create the uploads backup archive.', 'error');
        } else {
            $base = realpath(UPLOAD_DIR);
            $files = 0;
            if ($base) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $file) {
                    $localName = 'uploads/' . str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
                    $zip->addFile($file->getPathname(), $localName);
                    $files++;
                }
            }
            $zip->close();
            audit('uploads_backup_download', 'uploads', null, $files . ' files');
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . basename($zipPath) . '"');
            header('Content-Length: ' . filesize($zipPath));
            readfile($zipPath);
            unlink($zipPath);
            exit;
        }
    }

} elseif ($do === 'uploads_restore' && $isSuper) {
    if (($_POST['confirm_uploads_restore'] ?? '') !== 'RESTORE UPLOADS') {
        flash('Type RESTORE UPLOADS to confirm — this overwrites existing files with the same names.', 'error');
    } elseif (!class_exists('ZipArchive')) {
        flash('PHP ZipArchive extension is not available on this server.', 'error');
    } elseif (empty($_FILES['uploads_zip']['tmp_name']) || $_FILES['uploads_zip']['error'] !== UPLOAD_ERR_OK) {
        flash('Choose a .zip uploads backup to restore.', 'error');
    } else {
        $zip = new ZipArchive();
        if ($zip->open($_FILES['uploads_zip']['tmp_name']) !== true) {
            flash('Could not read the uploaded archive.', 'error');
        } else {
            // Zip-bomb guard: a tiny compressed archive can claim to expand to many GB and
            // exhaust disk space during extraction. Cap entry count and both per-entry and
            // total uncompressed size before writing anything out.
            $maxEntries = 20000;
            $maxEntryBytes = 500 * 1024 * 1024;      // 500 MB per file
            $maxTotalBytes = 5 * 1024 * 1024 * 1024; // 5 GB for the whole archive
            $numFiles = $zip->numFiles;
            $totalBytes = 0;
            $tooLarge = $numFiles > $maxEntries;
            if (!$tooLarge) {
                for ($i = 0; $i < $numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    $size = (int)($stat['size'] ?? 0);
                    if ($size > $maxEntryBytes) { $tooLarge = true; break; }
                    $totalBytes += $size;
                    if ($totalBytes > $maxTotalBytes) { $tooLarge = true; break; }
                }
            }
            if ($tooLarge) {
                $zip->close();
                flash('Archive rejected: too many files or too large once uncompressed (possible zip bomb).', 'error');
            } else {
            $base = realpath(UPLOAD_DIR);
            $restored = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false) continue;
                $name = ltrim((string)preg_replace('#^uploads/#', '', $name), '/');
                // block path traversal / absolute paths from a hostile archive
                if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/') || str_ends_with($name, '/')) continue;
                $dest = $base . '/' . $name;
                if (!str_starts_with(realpath(dirname($dest)) ?: dirname($dest), $base)) continue;
                $destDir = dirname($dest);
                if (!is_dir($destDir)) mkdir($destDir, 0755, true);
                $stream = $zip->getStream($name);
                if ($stream === false) continue;
                $out = fopen($dest, 'wb');
                stream_copy_to_stream($stream, $out);
                fclose($out);
                fclose($stream);
                $restored++;
            }
            $zip->close();
            audit('uploads_restore', 'uploads', null, $restored . ' files');
            flash('Uploads restore complete: ' . $restored . ' files written.');
            }
        }
    }

} elseif ($do === 'db_install_schema' && $isSuper) {
    if (($_POST['confirm_install'] ?? '') !== 'CREATE EMPTY DATABASE') {
        flash('Type CREATE EMPTY DATABASE to confirm schema creation.', 'error');
    } else {
        try {
            $host = trim($_POST['db_host'] ?? DB_HOST);
            $name = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['db_name'] ?? DB_NAME);
            $user = trim($_POST['db_user'] ?? DB_USER);
            $pass = (string)($_POST['db_pass'] ?? '');
            if ($name === '') throw new RuntimeException('Database name is required.');
            $pdo = new PDO('mysql:host=' . $host . ';charset=utf8mb4', $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$name}`");
            $sql = db_tool_sql_file('setup.sql');
            $sql = preg_replace('/^\s*CREATE\s+DATABASE\b.*?;\s*/ims', '', $sql);
            $sql = preg_replace('/^\s*USE\s+`?[\w]+`?\s*;\s*/ims', '', $sql);
            $sql = preg_replace('/^\s*DROP\s+TABLE\b.*?;\s*/ims', '', $sql);
            $ran = db_tool_exec_sql($pdo, $sql, false);
            $upgradeStatements = 0;
            $upgradeFiles = glob(__DIR__ . '/../database/upgrade*.sql') ?: [];
            sort($upgradeFiles, SORT_NATURAL);
            foreach ($upgradeFiles as $upgradeFile) {
                $upgradeSql = db_tool_sql_file(basename($upgradeFile));
                $upgradeSql = preg_replace('/^\s*USE\s+`?[\w]+`?\s*;\s*/ims', '', $upgradeSql);
                $upgradeRan = db_tool_exec_sql($pdo, $upgradeSql, true);
                $upgradeStatements += $upgradeRan['executed'];
            }
            flash('Current empty database schema created for ' . $name . ' (' . ($ran['executed'] + $upgradeStatements) . ' statements).');
        } catch (Throwable $e) {
            flash('Schema creation failed: ' . $e->getMessage(), 'error');
        }
    }

} elseif ($do === 'db_run_migrations' && $isSuper) {
    try {
        q("CREATE TABLE IF NOT EXISTS db_migrations (
            migration VARCHAR(190) PRIMARY KEY,
            status ENUM('applied','partial') NOT NULL DEFAULT 'applied',
            statements_run INT NOT NULL DEFAULT 0,
            skipped_errors INT NOT NULL DEFAULT 0,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $files = glob(__DIR__ . '/../database/upgrade*.sql') ?: [];
        sort($files, SORT_NATURAL);
        $summary = [];
        foreach ($files as $file) {
            $base = basename($file);
            $sql = db_tool_sql_file($base);
            $sql = preg_replace('/^\s*USE\s+`?[\w]+`?\s*;\s*/ims', '', $sql);
            $ran = db_tool_exec_sql(db(), $sql, true);
            q("INSERT INTO db_migrations (migration, status, statements_run, skipped_errors)
               VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status), statements_run = VALUES(statements_run),
               skipped_errors = VALUES(skipped_errors), applied_at = CURRENT_TIMESTAMP",
              [$base, $ran['skipped'] ? 'partial' : 'applied', $ran['executed'], $ran['skipped']]);
            $summary[] = $base . ': ' . $ran['executed'] . ' run, ' . $ran['skipped'] . ' skipped';
        }
        flash('Migrations/heal complete. ' . implode(' | ', $summary));
    } catch (Throwable $e) {
        flash('Migration failed: ' . $e->getMessage(), 'error');
    }

} elseif ($do === 'db_optimize' && $isSuper) {
    try {
        $tables = rows("SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE'", [DB_NAME]);
        $count = 0;
        foreach ($tables as $t) {
            $name = str_replace('`', '', $t['table_name']);
            q("REPAIR TABLE `{$name}`");
            q("OPTIMIZE TABLE `{$name}`");
            $count++;
        }
        flash('Database repair/optimize completed for ' . $count . ' tables.');
    } catch (Throwable $e) {
        flash('Repair/optimize failed: ' . $e->getMessage(), 'error');
    }

} elseif ($do === 'sys_save' && $isSuper) {
    site_setting_set('system_settings', sanitize_system_settings($_POST['sys'] ?? []));
    flash('System settings saved — changes are live immediately.');
} elseif ($do === 'sys_reset' && $isSuper) {
    site_setting_set('system_settings', system_settings_defaults());
    flash('System settings reset to factory defaults.');

} elseif ($do === 'ad_credit' && $isSuper && (float)($_POST['amount'] ?? 0) > 0) {
    $amount = (float)$_POST['amount'];
    $ad = row("SELECT * FROM ads WHERE id = ?", [$id]);
    if ($ad) {
        q("UPDATE ads SET credited = credited + ?, spent = GREATEST(0, spent - ?) WHERE id = ?", [$amount, $amount, $id]);
        q("INSERT INTO payments (payer_id, ad_id, payment_type, amount, payment_method, reference_number, status, confirmed_by)
           VALUES (?,?, 'refund', ?, 'cash', ?, 'confirmed', ?)",
          [$u['id'], $id, -$amount, trim($_POST['note'] ?? '') ?: 'ad credit adjustment', $u['id']]);
        flash('Credited ' . money($amount) . ' back to the campaign (suspicious clicks / goodwill).');
    }
}

/** Pure-PHP SQL dump — portable across hosts that disable exec()/passthru(). Reads in
 * chunks so large tables don't exhaust the PHP memory limit on shared hosting. */
function db_tool_dump_sql(PDO $pdo, string $dbName, bool $schemaOnly): string {
    $out = "-- EzihGebeya backup ({$dbName}) — " . date('c') . "\n";
    $out .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    foreach ($tables as [$table]) {
        $out .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM)[1];
        $out .= $create . ";\n\n";
        if ($schemaOnly) continue;

        $count = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        $chunk = 500;
        $cols = null;
        for ($offset = 0; $offset < $count; $offset += $chunk) {
            $stmt = $pdo->query("SELECT * FROM `{$table}` LIMIT {$chunk} OFFSET {$offset}");
            $rowsBuf = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($cols === null) $cols = array_map(fn($c) => "`$c`", array_keys($row));
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($row));
                $rowsBuf[] = '(' . implode(',', $vals) . ')';
            }
            if ($rowsBuf) {
                $out .= "INSERT INTO `{$table}` (" . implode(',', $cols) . ") VALUES\n" . implode(",\n", $rowsBuf) . ";\n";
            }
        }
        if ($count > 0) $out .= "\n";
    }
    $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $out;
}

function db_tool_sql_file(string $file): string {
    $path = __DIR__ . '/../database/' . basename($file);
    if (!is_file($path)) throw new RuntimeException('SQL file not found: ' . basename($file));
    return (string)file_get_contents($path);
}

function db_tool_split_sql(string $sql): array {
    $clean = preg_replace('/^\s*--.*$/m', '', $sql);
    $parts = preg_split('/;\s*(?:\r?\n|$)/', (string)$clean);
    return array_values(array_filter(array_map('trim', $parts), fn($s) => $s !== ''));
}

function db_tool_exec_sql(PDO $pdo, string $sql, bool $skipExistingErrors): array {
    $executed = 0;
    $skipped = 0;
    foreach (db_tool_split_sql($sql) as $statement) {
        try {
            $pdo->exec($statement);
            $executed++;
        } catch (Throwable $e) {
            $msg = strtolower($e->getMessage());
            $safeSkip = str_contains($msg, 'duplicate column')
                || str_contains($msg, 'duplicate key name')
                || str_contains($msg, 'already exists')
                || str_contains($msg, 'duplicate entry')
                || str_contains($msg, 'check that column/key exists')
                || str_contains($msg, "can't drop")
                || str_contains($msg, 'unknown column');
            if ($skipExistingErrors && $safeSkip) {
                $skipped++;
                continue;
            }
            throw $e;
        }
    }
    return ['executed' => $executed, 'skipped' => $skipped];
}
