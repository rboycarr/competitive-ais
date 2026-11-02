<?php

namespace Modules\Registry\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Registry\Actions\CurrencyService;
use Modules\Registry\Models\Currency;
use Modules\Registry\Models\Market;

/** Admin › Currencies (P1-23b) for taxonomy.manage holders; CurrencyService checks again. Never deleted. */
class CurrencyController
{
    public function __construct(private readonly CurrencyService $currencies) {}

    public function index(): Response
    {
        $used = Market::query()->selectRaw('default_currency, COUNT(*) AS markets')->groupBy('default_currency')->pluck('markets', 'default_currency');

        return Inertia::render('Registry/Admin/Currencies', [
            'currencies' => Currency::query()->orderBy('code')->get()->map(fn (Currency $c): array => [
                'code' => $c->code, 'name' => $c->name, 'minorUnits' => $c->minor_units, 'markets' => (int) ($used[$c->code] ?? 0),
            ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'size:3']] + $this->rules());
        $currency = $this->currencies->create($request->user() ?? abort(401), (string) $validated['code'], (string) $validated['name'], (int) $validated['minor_units']);

        return back()->with('status', "Currency {$currency->code} added.");
    }

    public function update(Request $request, Currency $currency): RedirectResponse
    {
        $validated = $request->validate($this->rules());
        $this->currencies->update($request->user() ?? abort(401), $currency, (string) $validated['name'], (int) $validated['minor_units']);

        return back()->with('status', "Currency {$currency->code} saved.");
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'minor_units' => ['required', 'integer', 'between:0,4'],
        ];
    }
}
