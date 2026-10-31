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
