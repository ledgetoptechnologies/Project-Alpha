<?php

declare(strict_types=1);

require_once __DIR__ . '/migration_lib.php';

function create_required_migration_backup(): string
{
    $host = getenv('DB_HOST') ?: 'db';
    $port = getenv('DB_PORT') ?: '3306';
    $db = getenv('MYSQL_DATABASE') ?: 'project_alpha';
    $user = getenv('MYSQL_USER') ?: 'root';
    $pass = getenv('MYSQL_PASSWORD') ?: getenv('MYSQL_ROOT_PASSWORD') ?: 'rootpass';
    $directory = getenv('MIGRATION_BACKUP_DIR') ?: '/var/www/backups/pre-migration';

    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException("Cannot create migration backup directory '$directory'.");
    }
    if (!is_writable($directory)) {
        throw new RuntimeException("Migration backup directory '$directory' is not writable.");
    }

    $base = $directory . DIRECTORY_SEPARATOR . $db . '_' . gmdate('Y-m-d_H-i-s');
    $temporary = $base . '.sql.tmp';
    $archive = $base . '.sql.gz';
    $command = [
        'mysqldump', "--host=$host", "--port=$port", "--user=$user",
        '--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces',
        "--result-file=$temporary", $db,
    ];
    $environment = array_merge($_ENV, ['MYSQL_PWD' => $pass]);
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start mysqldump.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0 || !is_file($temporary) || filesize($temporary) === 0) {
        @unlink($temporary);
        throw new RuntimeException('Pre-migration backup failed: ' . trim($stderr ?: $stdout));
    }

    $input = fopen($temporary, 'rb');
    $output = gzopen($archive, 'wb9');
    if ($input === false || $output === false) {
        if (is_resource($input)) {
            fclose($input);
        }
        @unlink($temporary);
        @unlink($archive);
        throw new RuntimeException('Could not compress the pre-migration backup.');
    }
    while (!feof($input)) {
        $chunk = fread($input, 1024 * 1024);
        if ($chunk === false || gzwrite($output, $chunk) === false) {
            fclose($input);
            gzclose($output);
            @unlink($temporary);
            @unlink($archive);
            throw new RuntimeException('Could not write the compressed pre-migration backup.');
        }
    }
    fclose($input);
    gzclose($output);
    @unlink($temporary);

    if (!is_file($archive) || filesize($archive) === 0) {
        throw new RuntimeException('Compressed pre-migration backup is empty.');
    }

    return $archive;
}

function migration_lock_retry_attempts(): int
{
    $configured = (int)(getenv('MIGRATION_LOCK_RETRY_ATTEMPTS') ?: 6);
    return max(1, min(20, $configured));
}

function migration_lock_retry_delay_ms(int $attempt): int
{
    $baseMs = (int)(getenv('MIGRATION_LOCK_RETRY_BASE_MS') ?: 250);
    $baseMs = max(50, min(5000, $baseMs));
    return min(10000, $baseMs * (2 ** max(0, $attempt - 1)));
}

function migration_is_retryable_lock_failure(Throwable $error): bool
{
    if (!$error instanceof PDOException) {
        return false;
    }

    $sqlState = (string)$error->getCode();
    $driverCode = isset($error->errorInfo[1]) ? (int)$error->errorInfo[1] : 0;

    return in_array($sqlState, ['40001', 'HY000'], true)
        && in_array($driverCode, [1205, 1213], true);
}

function migration_execute_with_lock_retry(callable $operation, string $description)
{
    $attempts = migration_lock_retry_attempts();
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        try {
            return $operation();
        } catch (Throwable $error) {
            if ($attempt >= $attempts || !migration_is_retryable_lock_failure($error)) {
                throw $error;
            }

            $delayMs = migration_lock_retry_delay_ms($attempt);
            fwrite(STDERR, sprintf(
                "Retrying %s after transient MySQL lock failure (%s/%s): %s\n",
                $description,
                $attempt,
                $attempts,
                $error->getMessage()
            ));
            usleep($delayMs * 1000);
        }
    }

    throw new RuntimeException("Could not complete {$description}.");
}

try {
    $arguments = $argv ?? [];
    $dryRun = in_array('--dry-run', $arguments, true);
    $verbose = in_array('--verbose', $arguments, true) || in_array('-v', $arguments, true);
    $validateFilesOnly = in_array('--validate-files', $arguments, true);
    $directory = dirname(__DIR__, 2) . '/database/migrations';
    $files = migration_files($directory);

    if ($validateFilesOnly) {
        fwrite(STDOUT, 'Migration files valid: ' . count($files) . PHP_EOL);
        exit(0);
    }

    $pdo = migration_connection();
    $ledger = migration_ledger($pdo);
    migration_validate_history($files, $ledger);
    $pending = array_diff_key($files, $ledger);

    // Validate every non-transactional legacy-data invariant before applying
    // any pending DDL. This keeps a refused migration's schema and ledger
    // exactly as they were at runner entry.
    foreach ($pending as $file) {
        migration_preflight($pdo, (int)$file['version']);
    }

    if ($dryRun) {
        foreach ($pending as $file) {
            $sql = file_get_contents($file['path']);
            migration_statements((string) $sql);
            fwrite(STDOUT, sprintf("Would apply %04d %s (%s)\n", $file['version'], $file['filename'], substr($file['checksum'], 0, 12)));
        }
        migration_schema_health($pdo, max(array_keys($ledger)));
        fwrite(STDOUT, 'Dry run passed; pending migrations: ' . count($pending) . PHP_EOL);
        exit(0);
    }

    if ($pending !== []) {
        $backup = create_required_migration_backup();
        fwrite(STDOUT, "Pre-migration backup created: $backup\n");
    }

    foreach ($pending as $file) {
        $sql = file_get_contents($file['path']);
        foreach (migration_statements((string) $sql) as $statement) {
            $result = migration_execute_with_lock_retry(
                static fn() => $pdo->query($statement),
                $file['filename']
            );
            if ($result !== false) {
                $result->closeCursor();
            }
        }
        $record = $pdo->prepare(
            'INSERT INTO schema_migrations (version, filename, checksum) VALUES (?, ?, ?)'
        );
        migration_execute_with_lock_retry(
            static fn() => $record->execute([$file['version'], $file['filename'], $file['checksum']]),
            $file['filename'] . ' ledger insert'
        );
        if ($verbose) {
            fwrite(STDOUT, "Applied {$file['filename']}.\n");
        }
    }

    migration_schema_health($pdo);
    fwrite(STDOUT, 'Migration and schema validation passed; applied: ' . count($pending) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
