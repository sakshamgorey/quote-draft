<?php

namespace App\Http\Controllers;

use App\Models\Draft;
use App\Models\DraftLine;
use App\Models\Product;
use App\Services\Drafts\DraftBuilder;
use App\Services\Drafts\OptionSelector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Salesperson edits on an open draft. None of these call the LLM: answers become
 * structured specs (quote = null, "entered by salesperson") and Postgres re-matches.
 */
class DraftLineController extends Controller
{
    /** Soft details: removing one widens the match. Size and fire rating have their own editors. */
    private const CLEARABLE_SPECS = ['model', 'brand', 'application', 'surface_type', 'material', 'flange_type', 'insulated', 'load_psf', 'load_lbs'];

    public function store(Request $request, Draft $draft, DraftBuilder $drafts): RedirectResponse
    {
        $this->ensureOpen($draft);

        $data = $request->validate([
            'product_id' => ['required', 'integer', $this->availableProduct()],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'from_suggestion' => ['sometimes', 'boolean'],
        ]);

        $drafts->addManualLine($draft, Product::findOrFail($data['product_id']), $data['quantity'] ?? 1, (bool) ($data['from_suggestion'] ?? false));

        return back();
    }

    public function update(Request $request, Draft $draft, DraftLine $line, DraftBuilder $drafts, OptionSelector $options): RedirectResponse
    {
        $this->ensureOpen($draft);

        $data = $request->validate([
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'product_id' => ['sometimes', 'nullable', 'integer', $this->availableProduct()],
            // One option choice for the line's product; null value clears an optional one.
            'option' => ['sometimes', 'array'],
            'option.store_option_id' => ['required_with:option', 'integer'],
            'option.store_value_id' => ['present_with:option', 'nullable', 'integer'],
            'product_type' => ['sometimes', 'nullable', Rule::in(config('catalog.product_types'))],
            'size' => ['sometimes', 'nullable', 'array'],
            'size.width' => ['required_with:size', 'numeric', 'gt:0', 'max:600'],
            'size.height' => ['required_with:size', 'numeric', 'gt:0', 'max:600'],
            'fire_rated' => ['sometimes', Rule::in(['yes', 'no', 'either'])],
            // Drop a detail from the notes that doesn't matter after all ("any brand is fine").
            'clear_spec' => ['sometimes', Rule::in(self::CLEARABLE_SPECS)],
        ]);

        $specs = $line->specs ?? [];
        $rematch = false;

        if (array_key_exists('size', $data)) {
            $specs = $this->setSpec($specs, 'size', $data['size'] ? [
                'width' => (float) $data['size']['width'],
                'height' => (float) $data['size']['height'],
            ] : null);
            $rematch = true;
        }

        if (array_key_exists('fire_rated', $data)) {
            $specs = $this->setSpec($specs, 'fire_rated', ['yes' => true, 'no' => false, 'either' => null][$data['fire_rated']]);
            $rematch = true;
        }

        if (isset($data['clear_spec'])) {
            $specs = $this->setSpec($specs, $data['clear_spec'], null);
            $rematch = true;
        }

        $changes = ['specs' => $specs];

        $typeChanged = array_key_exists('product_type', $data) && $data['product_type'] !== $line->product_type;
        if ($typeChanged) {
            $changes['product_type'] = $data['product_type'];
            $rematch = true;
        }

        if (array_key_exists('quantity', $data)) {
            $changes['quantity'] = $data['quantity'];
        }

        $line->update($changes);

        // Product changes go through setProduct so its options are chosen afresh.
        if ($typeChanged) {
            $drafts->setProduct($line, null, DraftLine::PICKED_SALESPERSON);
        }
        if (array_key_exists('product_id', $data)) {
            // Choosing the preselected product again marks it as the salesperson's.
            $drafts->setProduct($line, $data['product_id'], DraftLine::PICKED_SALESPERSON);
        }
        if ($rematch) {
            $drafts->autoPick($line->refresh());
        }
        if (isset($data['option'])) {
            $options->choose($line->refresh(), $data['option']['store_option_id'], $data['option']['store_value_id'] ?? null);
        }

        return back();
    }

    public function destroy(Draft $draft, DraftLine $line): RedirectResponse
    {
        $this->ensureOpen($draft);

        $line->delete();

        return back();
    }

    /** Answers typed by the salesperson carry no customer quote. */
    private function setSpec(array $specs, string $name, mixed $value): array
    {
        if ($value === null) {
            unset($specs[$name]);
        } else {
            $specs[$name] = ['value' => $value, 'quote' => null];
        }

        return $specs;
    }

    /** Disabled on the store (discontinued / hidden): can't be put on a quote. */
    private function availableProduct(): Exists
    {
        return Rule::exists('products', 'id')->where('is_available', true);
    }

    private function ensureOpen(Draft $draft): void
    {
        abort_if($draft->isFinalized(), 409, 'This draft is finalized.');
    }
}
