<?php
header('Content-Type: application/json');

function respond($success, $output = '', $error = '', $extra = [])
{
    echo json_encode(array_merge([
        'success' => $success,
        'output' => $output,
        'error' => $error,
    ], $extra));
    exit;
}

function findExecutable(array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
        $where = trim((string)@shell_exec('where ' . escapeshellarg($candidate) . ' 2>NUL'));
        if ($where !== '') {
            $first = strtok($where, "\r\n");
            if ($first) return $first;
        }
    }
    return null;
}

$localCompilerBin = realpath(__DIR__ . '/../tools/winlibs-gcc/mingw64/bin');
if ($localCompilerBin) {
    $currentPath = getenv('PATH') ?: '';
    if (stripos($currentPath, $localCompilerBin) === false) {
        putenv('PATH=' . $localCompilerBin . PATH_SEPARATOR . $currentPath);
    }
}

function runProcess(string $command, string $cwd, string $input = '', int $timeout = 8): array
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
            $hint = trim($input) === '' ? "\nIf your program uses input, type sample values in the Program Input box and run again." : '';
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

function publicClassName(string $code): string
{
    if (preg_match('/public\s+(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/', $code, $match)) {
        return $match[1];
    }
    if (preg_match('/class\s+([A-Za-z_][A-Za-z0-9_]*)/', $code, $match)) {
        return $match[1];
    }
    return 'Main';
}

function normalizeProjectFiles(array $postedFiles): array
{
    $safeFiles = [];
    foreach ($postedFiles as $file) {
        $name = trim((string)($file['name'] ?? ''));
        if ($name === '') continue;
        $name = str_replace('\\', '/', $name);
        $name = preg_replace('#(^|/)\.\.(?=/|$)#', '', $name);
        $name = preg_replace('/[^A-Za-z0-9_\-\.\/]/', '_', $name);
        $name = trim($name, '/');
        if ($name === '') continue;
        $safeFiles[] = [
            'name' => $name,
            'content' => (string)($file['content'] ?? ''),
            'language' => strtolower((string)($file['language'] ?? '')),
            'active' => !empty($file['active']),
        ];
    }
    return $safeFiles;
}

function writeProjectFiles(string $tmpBase, array $safeFiles): void
{
    foreach ($safeFiles as $fileInfo) {
        $target = $tmpBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fileInfo['name']);
        $dir = dirname($target);
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        file_put_contents($target, $fileInfo['content']);
    }
}

function activeFile(array $safeFiles, string $fallbackName, string $fallbackCode, string $extension): array
{
    foreach ($safeFiles as $file) {
        if (!empty($file['active'])) return $file;
    }
    foreach ($safeFiles as $file) {
        if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === strtolower($extension)) return $file;
    }
    return ['name' => $fallbackName, 'content' => $fallbackCode, 'language' => '', 'active' => true];
}

function projectFilesByExtension(array $safeFiles, string $extension): array
{
    return array_values(array_filter($safeFiles, function ($file) use ($extension) {
        return strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === strtolower($extension);
    }));
}

function dotnetProject(string $tmpBase, string $language, string $code): array
{
    $dotnet = findExecutable(['dotnet', 'C:\\Program Files\\dotnet\\dotnet.exe']);
    if (!$dotnet) {
        return ['ok' => false, 'output' => '', 'error' => '.NET SDK is not installed on this server.'];
    }

    $template = $language === 'vbnet' ? 'console -lang VB' : 'console -lang C#';
    $create = runProcess('"' . $dotnet . '" new ' . $template . ' --force --no-restore', $tmpBase, '', 45);
    if (!$create['ok']) return $create;

    file_put_contents($tmpBase . DIRECTORY_SEPARATOR . ($language === 'vbnet' ? 'Program.vb' : 'Program.cs'), $code);
    $run = runProcess('"' . $dotnet . '" run --no-restore', $tmpBase, $GLOBALS['input'] ?? '', 2);
    $combinedRunMessage = $run['output'] . "\n" . $run['error'];
    if (!$run['ok'] && str_contains($combinedRunMessage, 'project.assets.json')) {
        $restore = runProcess('"' . $dotnet . '" restore', $tmpBase, '', 45);
        if (!$restore['ok']) return $restore;
        $run = runProcess('"' . $dotnet . '" run --no-restore', $tmpBase, $GLOBALS['input'] ?? '', 45);
    }
    return $run;
}

$code = (string)($_POST['code'] ?? '');
$language = strtolower(trim((string)($_POST['language'] ?? '')));
$input = (string)($_POST['input'] ?? '');
$postedFiles = [];
if (!empty($_POST['files'])) {
    $decodedFiles = json_decode((string)$_POST['files'], true);
    if (is_array($decodedFiles)) {
        $postedFiles = $decodedFiles;
    }
}

if (trim($code) === '') {
    respond(false, '', 'No code received.');
}

$languageAliases = [
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
];
$language = $languageAliases[$language] ?? $language;

$executionRoot = __DIR__ . '/../runtime/code-execution';
if (!is_dir($executionRoot)) {
    mkdir($executionRoot, 0700, true);
}
$tmpBase = $executionRoot . DIRECTORY_SEPARATOR . 'qoda_exec_' . bin2hex(random_bytes(6));
if (!mkdir($tmpBase, 0700, true) && !is_dir($tmpBase)) {
    respond(false, '', 'Unable to create execution directory.');
}

$started = microtime(true);
try {
    $safeFiles = normalizeProjectFiles($postedFiles);
    writeProjectFiles($tmpBase, $safeFiles);

    switch ($language) {
        case 'python':
            $exe = findExecutable(['python', 'python3', 'C:\\Python314\\python.exe']);
            if (!$exe) respond(false, '', 'Python is not installed on this server.');
            $entry = activeFile($safeFiles, 'main.py', $code, 'py');
            if (!$safeFiles) file_put_contents($tmpBase . DIRECTORY_SEPARATOR . $entry['name'], $entry['content']);
            $file = $tmpBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['name']);
            $result = runProcess('"' . $exe . '" "' . $file . '"', $tmpBase, $input);
            break;

        case 'javascript':
            $exe = findExecutable(['node', 'C:\\Program Files\\nodejs\\node.exe']);
            if (!$exe) respond(false, '', 'Node.js is not installed on this server.');
            $entry = activeFile($safeFiles, 'main.js', $code, 'js');
            if (!$safeFiles) file_put_contents($tmpBase . DIRECTORY_SEPARATOR . $entry['name'], $entry['content']);
            $file = $tmpBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['name']);
            $result = runProcess('"' . $exe . '" "' . $file . '"', $tmpBase, $input);
            break;

        case 'php':
            $exe = findExecutable(['php', 'C:\\xampp\\php\\php.exe', 'C:\\Program Files\\php-8.5.3\\php.exe']);
            if (!$exe) respond(false, '', 'PHP CLI is not installed on this server.');
            $entry = activeFile($safeFiles, 'main.php', $code, 'php');
            $file = $tmpBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['name']);
            $entryContent = str_contains($entry['content'], '<?php') ? $entry['content'] : "<?php\n" . $entry['content'];
            file_put_contents($file, $entryContent);
            $result = runProcess('"' . $exe . '" "' . $file . '"', $tmpBase, $input);
            break;

        case 'java':
            $javac = findExecutable(['javac', 'C:\\Program Files\\Java\\jdk-21\\bin\\javac.exe', 'C:\\Program Files\\Java\\jdk-25\\bin\\javac.exe']);
            $java = findExecutable(['java', 'C:\\Program Files\\Java\\jdk-21\\bin\\java.exe', 'C:\\Program Files\\Java\\jdk-25\\bin\\java.exe']);
            if (!$javac || !$java) respond(false, '', 'Java JDK is not installed on this server.');
            $className = publicClassName($code);
            if (!$safeFiles) {
                $safeFiles[] = ['name' => $className . '.java', 'content' => $code];
                writeProjectFiles($tmpBase, $safeFiles);
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
            $javaFiles = projectFilesByExtension($safeFiles, 'java');
            $javaArgs = implode(' ', array_map(function ($file) {
                return '"' . str_replace('/', DIRECTORY_SEPARATOR, $file['name']) . '"';
            }, $javaFiles));
            $compile = runProcess('"' . $javac . '" ' . $javaArgs, $tmpBase, '', 15);
            if (!$compile['ok']) {
                $result = $compile;
                break;
            }
            $result = runProcess('"' . $java . '" -cp "' . $tmpBase . '" ' . $className, $tmpBase, $input, 8);
            break;

        case 'c':
        case 'cpp':
            $compiler = $language === 'c'
                ? findExecutable(['gcc', __DIR__ . '/../tools/winlibs-gcc/mingw64/bin/gcc.exe'])
                : findExecutable(['g++', __DIR__ . '/../tools/winlibs-gcc/mingw64/bin/g++.exe']);
            if (!$compiler) respond(false, '', strtoupper($language) . ' compiler is not installed on this server.');
            $extension = $language === 'c' ? 'c' : 'cpp';
            $entry = activeFile($safeFiles, 'main.' . $extension, $code, $extension);
            if (!$safeFiles) file_put_contents($tmpBase . DIRECTORY_SEPARATOR . $entry['name'], $entry['content']);
            $source = $tmpBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['name']);
            $binary = $tmpBase . DIRECTORY_SEPARATOR . 'main.exe';
            $compile = runProcess('"' . $compiler . '" "' . $source . '" -o "' . $binary . '"', $tmpBase, '', 10);
            if (!$compile['ok']) {
                $result = $compile;
                break;
            }
            $result = runProcess('"' . $binary . '"', $tmpBase, $input, 8);
            break;

        case 'csharp':
        case 'vbnet':
            $result = dotnetProject($tmpBase, $language, $code);
            break;

        default:
            respond(false, '', "Language '{$language}' is not supported by the local executor yet.");
    }

    $time = round((microtime(true) - $started) * 1000);
    respond($result['ok'], $result['output'], $result['error'], ['execution_time_ms' => $time]);
} finally {
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpBase, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($tmpBase);
}
