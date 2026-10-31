<?php

/*
| The migrator connection (DDL, GRANT OPTION) may only be named by the Platform migration wiring
| (TASK-0003 #6). Application code must never resolve it; it does not exist in runtime containers anyway.
*/

it('names the migrator connection only in the Platform migration wiring', function () {
    $root = dirname(__DIR__, 2);
    $allowed = [
        'config/database.php',
        'modules/Platform/src/PlatformServiceProvider.php',
        'modules/Platform/src/Database/TableGrants.php',
        'modules/Platform/src/Console/ApplyDatabaseGrants.php',
    ];

    $offenders = [];
    foreach (['app', 'modules', 'routes', 'config', 'database', 'bootstrap'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if ($file->getExtension() !== 'php' || preg_match('#^(modules/[^/]+/tests/|bootstrap/cache/)#', $relative) || in_array($relative, $allowed, true)) {
                continue;
            }
            if (preg_match('/[\'"]migrator[\'"]/', (string) file_get_contents($file->getPathname()))) {
                $offenders[] = $relative;
            }
        }
    }

    expect($offenders)->toBe([]);
});
