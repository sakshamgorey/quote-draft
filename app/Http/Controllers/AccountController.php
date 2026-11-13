<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Customer accounts: the trusted source for who a quote is for and their pricing terms. */
class AccountController extends Controller
{
    public function index(Request $request): Response
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);

        $accounts = Account::search($q)
            ->query(fn ($query) => $query->withCount('drafts'))
            ->when($q === '', fn ($b) => $b->orderBy('name'))
            ->paginate(25)
            ->appends('query', null)
            ->withQueryString();

        return Inertia::render('accounts/index', [
            'q' => $q,
            'accounts' => [
                'data' => $accounts->getCollection()->map(fn (Account $a) => $a->summary() + ['drafts_count' => $a->drafts_count])->all(),
                'total' => $accounts->total(),
                'prev_url' => $accounts->previousPageUrl(),
                'next_url' => $accounts->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Picker on the draft pages. Scout (full text + whole-name trigram) first; then
     * partial words ("nor…") and per-word typos ("nortside"), which a picker needs and
     * the whole-name trigram can't do against multi-word names.
     */
    public function lookup(Request $request): JsonResponse
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);

        if ($q === '') {
            $accounts = Account::query()->orderBy('name')->limit(8)->get();
        } else {
            $accounts = Account::search($q)->take(8)->get();

            if ($accounts->count() < 8) {
                $accounts = $accounts->concat(Account::query()
                    ->whereNotIn('id', $accounts->pluck('id'))
                    ->where(fn ($w) => $w
                        ->where('name', 'ilike', '%'.addcslashes($q, '%_\\').'%')
                        ->orWhereRaw('word_similarity(?, name) > 0.4', [$q]))
                    ->orderByRaw('word_similarity(?, name) desc', [$q])
                    ->limit(8 - $accounts->count())
                    ->get());
            }
        }

        return response()->json(['data' => $accounts->map(fn (Account $a) => $a->summary())->values()]);
    }

    public function create(): Response
    {
        return Inertia::render('accounts/form', ['account' => null, 'defaultMaxDiscount' => (float) config('pricing.max_discount_pct')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = Account::create($this->validated($request));

        return redirect()->route('accounts.edit', $account)->with('status', 'Account created.');
    }

    public function edit(Account $account): Response
    {
        return Inertia::render('accounts/form', [
            'account' => $account->summary() + [
                'notes' => $account->notes,
                'own_max_discount_pct' => $account->max_discount_pct,
                'rules' => $account->priceRules()->orderBy('label')->get(['id', 'label', 'product_type', 'brand', 'min_quantity', 'discount_pct', 'active']),
            ],
            'defaultMaxDiscount' => (float) config('pricing.max_discount_pct'),
        ]);
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        $account->update($this->validated($request));

        return back()->with('status', 'Account saved.');
    }

    public function destroy(Account $account): RedirectResponse
    {
        // Drafts keep their frozen account snapshot; open drafts just lose the link.
        $account->delete();

        return redirect()->route('accounts.index');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contacts' => ['array', 'max:20'],
            'contacts.*.name' => ['required', 'string', 'max:120'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:40'],
            'ship_to' => ['nullable', 'string', 'max:1000'],
            'payment_terms' => ['nullable', 'string', 'max:64'],
            'tax_exempt' => ['boolean'],
            'discount_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            // The contract discount itself must be allowed.
            'max_discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100', 'gte:discount_pct'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $data['contacts'] = array_values($data['contacts'] ?? []);

        return $data;
    }
}
