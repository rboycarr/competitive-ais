<?php

namespace Modules\Platform\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Inertia\Middleware;

/**
 * Inertia for the "web" route group (docs/01: UI pages are Vue 3 + Inertia). Root view: resources/views/app.blade.php.
 * Shared props are the minimum every page needs; user and permission props arrive with login (P1-11).
 */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'requestId' => Context::get('request_id'),
        ];
    }
}
