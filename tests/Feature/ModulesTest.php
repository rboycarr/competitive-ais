<?php

use App\Providers\ModulesServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Modules\Platform\PlatformServiceProvider;

it('registers a provider for every module, Platform first', function () {
    $providers = ModulesServiceProvider::discover(base_path('modules'));

    expect($providers)->toHaveCount(count(modules()))
        ->and($providers[0])->toBe(PlatformServiceProvider::class);

    foreach ($providers as $provider) {
        expect(app()->getProvider($provider))->not->toBeNull();
    }
});

it('maps every module directory in composer.json autoload', function () {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    foreach (modules() as $module) {
        expect($composer['autoload']['psr-4']["Modules\\$module\\"] ?? null)->toBe("modules/$module/src/")
            ->and($composer['autoload-dev']['psr-4']["Modules\\$module\\Tests\\"] ?? null)->toBe("modules/$module/tests/");
    }
});

it('fails loudly for a module directory without a provider', function () {
    $dir = sys_get_temp_dir().'/cais-modules-'.bin2hex(random_bytes(4));
    mkdir("$dir/Ghost", recursive: true);

    try {
        expect(fn () => ModulesServiceProvider::discover($dir))->toThrow(RuntimeException::class, 'Module [Ghost] has no');
    } finally {
        rmdir("$dir/Ghost");
        rmdir($dir);
    }
});

it('registers module console commands', function () {
    expect(Artisan::all())->toHaveKey('db:apply-grants');
});
