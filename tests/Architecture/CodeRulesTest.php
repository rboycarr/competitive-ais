<?php

/*
| General code rules (docs/09 "Architecture" row). Rules that need classes which do not exist yet
| (controllers with Form Requests, routed models with HasPublicUlid) are added with those classes.
*/

arch('no debugging or output functions')->preset()->php();

arch('no insecure functions')->preset()->security();

arch('env() is only read in config files')
    ->expect('env')
    ->not->toBeUsed();

foreach (modules() as $module) {
    arch("$module service provider extends the module base provider")
        ->expect("Modules\\$module\\{$module}ServiceProvider")
        ->toExtend('Modules\Platform\Support\ModuleServiceProvider');
}

// Tag links carry no visibility of their own: CompetitorTagService is their only writer, and it checks AccessScope
// (TASK-0042 #2). A new reader or writer is added here only after review. This is a tripwire for ordinary use, not a
// proof: a disguised table name (built at run time) is not detected and is a code-review matter, as it would be for
// any table the app account can write (TASK-0043).
test('only reviewed files use the taggables table', function () {
    $allowed = [
        'modules/Registry/src/Actions/CompetitorTagService.php', // the only writer
        'modules/Registry/src/Http/Controllers/CompetitorController.php', // reads tags of visible competitors
        'modules/Registry/src/Models/Tag.php', // reads whether a tag is in use
    ];
    $root = dirname(__DIR__, 2);
    $users = [];
    foreach ([...glob("$root/modules/*/src", GLOB_ONLYDIR) ?: [], ...glob("$root/modules/*/routes", GLOB_ONLYDIR) ?: [], "$root/app", "$root/routes"] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'taggables')) {
                $users[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }
    }
    sort($users);

    expect($users)->toBe($allowed);
});

// Feature flags are read only through Modules\Platform\Support\Features, which counts only a stored true as on;
// Pennant's own active() accepts any value but false, and its facade would skip the unknown-flag check.
test('only Platform reads Pennant', function () {
    $allowed = [
        'modules/Platform/src/PlatformServiceProvider.php', // defines the defaults, forgets cached values per job
        'modules/Platform/src/Support/Features.php',
    ];
    $root = dirname(__DIR__, 2);
    $users = [];
    foreach ([...glob("$root/modules/*/src", GLOB_ONLYDIR) ?: [], ...glob("$root/modules/*/routes", GLOB_ONLYDIR) ?: [], "$root/app", "$root/routes"] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && preg_match('/Laravel\\\\Pennant|\bFeature::|@feature/', (string) file_get_contents($file->getPathname())) === 1) {
                $users[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }
    }
    sort($users);

    expect($users)->toBe($allowed);
});

// Outbox receipts are written only by the relay, so a duplicate receipt can only mean that a concurrent delivery of
// the same event and handler won (TASK-0067 #3): a handler that wrote one could make its own failure look handled. A
// tripwire for ordinary use: a table name built at run time is a code-review matter.
test('only the outbox relay names the receipts table', function () {
    $root = dirname(__DIR__, 2);
    $users = [];
    foreach ([...glob("$root/modules/*/src", GLOB_ONLYDIR) ?: [], ...glob("$root/modules/*/routes", GLOB_ONLYDIR) ?: [], "$root/app", "$root/routes"] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'outbox_receipts')) {
                $users[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }
    }

    expect($users)->toBe(['modules/Platform/src/Outbox/OutboxRelay.php']);
});
