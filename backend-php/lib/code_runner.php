<?php

if (!function_exists('qodaFindExecutable')) {
    function qodaFindExecutable(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }

            $lookupCommand = DIRECTORY_SEPARATOR === '\\'
                ? 'where ' . escapeshellarg($candidate) . ' 2>NUL'
                : 'command -v ' . escapeshellarg($candidate) . ' 2>/dev/null';
            $path = trim((string)@shell_exec($lookupCommand));
            if ($path !== '') {
                $first = strtok($path, "\r\n");
                if ($first) return $first;
            }
        }
        return null;
    }

    function qodaBootstrapLocalCompilers(): void
    {
        $localCompilerBin = realpath(__DIR__ . '/../../tools/winlibs-gcc/mingw64/bin');
        if ($localCompilerBin) {
            $currentPath = getenv('PATH') ?: '';
            if (stripos($currentPath, $localCompilerBin) === false) {
                putenv('PATH=' . $localCompilerBin . PATH_SEPARATOR . $currentPath);
            }
        }
    }

    function qodaRunProcess(string $command, string $cwd, string $input = '', int $timeout = 8): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return ['ok' => false, 'output' => '', 'error' => 'Unable to start process.'];
        }

        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $error = '';
        $start = microtime(true);
        while (true) {
            $status = proc_get_status($process);
            $output .= stream_get_contents($pipes[1]);
            $error .= stream_get_contents($pipes[2]);

            if (!$status['running']) {
                break;
            }
            if ((microtime(true) - $start) > $timeout) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) fclose($pipe);
                }
                proc_close($process);
                $hint = trim($input) === ''
                    ? "\nIf this program expects input, add test-case input values and run again."
                    : '';
                return ['ok' => false, 'output' => $output, 'error' => "Execution timed out after {$timeout} seconds." . $hint];
            }
            usleep(100000);
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) fclose($pipe);
        }
        $exitCode = proc_close($process);
        return ['ok' => $exitCode === 0, 'output' => $output, 'error' => $error];
    }

    function qodaNormalizeLanguage(string $language): string
    {
        $language = strtolower(trim($language));
        $aliases = [
            'py' => 'python',
            'python3' => 'python',
            'js' => 'javascript',
            'node' => 'javascript',
            'c++' => 'cpp',
            'cs' => 'csharp',
            'c#' => 'csharp',
            'vb' => 'vbnet',
            'visualbasic' => 'vbnet',
            'visual-basic' => 'vbnet',
            'vb.net' => 'vbnet',
            'mssql' => 'sql',
            'mysql' => 'sql',
            'postgres' => 'sql',
            'postgresql' => 'sql',
            'sqlite' => 'sql',
        ];
        return $aliases[$language] ?? $language;
    }

    function qodaPublicClassName(string $code): string
    {
        if (preg_match('/public\s+(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/', $code, $match)) {
            return $match[1];
        }
        if (preg_match('/class\s+([A-Za-z_][A-Za-z0-9_]*)/', $code, $match)) {
            return $match[1];
        }
        return 'Main';
    }

    function qodaNormalizeProjectFiles(array $postedFiles): array
    {
        $safeFiles = [];
        $maxFiles = (int)(getenv('QODA_MAX_PROJECT_FILES') ?: 40);
        $maxFileBytes = (int)(getenv('QODA_MAX_PROJECT_FILE_BYTES') ?: 200000);
        foreach ($postedFiles as $file) {
            if (count($safeFiles) >= $maxFiles) {
                break;
            }
            $name = trim((string)($file['name'] ?? ''));
            if ($name === '') continue;
            $name = str_replace('\\', '/', $name);
            $name = preg_replace('#(^|/)\.\.(?=/|$)#', '', $name);
            $name = preg_replace('/[^A-Za-z0-9_\-\.\/]/', '_', $name);
            $name = trim($name, '/');
            if ($name === '') continue;
            $content = (string)($file['content'] ?? '');
            if (strlen($content) > $maxFileBytes) {
                $content = substr($content, 0, $maxFileBytes);
            }
            $safeFiles[] = [
                'name' => $name,
                'content' => $content,
                'language' => strtolower((string)($file['language'] ?? '')),
                'active' => !empty($file['active']),
            ];
        }
        return $safeFiles;
    }

    function qodaWriteProjectFiles(string $tmpBase, array $safeFiles): void
    {
        foreach ($safeFiles as $fileInfo) {
            $target = $tmpBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fileInfo['name']);
            $dir = dirname($target);
            if (!is_dir($dir)) mkdir($dir, 0700, true);
            file_put_contents($target, $fileInfo['content']);
        }
    }

    function qodaActiveFile(array $safeFiles, string $fallbackName, string $fallbackCode, string $extension): array
    {
        foreach ($safeFiles as $file) {
            if (!empty($file['active'])) return $file;
        }
        foreach ($safeFiles as $file) {
            if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === strtolower($extension)) return $file;
        }
        return ['name' => $fallbackName, 'content' => $fallbackCode, 'language' => '', 'active' => true];
    }

    function qodaProjectFilesByExtension(array $safeFiles, string $extension): array
    {
        return array_values(array_filter($safeFiles, function ($file) use ($extension) {
            return strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === strtolower($extension);
        }));
    }

    function qodaUsesWindowsDesktopGui(string $code): bool
    {
        return (bool)preg_match('/System\.Windows\.Forms|Application\.Run\s*\(|WindowsForms|<UseWindowsForms>\s*true/i', $code);
    }

    function qodaUsesJavaDesktopGui(string $code): bool
    {
        return (bool)preg_match('/javax\.swing|java\.awt|JFrame|JDialog|JWindow|SwingUtilities|Application\.launch\s*\(/i', $code);
    }

    function qodaRunDotnetProject(string $tmpBase, string $language, string $code, string $input): array
    {
        $dotnet = qodaFindExecutable(['dotnet', 'C:\\Program Files\\dotnet\\dotnet.exe']);
        if (!$dotnet) {
            return ['ok' => false, 'output' => '', 'error' => '.NET SDK is not installed on this server.'];
        }

        $template = $language === 'vbnet' ? 'console -lang VB' : 'console -lang C#';
        $create = qodaRunProcess('"' . $dotnet . '" new ' . $template . ' --force --no-restore', $tmpBase, '', 45);
        if (!$create['ok']) return $create;

        file_put_contents($tmpBase . DIRECTORY_SEPARATOR . ($language === 'vbnet' ? 'Program.vb' : 'Program.cs'), $code);
        $run = qodaRunProcess('"' . $dotnet . '" run --no-restore', $tmpBase, $input, 8);
        $combinedRunMessage = $run['output'] . "\n" . $run['error'];
        if (!$run['ok'] && str_contains($combinedRunMessage, 'project.assets.json')) {
            $restore = qodaRunProcess('"' . $dotnet . '" restore', $tmpBase, '', 45);
            if (!$restore['ok']) return $restore;
            $run = qodaRunProcess('"' . $dotnet . '" run --no-restore', $tmpBase, $input, 45);
        }
        return $run;
    }

    function qodaRunSqlScript(string $tmpBase, string $code): array
    {
        $sqlite = qodaFindExecutable(['sqlite3', 'C:\\sqlite\\sqlite3.exe']);
        if (!$sqlite) {
            return ['ok' => false, 'output' => '', 'error' => 'SQLite is not installed on this server.'];
        }

        $dbPath = $tmpBase . DIRECTORY_SEPARATOR . 'qoda_sql_workspace.db';
        $script = ".headers on\n.mode box\n" . $code . "\n";
        return qodaRunProcess('"' . $sqlite . '" "' . $dbPath . '"', $tmpBase, $script, 8);
    }

    function qodaRemoveDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    function checkQodaCodeSyntax(string $code, string $language, array $postedFiles = []): array
    {
        qodaBootstrapLocalCompilers();
        $language = qodaNormalizeLanguage($language);
        $executionRoot = __DIR__ . '/../../runtime/code-execution';
        if (!is_dir($executionRoot)) {
            mkdir($executionRoot, 0700, true);
        }
        $tmpBase = $executionRoot . DIRECTORY_SEPARATOR . 'qoda_check_' . bin2hex(random_bytes(6));
        if (!mkdir($tmpBase, 0700, true) && !is_dir($tmpBase)) {
            return ['success' => false, 'ok' => false, 'output' => '', 'error' => 'Unable to create syntax-check directory.'];
        }

        $started = microtime(true);
        try {
            $safeFiles = qodaNormalizeProjectFiles($postedFiles);
            qodaWriteProjectFiles($tmpBase, $safeFiles);

            switch ($language) {
                case 'python':
                    $exe = qodaFindExecutable(['python', 'python3', 'C:\\Python314\\python.exe']);
                    if (!$exe) return ['ok' => false, 'output' => '', 'error' => 'Python is not installed on this server.'];
                    $entry = qodaActiveFile($safeFiles, 'main.py', $code, 'py');
                    if (!$safeFiles) file_put_contents($tmpBase . DIRECTORY_SEPARATOR . $entry['name'], $entry['content']);
                    $file = $tmpBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['name']);
                    $result = qodaRunProcess('"' . $exe . '" -m py_compile "' . $file . '"', $tmpBase, '', 8);
                    break;

                case 'javascript':
                    $exe = qodaFindExecutable(['node', 'C:\\Program Files\\nodejs\\node.exe']);
                    if (!$exe) return ['ok' => false, 'output' => '', 'error' => 'Node.js is not installed on this server.'];
                    $entry = qodaActiveFile($safeFiles, 'main.js', $code, 'js');
                    if (!$safeFiles) file_put_contents($tmpBase . DIRECTORY_SEPARATOR . $entry['name'], $entry['content']);
                    $file = $tmpBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['name']);
                    $result = qodaRunProcess('"' . $exe . '" --check "' . $file . '"', $tmpBase, '', 8);
                    break;

                case 'php':
                    $exe = qodaFindExecutable(['php', 'C:\\xampp\\php\\php.exe', 'C:\\Program Files\\php-8.5.3\\php.exe']);
                    if (!$exe) return ['ok' => false, 'output' => '', 'error' => 'PHP CLI is not installed on this server.'];
                    $entry = qodaActiveFile($safeFiles, 'main.php', $code, 'php');
                    $file = $tmpBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['name']);
                    $entryContent = str_contains($entry['content'], '<?php') ? $entry['content'] : "<?php\n" . $entry['content'];
                    file_put_contents($file, $entryContent);
                    $result = qodaRunProcess('"' . $exe . '" -l "' . $file . '"', $tmpBase, '', 8);
                    break;

                case 'java':
                    $javac = qodaFindExecutable(['javac', 'C:\\Program Files\\Java\\jdk-21\\bin\\javac.exe', 'C:\\Program Files\\Java\\jdk-25\\bin\\javac.exe']);
                    if (!$javac) return ['ok' => false, 'output' => '', 'error' => 'Java JDK is not installed on this server.'];
                    $className = qodaPublicClassName($code);
                    if (!$safeFiles) {
                        $safeFiles[] = ['name' => $className . '.java', 'content' => $code, 'active' => true];
                        qodaWriteProjectFiles($tmpBase, $safeFiles);
                    }
                    foreach ($safeFiles as &$fileInfo) {
                        if (preg_match('/public\s+(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/', $fileInfo['content'], $match)) {
                            $fileInfo['name'] = $match[1] . '.java';
                            file_put_contents($tmpBase . DIRECTORY_SEPARATOR . $fileInfo['name'], $fileInfo['content']);
                        }
                    }
                    unset($fileInfo);
                    $javaFiles = qodaProjectFilesByExtension($safeFiles, 'java');
                    $javaArgs = implode(' ', array_map(fn($file) => '"' . str_replace('/', DIRECTORY_SEPARATOR, $file['name']) . '"', $javaFiles));
                    $result = qodaRunProcess('"' . $javac . '" ' . $javaArgs, $tmpBase, '', 15);
                    break;

                case 'c':
                case 'cpp':
                    $compiler = $language === 'c'
                        ? qodaFindExecutable(['gcc', __DIR__ . '/../../tools/winlibs-gcc/mingw64/bin/gcc.exe'])
                        : qodaFindExecutable(['g++', __DIR__ . '/../../tools/winlibs-gcc/mingw64/bin/g++.exe']);
                    if (!$compiler) return ['ok' => false, 'output' => '', 'error' => strtoupper($language) . ' compiler is not installed on this server.'];
                    $extension = $language === 'c' ? 'c' : 'cpp';
                    $entry = qodaActiveFile($safeFiles, 'main.' . $extension, $code, $extension);
                    if (!$safeFiles) file_put_contents($tmpBase . DIRECTORY_SEPARATOR . $entry['name'], $entry['content']);
                    $source = $tmpBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['name']);
                    $binary = $tmpBase . DIRECTORY_SEPARATOR . 'syntax_check.exe';
                    $result = qodaRunProcess('"' . $compiler . '" "' . $source . '" -o "' . $binary . '"', $tmpBase, '', 10);
                    break;

                case 'csharp':
                case 'vbnet':
                case 'sql':
                case 'html':
                case 'css':
                    return ['success' => true, 'ok' => true, 'output' => 'Syntax preflight passed.', 'error' => '', 'execution_time_ms' => 0, 'language' => $language];

                default:
                    return ['success' => true, 'ok' => true, 'output' => 'Syntax preflight skipped for this language.', 'error' => '', 'execution_time_ms' => 0, 'language' => $language];
            }

            $time = round((microtime(true) - $started) * 1000);
            return [
                'success' => !empty($result['ok']),
                'ok' => !empty($result['ok']),
                'output' => (string)($result['output'] ?? ''),
                'error' => (string)($result['error'] ?? ''),
                'execution_time_ms' => $time,
                'language' => $language,
            ];
        } finally {
            qodaRemoveDirectory($tmpBase);
        }
    }

    function executeQodaCode(string $code, string $language, string $input = '', array $postedFiles = []): array
    {
        qodaBootstrapLocalCompilers();
        $language = qodaNormalizeLanguage($language);
        $maxCodeBytes = (int)(getenv('QODA_MAX_CODE_BYTES') ?: 500000);
        $maxInputBytes = (int)(getenv('QODA_MAX_INPUT_BYTES') ?: 100000);

        if (strlen($code) > $maxCodeBytes) {
            return [
                'success' => false,
                'ok' => false,
                'output' => '',
                'error' => 'Code is too large for the exam runner.',
            ];
        }

        if (strlen($input) > $maxInputBytes) {
            return [
                'success' => false,
                'ok' => false,
                'output' => '',
                'error' => 'Program input is too large for the exam runner.',
            ];
        }

        if (trim($code) === '' && empty($postedFiles)) {
            return ['success' => false, 'ok' => false, 'output' => '', 'error' => 'No code received.'];
        }

        $executionRoot = __DIR__ . '/../../runtime/code-execution';
        if (!is_dir($executionRoot)) {
            mkdir($executionRoot, 0700, true);
        }
        $tmpBase = $executionRoot . DIRECTORY_SEPARATOR . 'qoda_exec_' . bin2hex(random_bytes(6));
        if (!mkdir($tmpBase, 0700, true) && !is_dir($tmpBase)) {
            return ['success' => false, 'ok' => false, 'output' => '', 'error' => 'Unable to create execution directory.'];
        }

        $started = microtime(true);
        try {
            $safeFiles = qodaNormalizeProjectFiles($postedFiles);
            qodaWriteProjectFiles($tmpBase, $safeFiles);

            switch ($language) {
                case 'python':
                    $exe = qodaFindExecutable(['python', 'python3', 'C:\\Python314\\python.exe']);
                    if (!$exe) return ['ok' => false, 'output' => '', 'error' => 'Python is not installed on this server.'];
                    $entry = qodaActiveFile($safeFiles, 'main.py', $code, 'py');
                    if (!$safeFiles) file_put_contents($tmpBase . DIRECTORY_SEPARATOR . $entry['name'], $entry['content']);
                    $file = $tmpBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['name']);
                    $result = qodaRunProcess('"' . $exe . '" "' . $file . '"', $tmpBase, $input);
                    break;

                case 'javascript':
                    $exe = qodaFindExecutable(['node', 'C:\\Program Files\\nodejs\\node.exe']);
                    if (!$exe) return ['ok' => false, 'output' => '', 'error' => 'Node.js is not installed on this server.'];
                    $entry = qodaActiveFile($safeFiles, 'main.js', $code, 'js');
                    if (!$safeFiles) file_put_contents($tmpBase . DIRECTORY_SEPARATOR . $entry['name'], $entry['content']);
                    $file = $tmpBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['name']);
                    $result = qodaRunProcess('"' . $exe . '" "' . $file . '"', $tmpBase, $input);
                    break;

                case 'php':
                    $exe = qodaFindExecutable(['php', 'C:\\xampp\\php\\php.exe', 'C:\\Program Files\\php-8.5.3\\php.exe']);
                    if (!$exe) return ['ok' => false, 'output' => '', 'error' => 'PHP CLI is not installed on this server.'];
                    $entry = qodaActiveFile($safeFiles, 'main.php', $code, 'php');
                    $file = $tmpBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['name']);
                    $entryContent = str_contains($entry['content'], '<?php') ? $entry['content'] : "<?php\n" . $entry['content'];
                    file_put_contents($file, $entryContent);
                    $result = qodaRunProcess('"' . $exe . '" "' . $file . '"', $tmpBase, $input);
                    break;

                case 'java':
                    $javac = qodaFindExecutable(['javac', 'C:\\Program Files\\Java\\jdk-21\\bin\\javac.exe', 'C:\\Program Files\\Java\\jdk-25\\bin\\javac.exe']);
                    $java = qodaFindExecutable(['java', 'C:\\Program Files\\Java\\jdk-21\\bin\\java.exe', 'C:\\Program Files\\Java\\jdk-25\\bin\\java.exe']);
                    if (!$javac || !$java) return ['ok' => false, 'output' => '', 'error' => 'Java JDK is not installed on this server.'];
                    $isJavaGui = qodaUsesJavaDesktopGui($code);
                    $className = qodaPublicClassName($code);
                    if (!$safeFiles) {
                        $safeFiles[] = ['name' => $className . '.java', 'content' => $code, 'active' => true];
                        qodaWriteProjectFiles($tmpBase, $safeFiles);
                    }
                    foreach ($safeFiles as &$fileInfo) {
                        $content = $fileInfo['content'];
                        $targetName = $fileInfo['name'];
                        if (preg_match('/public\s+(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/', $content, $match)) {
                            $targetName = $match[1] . '.java';
                            if (preg_match('/public\s+static\s+void\s+main\s*\(/', $content)) {
                                $className = $match[1];
                            }
                            $fileInfo['name'] = $targetName;
                        }
                        $target = $tmpBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $targetName);
                        $dir = dirname($target);
                        if (!is_dir($dir)) mkdir($dir, 0700, true);
                        file_put_contents($target, $content);
                    }
                    unset($fileInfo);
                    $javaFiles = qodaProjectFilesByExtension($safeFiles, 'java');
                    $javaArgs = implode(' ', array_map(function ($file) {
                        return '"' . str_replace('/', DIRECTORY_SEPARATOR, $file['name']) . '"';
                    }, $javaFiles));
                    $compile = qodaRunProcess('"' . $javac . '" ' . $javaArgs, $tmpBase, '', 15);
                    if (!$compile['ok']) {
                        $result = $compile;
                        break;
                    }
                    if ($isJavaGui) {
                        $result = [
                            'ok' => true,
                            'output' => "Java GUI code compiled successfully.\nDesktop JFrame/AWT windows cannot be displayed inside the Railway Linux server.",
                            'error' => '',
                        ];
                        break;
                    }
                    $result = qodaRunProcess('"' . $java . '" -cp "' . $tmpBase . '" ' . $className, $tmpBase, $input, 8);
                    break;

                case 'c':
                case 'cpp':
                    $compiler = $language === 'c'
                        ? qodaFindExecutable(['gcc', __DIR__ . '/../../tools/winlibs-gcc/mingw64/bin/gcc.exe'])
                        : qodaFindExecutable(['g++', __DIR__ . '/../../tools/winlibs-gcc/mingw64/bin/g++.exe']);
                    if (!$compiler) return ['ok' => false, 'output' => '', 'error' => strtoupper($language) . ' compiler is not installed on this server.'];
                    $extension = $language === 'c' ? 'c' : 'cpp';
                    $entry = qodaActiveFile($safeFiles, 'main.' . $extension, $code, $extension);
                    if (!$safeFiles) file_put_contents($tmpBase . DIRECTORY_SEPARATOR . $entry['name'], $entry['content']);
                    $source = $tmpBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['name']);
                    $binary = $tmpBase . DIRECTORY_SEPARATOR . 'main.exe';
                    $compile = qodaRunProcess('"' . $compiler . '" "' . $source . '" -o "' . $binary . '"', $tmpBase, '', 10);
                    if (!$compile['ok']) {
                        $result = $compile;
                        break;
                    }
                    $result = qodaRunProcess('"' . $binary . '"', $tmpBase, $input, 8);
                    break;

                case 'csharp':
                case 'vbnet':
                    if (qodaUsesWindowsDesktopGui($code)) {
                        $result = [
                            'ok' => false,
                            'output' => '',
                            'error' => 'Windows Forms requires a Windows desktop runtime. Console C#/VB.NET is supported on this server.',
                        ];
                        break;
                    }
                    $result = qodaRunDotnetProject($tmpBase, $language, $code, $input);
                    break;

                case 'sql':
                    $result = qodaRunSqlScript($tmpBase, $code);
                    break;

                default:
                    $result = ['ok' => false, 'output' => '', 'error' => "Language '{$language}' is not supported by the local executor yet."];
            }

            $time = round((microtime(true) - $started) * 1000);
            return [
                'success' => !empty($result['ok']),
                'ok' => !empty($result['ok']),
                'output' => (string)($result['output'] ?? ''),
                'error' => (string)($result['error'] ?? ''),
                'execution_time_ms' => $time,
                'language' => $language,
            ];
        } finally {
            qodaRemoveDirectory($tmpBase);
        }
    }
}
