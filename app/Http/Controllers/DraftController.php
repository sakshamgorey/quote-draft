<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Draft;
use App\Models\Product;
use App\Services\Drafts\AiSuggester;
use App\Services\Drafts\DraftBuilder;
use App\Services\Drafts\DraftPresenter;
use App\Services\Drafts\InvalidExtraction;
use App\Services\Zoho\DummyZohoSender;
use App\Services\Zoho\ZohoQuoteRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;

class DraftController extends Controller
{
    public const MAX_MESSAGE_LENGTH = 5000;

    /**
     * Seconds the create request may run. PHP's default 30 s counts wall-clock time on
     * Windows, and Gemini can queue a request for 20–50 s when it's busy. Must stay above
     * DraftBuilder::EXTRACTION_BUDGET (50 s, both AI attempts) so the AI times out first
     * with a readable error, and below nginx's default fastcgi_read_timeout (60 s) or the
     * browser gets a 504.
     */
    public const REQUEST_TIME_LIMIT = 55;

    public function index(): Response
    {
        $drafts = Draft::query()
            ->with('account:id,name')
            ->withCount(['lines', 'lines as ready_count' => fn ($q) => $q->whereNotNull('product_id')])
            ->latest('id')
            ->paginate(25);

        return Inertia::render('drafts/index', [
            'drafts' => [
                'data' => $drafts->getCollection()->map(fn (Draft $d) => $this->summary($d, 140) + [
                    'lines' => $d->lines_count,
                    'ready' => $d->ready_count,
                ])->all(),
                'prev_url' => $drafts->previousPageUrl(),
                'next_url' => $drafts->nextPageUrl(),
                'total' => $drafts->total(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('drafts/create', [
            'maxLength' => self::MAX_MESSAGE_LENGTH,
            'recent' => Draft::query()->with('account:id,name')->latest('id')->limit(5)->get()
                ->map(fn (Draft $d) => $this->summary($d, 90)),
        ]);
    }

    /** One LLM call on the redacted notes, then straight to the saved draft. */
    public function store(Request $request, DraftBuilder $drafts): RedirectResponse
    {
        // Checked before the AI call: no account, no call.
        $data = $request->validate([
            'message' => ['required', 'string', 'max:'.self::MAX_MESSAGE_LENGTH],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
        ], ['account_id.required' => 'Pick the customer account first.']);

        set_time_limit(self::REQUEST_TIME_LIMIT);

        try {
            $draft = $drafts->create($data['message'], Account::findOrFail($data['account_id']));
        } catch (ProviderOverloadedException|RateLimitedException) {
            return back()->withErrors(['message' => 'The AI service is busy right now. Please try again in a minute.']);
        } catch (ProviderConnectionException $e) {
            Log::warning('Draft extraction timed out or could not connect', ['error' => $e->getMessage()]);

            return back()->withErrors(['message' => 'The AI service took too long to answer. Please try again.']);
        } catch (AiException $e) {
            Log::warning('Draft extraction failed', ['error' => $e->getMessage()]);

            return back()->withErrors(['message' => "Couldn't reach the AI service. Please try again."]);
        } catch (InvalidExtraction $e) {
            Log::warning('Draft extraction returned an unexpected shape', ['error' => $e->getMessage()]);

            return back()->withErrors(['message' => "Couldn't read those notes. Try rephrasing them or splitting them up."]);
        }

        return redirect()->route('drafts.show', $draft);
    }

    public function show(Draft $draft, DraftPresenter $presenter): Response
    {
        return Inertia::render('drafts/show', [
            'draft' => $presenter->show($draft),
            'productTypes' => collect(Product::TYPE_LABELS)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    /**
     * The optional second AI call (DRAFT_AI_SUGGESTIONS=true): variants and add-ons
     * per line, from real catalog candidates. Only when the salesperson clicks
     * "Ask AI"; catalog suggestions never need it. A failure is saved on the draft and
     * shown, never thrown.
     */
    public function suggest(Draft $draft, AiSuggester $suggester): RedirectResponse
    {
        abort_unless(AiSuggester::enabled(), 404);
        abort_if($draft->isFinalized(), 409, 'This draft is finalized.');

        set_time_limit(self::REQUEST_TIME_LIMIT);
        $suggester->suggest($draft);

        return back();
    }

    /** Change the account or the quote discount. Prices re-compute on the next view. */
    public function update(Request $request, Draft $draft): RedirectResponse
    {
        abort_if($draft->isFinalized(), 409, 'This draft is finalized.');

        $draft->update($request->validate([
            'account_id' => ['sometimes', 'required', 'integer', 'exists:accounts,id'], // can change, can't be removed
            // Anything above the account maximum is allowed in but raises a blocking flag.
            'discount_pct' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
        ]));

        return back();
    }

    public function finalize(Draft $draft, DraftBuilder $drafts, DraftPresenter $presenter): RedirectResponse
    {
        $shown = $presenter->show($draft);

        if (! $shown['can_finalize']) {
            return back()->withErrors(['finalize' => $shown['totals']['blocking'] > 0
                ? 'Fix the blocking flags first.'
                : 'Pick a product on every line (or remove it) first.']);
        }

        $drafts->finalize($draft);

        return redirect()->route('drafts.show', $draft);
    }

    /**
     * Finalize (if still open) and hand the quote to Zoho. Dummy mode records the exact
     * request with a Zoho-shaped response and makes no network call. Sending twice
     * returns the first submission: a retry never creates a second Zoho quote.
     */
    public function sendToZoho(Draft $draft, DraftBuilder $drafts, DraftPresenter $presenter, ZohoQuoteRequest $zoho, DummyZohoSender $sender): RedirectResponse
    {
        if ($draft->zohoSubmission) {
            return back()->with('status', 'Already sent to Zoho.');
        }

        if (! $draft->isFinalized()) {
            $shown = $presenter->show($draft);

            if (! $shown['can_finalize']) {
                return back()->withErrors(['finalize' => $shown['totals']['blocking'] > 0
                    ? 'Fix the blocking flags first.'
                    : 'Pick a product on every line (or remove it) first.']);
            }

            $drafts->finalize($draft);
        }

        // Build from the frozen snapshot, exactly as shown after finalizing.
        $request = $presenter->show($draft->refresh())['zoho']['request'];
        $submission = $sender->send($draft, $request);

        return back()->with('status', "Sent to Zoho (dummy). Quote record {$submission->zoho_record_id}.");
    }

    private function summary(Draft $d, int $excerpt): array
    {
        return [
            'ulid' => $d->ulid,
            'status' => $d->status,
            'account' => $d->account?->name ?? ($d->account_snapshot['name'] ?? null),
            'excerpt' => Str::limit(trim(preg_replace('/\s+/', ' ', $d->message)), $excerpt),
            'created_at' => $d->created_at->toIso8601String(),
        ];
    }
}
