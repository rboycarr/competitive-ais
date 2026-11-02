<?php

/*
|--------------------------------------------------------------------------
| Feature flags
|--------------------------------------------------------------------------
|
| Capabilities that stay off until the Product Owner switches them on (docs/01: collection and AI are off by
| default). Read through Modules\Platform\Support\Features, which accepts only true as "on". Set per environment;
| a change needs a configuration reload, so nobody can switch a flag from inside the app.
|
*/

return [

    // Phase 3 automated collection. While off, a source can only be collected manually (docs/04 §1, P1-30).
    'collection' => env('FEATURE_COLLECTION', false),

];
