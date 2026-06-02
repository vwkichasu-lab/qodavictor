<?php

require_once __DIR__ . '/../config/database.php';

if (!function_exists('qodaEnsureMigrationTable')) {
    function qodaEnsureMigrationTable(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS schema_migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(190) NOT NULL UNIQUE,
                checksum CHAR(64) NOT NULL,
                applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    function qodaAppliedMigrations(PDO $pdo): array
    {
        qodaEnsureMigrationTable($pdo);
        $stmt = $pdo->query("SELECT migration, checksum FROM schema_migrations ORDER BY migration");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $applied = [];
        foreach ($rows as $row) {
            $applied[$row['migration']] = $row['checksum'];
        }
        return $applied;
    }

    function qodaSplitSqlStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $inSingle = false;
        $inDouble = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $prev = $i > 0 ? $sql[$i - 1] : '';

            if ($char === "'" && !$inDouble && $prev !== '\\') {
                $inSingle = !$inSingle;
            } elseif ($char === '"' && !$inSingle && $prev !== '\\') {
                $inDouble = !$inDouble;
            }

            if ($char === ';' && !$inSingle && !$inDouble) {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }
        return $statements;
    }

    function qodaRunMigrations(PDO $pdo, string $migrationDir): array
    {
        qodaEnsureMigrationTable($pdo);
        $applied = qodaAppliedMigrations($pdo);
        $files = glob(rtrim($migrationDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($files, SORT_STRING);

        $results = [];
        foreach ($files as $file) {
            $name = basename($file);
            $sql = file_get_contents($file);
            $checksum = hash('sha256', $sql);

            if (isset($applied[$name])) {
                $results[] = [
                    'migration' => $name,
                    'status' => $applied[$name] === $checksum ? 'skipped' : 'changed_after_apply',
                ];
                continue;
            }

            $pdo->beginTransaction();
            try {
                foreach (qodaSplitSqlStatements($sql) as $statement) {
                    $pdo->exec($statement);
                }
                $stmt = $pdo->prepare("INSERT INTO schema_migrations (migration, checksum) VALUES (?, ?)");
                $stmt->execute([$name, $checksum]);
                $pdo->commit();
                $results[] = ['migration' => $name, 'status' => 'applied'];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $results[] = ['migration' => $name, 'status' => 'failed', 'error' => $e->getMessage()];
                break;
            }
        }

        return $results;
    }
}
