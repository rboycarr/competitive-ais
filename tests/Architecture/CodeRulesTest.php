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
