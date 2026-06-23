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

    function qodaStripSqlComments(string $sql): string
    {
        $clean = [];
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $clean[] = $line;
        }
        return implode("\n", $clean);
    }

    function qodaSplitSqlParts(string $sql): array
    {
        $parts = [];
        $buffer = '';
        $inSingle = false;
        $inDouble = false;
        $parenDepth = 0;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $prev = $i > 0 ? $sql[$i - 1] : '';

            if ($char === "'" && !$inDouble && $prev !== '\\') {
                $inSingle = !$inSingle;
            } elseif ($char === '"' && !$inSingle && $prev !== '\\') {
                $inDouble = !$inDouble;
            } elseif (!$inSingle && !$inDouble) {
                if ($char === '(') {
                    $parenDepth++;
                } elseif ($char === ')' && $parenDepth > 0) {
                    $parenDepth--;
                }
            }

            if ($char === ',' && !$inSingle && !$inDouble && $parenDepth === 0) {
                $part = trim($buffer);
                if ($part !== '') {
                    $parts[] = $part;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $parts[] = $tail;
        }

        return $parts;
    }

    function qodaColumnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    }

    function qodaMigrationTableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    function qodaIndexExists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND INDEX_NAME = ?
        ");
        $stmt->execute([$table, $index]);
        return (int)$stmt->fetchColumn() > 0;
    }

    function qodaExecMigrationStatement(PDO $pdo, string $statement): void
    {
        if (
            preg_match('/^CREATE\s+(UNIQUE\s+)?INDEX\s+IF\s+NOT\s+EXISTS\s+`?([A-Za-z0-9_]+)`?\s+ON\s+`?([A-Za-z0-9_]+)`?\s*(\(.+\))$/is', $statement, $matches)
        ) {
            $unique = trim((string)($matches[1] ?? ''));
            $index = $matches[2];
            $table = $matches[3];
            $columns = trim($matches[4]);
            if (!qodaMigrationTableExists($pdo, $table)) {
                return;
            }
            preg_match_all('/`?([A-Za-z0-9_]+)`?(?:\s*\([0-9]+\))?/', trim($columns, '()'), $columnMatches);
            foreach ($columnMatches[1] ?? [] as $columnName) {
                if (!qodaColumnExists($pdo, $table, $columnName)) {
                    return;
                }
            }
            if (!qodaIndexExists($pdo, $table, $index)) {
                $pdo->exec("CREATE {$unique}INDEX `$index` ON `$table` $columns");
            }
            return;
        }

        if (
            stripos($statement, 'ADD COLUMN IF NOT EXISTS') !== false
            && preg_match('/^ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?\s+(.+)$/is', $statement, $matches)
        ) {
            $table = $matches[1];
            foreach (qodaSplitSqlParts($matches[2]) as $part) {
                if (preg_match('/^ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+`?([A-Za-z0-9_]+)`?\s+(.+)$/is', $part, $columnMatch)) {
                    $column = $columnMatch[1];
                    $definition = trim($columnMatch[2]);
                    if (!qodaColumnExists($pdo, $table, $column)) {
                        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
                    }
                    continue;
                }

                $pdo->exec("ALTER TABLE `$table` $part");
            }
            return;
        }

        $pdo->exec($statement);
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
            $rawSql = file_get_contents($file);
            $checksum = hash('sha256', $rawSql);
            $sql = qodaStripSqlComments($rawSql);

            if (isset($applied[$name])) {
                $results[] = [
                    'migration' => $name,
                    'status' => $applied[$name] === $checksum ? 'skipped' : 'changed_after_apply',
                ];
                continue;
            }

            try {
                $pdo->beginTransaction();
                foreach (qodaSplitSqlStatements($sql) as $statement) {
                    qodaExecMigrationStatement($pdo, $statement);
                }
                $stmt = $pdo->prepare("INSERT INTO schema_migrations (migration, checksum) VALUES (?, ?)");
                $stmt->execute([$name, $checksum]);
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }
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
