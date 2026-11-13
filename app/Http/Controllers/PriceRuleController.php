<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\PriceRule;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Quantity breaks and promotions. The best single discount per line wins (PriceCalculator). */
class PriceRuleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('pricing/index', [
            'rules' => PriceRule::query()->with('account:id,name')->orderByRaw('account_id is not null')->orderBy('label')->get()
                ->map(fn (PriceRule $r) => [
                    'id' => $r->id,
                    'label' => $r->label,
                    'account' => $r->account?->only(['id', 'name']),
                    'product_type' => $r->product_type,
                    'brand' => $r->brand,
                    'min_quantity' => $r->min_quantity,
                    'discount_pct' => $r->discount_pct,
                    'starts_on' => $r->starts_on?->toDateString(),
                    'ends_on' => $r->ends_on?->toDateString(),
                    'active' => $r->active,
                ]),
            'accounts' => Account::query()->orderBy('name')->get(['id', 'name']),
            'productTypes' => collect(Product::TYPE_LABELS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'brands' => Product::query()->whereNotNull('brand')->distinct()->orderBy('brand')->pluck('brand'),
            'maxDiscount' => (float) config('pricing.max_discount_pct'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        PriceRule::create($this->validated($request));

        return back()->with('status', 'Rule added.');
    }

    public function update(Request $request, PriceRule $rule): RedirectResponse
    {
        $rule->update($request->validate(['active' => ['required', 'boolean']]));

        return back();
    }

    public function destroy(PriceRule $rule): RedirectResponse
    {
        $rule->delete();

        return back();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'product_type' => ['nullable', Rule::in(config('catalog.product_types'))],
            'brand' => ['nullable', 'string', 'max:100'],
            'min_quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'discount_pct' => ['required', 'numeric', 'gt:0', 'max:100'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'active' => ['boolean'],
        ]);
    }
}
