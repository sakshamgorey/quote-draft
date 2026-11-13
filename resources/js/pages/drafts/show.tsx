import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    CheckCircle2,
    CircleAlert,
    Copy,
    ExternalLink,
    FileText,
    HelpCircle,
    ImageOff,
    Info,
    OctagonX,
    TriangleAlert,
    Loader2,
    Lock,
    Send,
    Sparkles,
    Pencil,
    Plus,
    Search,
    Trash2,
    X,
} from 'lucide-react';
import { Fragment, useCallback, useEffect, useState, type ReactNode } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { AccountPicker } from '@/components/account-picker';
import { dateTime, money, pct } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Account, Draft, DraftFlag, DraftLine, DraftOption, FitCheck, FlagSeverity, LinePick, LineStatus, LookupProduct, OptionGroup, Suggestion } from '@/types';

interface Props {
    draft: Draft;
    productTypes: { value: string; label: string }[];
}

const OPTIONS_SHOWN = 5;

/** Soft details the salesperson can drop ("any brand is fine"); size and fire rating have editors. */
const CLEARABLE_SPECS = ['model', 'brand', 'application', 'surface_type', 'material', 'flange_type', 'insulated', 'load_psf', 'load_lbs'];

const STATUS: Record<LineStatus, { label: string; className: string }> = {
    ready: { label: 'Ready', className: 'text-success border-success/30 bg-success/10' },
    choose: { label: 'Choose a product', className: 'text-warning border-warning/30 bg-warning/10' },
    needs_details: { label: 'Needs details', className: 'text-warning border-warning/30 bg-warning/10' },
    needs_clarification: { label: 'Needs clarification', className: 'text-destructive border-destructive/30 bg-destructive/10' },
    no_match: { label: 'No match', className: 'text-destructive border-destructive/30 bg-destructive/10' },
};

/** Every edit is a small PATCH; the server re-matches in Postgres and returns the fresh draft. */
function patchLine(draft: Draft, line: DraftLine, data: Record<string, unknown>) {
    router.patch(`/drafts/${draft.ulid}/lines/${line.id}`, data as never, { preserveScroll: true, preserveState: true });
}

/**
 * The optional AI suggestions (DRAFT_AI_SUGGESTIONS): only when the salesperson asks, e.g.
 * when the catalog's suggestions don't look right. Catalog suggestions never
 * need it. Async so line edits don't cancel it.
 */
function useAiSuggestions(draft: Draft): { asking: boolean; ask: () => void } {
    const [asking, setAsking] = useState(false);

    const ask = useCallback(() => {
        setAsking(true);
        router.post(`/drafts/${draft.ulid}/suggestions`, {}, { async: true, preserveScroll: true, preserveState: true, onFinish: () => setAsking(false) });
    }, [draft.ulid]);

    return { asking, ask };
}

export default function ShowDraft({ draft, productTypes }: Props) {
    const finalized = draft.status === 'finalized';
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const ai = useAiSuggestions(draft);

    return (
        <AppLayout>
            <Head title={finalized ? 'Finalized draft' : 'Draft'} />

            <div className="mb-6">
                <Link href="/drafts" className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm">
                    <ArrowLeft className="size-4" /> All drafts
                </Link>
                <div className="mt-2 flex flex-wrap items-center gap-3">
                    <h1 className="text-2xl font-semibold tracking-tight">Quote draft</h1>
                    <Badge variant={finalized ? 'default' : 'outline'}>
                        {finalized ? (
                            <>
                                <Lock /> Finalized
                            </>
                        ) : (
                            'Draft'
                        )}
                    </Badge>
                </div>
                <p className="text-muted-foreground mt-1 text-sm">
                    Created {dateTime(draft.created_at)}
                    {draft.finalized_at && <> · finalized {dateTime(draft.finalized_at)}</>}
                    {draft.model && <> · read by {draft.model}</>}
                </p>
            </div>

            <div className="grid gap-6 lg:grid-cols-[minmax(0,8fr)_minmax(0,4fr)]">
                <section className="min-w-0 space-y-4">
                    <FlagsPanel draft={draft} />

                    {draft.lines.length === 0 && (
                        <Card className="items-center border-dashed px-6 py-10 text-center shadow-none">
                            <p className="font-medium">No products found in this request</p>
                            <p className="text-muted-foreground text-sm">Add products yourself below.</p>
                        </Card>
                    )}

                    {draft.lines.map((line, i) => (
                        <LineCard key={line.id} draft={draft} line={line} index={i} productTypes={productTypes} aiAsking={ai.asking} />
                    ))}

                    {!finalized && <AddProduct draft={draft} />}

                    <ZohoRequestCard draft={draft} />
                </section>

                <aside className="space-y-4 lg:sticky lg:top-20 lg:self-start">
                    <Summary draft={draft} error={errors.finalize} ai={ai} />
                    <AccountCard draft={draft} />
                    <OriginalMessage message={draft.message} redacted={draft.redacted_message} />
                </aside>
            </div>
        </AppLayout>
    );
}

function LineCard({
    draft,
    line,
    index,
    productTypes,
    aiAsking,
}: {
    draft: Draft;
    line: DraftLine;
    index: number;
    productTypes: Props['productTypes'];
    aiAsking: boolean;
}) {
    const finalized = draft.status === 'finalized';
    const [editing, setEditing] = useState(line.status !== 'ready');
    const status = STATUS[line.status];

    // Picking a product collapses the card; un-picking (spec change) re-opens it.
    useEffect(() => setEditing(line.status !== 'ready'), [line.status]);

    const showEditor = !finalized && editing;

    return (
        <Card className={cn('gap-0 py-0', line.status === 'ready' && !editing && 'border-success/30')}>
            <div className="p-4 sm:p-5">
                {/* Header */}
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex min-w-0 items-center gap-2.5">
                        <span className="bg-muted text-muted-foreground grid size-6 shrink-0 place-items-center rounded-full text-xs font-semibold">
                            {line.number ?? index + 1}
                        </span>
                        <h2 className="truncate text-sm font-semibold">{line.label}</h2>
                        {line.source === 'manual' && (
                            <Badge variant="secondary" className="font-normal">
                                Added by you
                            </Badge>
                        )}
                        {line.source === 'suggestion' && (
                            <Badge variant="outline" className="border-primary/30 bg-primary/10 text-primary gap-1 font-normal">
                                <span className="bg-primary size-1.5 rounded-full" /> From suggestion
                            </Badge>
                        )}
                        {!finalized && line.selected && line.suggestions.length > 0 && (
                            <button
                                type="button"
                                onClick={() => document.getElementById(`suggestions-${line.id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' })}
                                className="text-primary inline-flex shrink-0 items-center gap-1.5 text-xs font-medium hover:underline"
                                title="Show the suggestions for this line"
                            >
                                <span className="relative flex size-2">
                                    <span className="bg-primary/60 absolute inline-flex size-full animate-ping rounded-full" />
                                    <span className="bg-primary relative inline-flex size-2 rounded-full" />
                                </span>
                                {line.suggestions.length} suggestion{line.suggestions.length > 1 && 's'}
                            </button>
                        )}
                    </div>
                    <div className="flex items-center gap-2">
                        <Badge variant="outline" className={status.className}>
                            {status.label}
                        </Badge>
                        {!finalized && (
                            <>
                                <QuantityInput draft={draft} line={line} />
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="text-muted-foreground hover:text-destructive size-8"
                                    aria-label="Remove line"
                                    onClick={() => {
                                        if (confirm('Remove this line from the draft?')) {
                                            router.delete(`/drafts/${draft.ulid}/lines/${line.id}`, { preserveScroll: true });
                                        }
                                    }}
                                >
                                    <Trash2 />
                                </Button>
                            </>
                        )}
                        {finalized && <span className="text-muted-foreground text-sm tabular-nums">Qty {line.quantity}</span>}
                    </div>
                </div>

                {line.quote && (
                    <blockquote className="text-muted-foreground mt-3 border-l-2 pl-3 text-sm italic">“{line.quote}”</blockquote>
                )}

                <LineFlags draft={draft} flags={draft.flags.filter((f) => f.line_id === line.id)} />

                {(line.specs.length > 0 || line.features.length > 0) && (
                    <dl className="mt-3 flex flex-wrap gap-1.5">
                        {line.specs.map((s) => (
                            <div
                                key={s.key}
                                className="bg-muted flex overflow-hidden rounded-md text-xs"
                                title={s.quote ? `Customer wrote: “${s.quote}”` : 'Entered by you'}
                            >
                                <dt className="text-muted-foreground px-2 py-1">{s.name}</dt>
                                <dd className="bg-secondary flex items-center gap-1 px-2 py-1 font-medium">
                                    {s.value}
                                    {!s.quote && <Pencil className="text-muted-foreground size-2.5" />}
                                </dd>
                                {!finalized && CLEARABLE_SPECS.includes(s.key) && (
                                    <button
                                        type="button"
                                        aria-label={`Ignore ${s.name}`}
                                        title="Ignore this detail when matching"
                                        onClick={() => patchLine(draft, line, { clear_spec: s.key })}
                                        className="bg-secondary text-muted-foreground hover:text-destructive border-l px-1.5"
                                    >
                                        <X className="size-3" />
                                    </button>
                                )}
                            </div>
                        ))}
                        {line.features.map((f) => (
                            <div key={f.text} className="bg-muted flex overflow-hidden rounded-md text-xs" title={`Customer wrote: “${f.quote}” (ranks products, never rules one out)`}>
                                <dt className="text-muted-foreground px-2 py-1">Wants</dt>
                                <dd className="bg-secondary px-2 py-1 font-medium">{f.text}</dd>
                            </div>
                        ))}
                    </dl>
                )}

                {/* Chosen product, collapsed view */}
                {line.selected && (!editing || finalized || line.status === 'needs_clarification') && (
                    <div className={cn('mt-4 rounded-lg border p-3', line.status === 'needs_clarification' ? 'bg-destructive/5 border-destructive/20' : 'bg-success/5 border-success/20')}>
                        <ProductRow product={line.selected} quantity={line.quantity} />
                        <FitChecks checks={line.selected.checks ?? []} />
                        {line.pick && line.status !== 'needs_clarification' && <PickReason pick={line.pick} />}
                        <ProductDetails product={line.selected} className="ml-[3.75rem]" />
                        <LineOptions draft={draft} line={line} />
                        {!finalized && (
                            <div className="mt-2 flex justify-end">
                                <Button variant="ghost" size="sm" className="h-7 text-xs" onClick={() => setEditing(true)}>
                                    <Pencil /> Change product
                                    {line.options.length > 1 && <span className="text-muted-foreground font-normal">· {line.options.length - 1} other matches</span>}
                                </Button>
                            </div>
                        )}
                    </div>
                )}

                {!finalized && line.selected && line.suggestions.length > 0 && <Suggestions draft={draft} line={line} />}
                {!finalized && line.selected && aiAsking && (
                    <p className="text-muted-foreground mt-3 flex items-center gap-1.5 text-xs">
                        <Loader2 className="size-3.5 animate-spin" /> AI is looking for variants and add-ons…
                    </p>
                )}

                {showEditor && <LineEditor draft={draft} line={line} productTypes={productTypes} />}

                {showEditor && line.options.length > 0 && <Options draft={draft} line={line} onClose={line.selected ? () => setEditing(false) : undefined} />}

                {/* Hints: answer them above (opening, fire rating) or just pick a product. */}
                {!finalized && line.status !== 'ready' && line.questions.length > 0 && <Questions questions={line.questions} />}
            </div>
        </Card>
    );
}

/** Add-ons the catalog ties to the chosen product. Each becomes its own line, only when clicked. */
/**
 * Add-ons (each becomes its own line) and variants (replace the chosen product), from the
 * catalog's compatibility info and, when on, the AI. Nothing changes until clicked.
 */
function Suggestions({ draft, line }: { draft: Draft; line: DraftLine }) {
    const [busy, setBusy] = useState<number | null>(null);
    const hasAi = line.suggestions.some((s) => s.source === 'ai');

    const act = (s: Suggestion) => {
        if (s.id == null) return;
        setBusy(s.id);
        const done = { preserveScroll: true, onFinish: () => setBusy(null) };
        if (s.kind === 'variant') router.patch(`/drafts/${draft.ulid}/lines/${line.id}`, { product_id: s.id }, done);
        else router.post(`/drafts/${draft.ulid}/lines`, { product_id: s.id, quantity: line.quantity, from_suggestion: true }, done);
    };

    return (
        <div id={`suggestions-${line.id}`} className="mt-4 scroll-mt-24">
            <p className="flex items-center gap-1.5 text-xs font-medium">
                <span className="bg-primary size-1.5 shrink-0 rounded-full" />
                Suggested for this line{' '}
                <span className="text-muted-foreground font-normal">· from the catalog’s compatibility info{hasAi && ' and the AI (marked)'}</span>
            </p>
            <ul className="mt-1.5 divide-y rounded-lg border">
                {line.suggestions.map((s) => (
                    <li key={s.id ?? s.sku} className="flex items-center gap-3 px-3 py-2.5">
                        <div className="min-w-0 flex-1">
                            <ProductRow product={s} quantity={line.quantity} compact />
                            <p
                                className="text-muted-foreground mt-1 ml-[3.25rem] flex items-start gap-1.5 text-[11px]"
                                title={s.evidence ?? (s.quote ? `Customer wrote: “${s.quote}”` : undefined)}
                            >
                                {s.source === 'ai' && (
                                    <Badge variant="outline" className="border-primary/30 bg-primary/10 text-primary h-4 shrink-0 gap-0.5 px-1 text-[10px] font-normal">
                                        <Sparkles className="size-2.5" /> AI
                                    </Badge>
                                )}
                                {s.kind === 'variant' && <span className="text-foreground shrink-0 font-medium">Instead:</span>}
                                <span>{s.reason}</span>
                            </p>
                        </div>
                        <Button size="sm" variant="outline" className="h-8 shrink-0" disabled={busy !== null} onClick={() => act(s)}>
                            {busy === s.id ? <Loader2 className="animate-spin" /> : s.kind === 'variant' ? <Check /> : <Plus />}
                            {s.kind === 'variant' ? 'Use this' : 'Add'}
                        </Button>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function QuantityInput({ draft, line }: { draft: Draft; line: DraftLine }) {
    const [value, setValue] = useState(String(line.quantity));
    useEffect(() => setValue(String(line.quantity)), [line.quantity]);

    const commit = () => {
        const n = parseInt(value, 10);
        if (Number.isFinite(n) && n >= 1 && n !== line.quantity) patchLine(draft, line, { quantity: n });
        else setValue(String(line.quantity));
    };

    return (
        <label className="flex items-center gap-1.5 text-xs">
            <span className="text-muted-foreground">Qty</span>
            <Input
                type="number"
                min={1}
                value={value}
                onChange={(e) => setValue(e.target.value)}
                onBlur={commit}
                onKeyDown={(e) => e.key === 'Enter' && (e.target as HTMLInputElement).blur()}
                aria-label="Quantity"
                className="h-8 w-16 px-2 text-center tabular-nums"
            />
        </label>
    );
}

/** Answers become structured specs; the server re-matches without the LLM. */
function LineEditor({ draft, line, productTypes }: { draft: Draft; line: DraftLine; productTypes: Props['productTypes'] }) {
    const [w, setW] = useState(line.size ? String(line.size.width) : '');
    const [h, setH] = useState(line.size ? String(line.size.height) : '');
    useEffect(() => {
        setW(line.size ? String(line.size.width) : '');
        setH(line.size ? String(line.size.height) : '');
    }, [line.size]);

    const sizeChanged = w !== (line.size ? String(line.size.width) : '') || h !== (line.size ? String(line.size.height) : '');
    const sizeValid = Number(w) > 0 && Number(h) > 0;
    const fire = line.fire_rated === null ? 'either' : line.fire_rated ? 'yes' : 'no';

    return (
        <div className="bg-muted/40 mt-4 flex flex-wrap items-end gap-x-5 gap-y-3 rounded-lg p-3">
            <Field label="Product type">
                <select
                    value={line.product_type ?? ''}
                    onChange={(e) => patchLine(draft, line, { product_type: e.target.value || null })}
                    className="border-input bg-background focus-visible:ring-ring/50 h-8 rounded-md border px-2 text-sm outline-none focus-visible:ring-[3px]"
                >
                    <option value="">Unclear</option>
                    {productTypes.map((t) => (
                        <option key={t.value} value={t.value}>
                            {t.label}
                        </option>
                    ))}
                </select>
            </Field>

            <Field label="Opening (in)">
                <form
                    className="flex items-center gap-1.5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (sizeValid) patchLine(draft, line, { size: { width: Number(w), height: Number(h) } });
                    }}
                >
                    <SizeBox value={w} onChange={setW} label="Width" />
                    <span className="text-muted-foreground text-xs">×</span>
                    <SizeBox value={h} onChange={setH} label="Height" />
                    {sizeChanged && (
                        <Button type="submit" size="sm" className="h-8" disabled={!sizeValid}>
                            Apply
                        </Button>
                    )}
                </form>
            </Field>

            <Field label="Fire-rated">
                <div className="bg-background inline-flex rounded-md border p-0.5">
                    {(['yes', 'no', 'either'] as const).map((v) => (
                        <button
                            key={v}
                            type="button"
                            onClick={() => v !== fire && patchLine(draft, line, { fire_rated: v })}
                            className={cn(
                                'rounded px-2.5 py-1 text-xs font-medium capitalize transition-colors',
                                v === fire ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {v === 'either' ? 'Any' : v}
                        </button>
                    ))}
                </div>
            </Field>
        </div>
    );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-1">
            <span className="text-muted-foreground text-[11px] font-medium tracking-wide uppercase">{label}</span>
            {children}
        </div>
    );
}

function SizeBox({ value, onChange, label }: { value: string; onChange: (v: string) => void; label: string }) {
    return (
        <Input
            type="number"
            inputMode="decimal"
            min={0}
            step="0.25"
            value={value}
            onChange={(e) => onChange(e.target.value)}
            placeholder={label[0]}
            aria-label={`Opening ${label.toLowerCase()}`}
            className="bg-background h-8 w-16 px-2 text-center [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none"
        />
    );
}

/**
 * The chosen product's options: a picker per option (store's real choices only), the
 * extra price of each choice, and the line's price build-up. Finalized lines show the
 * frozen choices.
 */
function LineOptions({ draft, line }: { draft: Draft; line: DraftLine }) {
    const finalized = draft.status === 'finalized';
    const groups = line.option_groups;
    if (groups.length === 0) return null;

    const delta = (c: number | null) => (c == null ? 'price ?' : c === 0 ? '' : `+${money(c)}`);
    const choose = (g: OptionGroup, value: string) =>
        patchLine(draft, line, { option: { store_option_id: g.store_option_id, store_value_id: value === '' ? null : Number(value) } });

    return (
        <div className="mt-3 border-t pt-3">
            <p className="text-muted-foreground mb-2 text-[11px] font-medium tracking-wide uppercase">Options</p>
            <div className="grid gap-2 sm:grid-cols-2">
                {groups.map((g) =>
                    !finalized && !g.selected?.gone && g.choices.some((c) => c.image_url) ? (
                        <ChoiceTiles key={g.store_option_id ?? g.label} group={g} onChoose={(value) => choose(g, value)} />
                    ) : (
                    <label key={g.store_option_id ?? g.label} className="block min-w-0">
                        <span className="flex items-center gap-1.5 text-xs font-medium">
                            {g.label}
                            {g.required && <span className="text-destructive">*</span>}
                            {g.selected?.source === 'notes' && (
                                <Badge variant="outline" className="border-primary/30 bg-primary/10 text-primary h-4 px-1 text-[10px] font-normal" title={g.selected.quote ?? undefined}>
                                    from notes
                                </Badge>
                            )}
                        </span>
                        {finalized ? (
                            <p className="mt-1 text-sm">
                                {g.selected?.name ?? '—'} <span className="text-muted-foreground">{delta(g.selected?.price_delta_cents ?? 0)}</span>
                            </p>
                        ) : (
                            <select
                                value={g.selected?.store_value_id ?? ''}
                                onChange={(e) => choose(g, e.target.value)}
                                aria-invalid={(g.required && !g.selected) || g.selected?.gone}
                                className="border-input bg-background focus-visible:ring-ring/50 aria-invalid:border-destructive mt-1 h-8 w-full rounded-md border px-2 text-sm outline-none focus-visible:ring-[3px]"
                            >
                                {(!g.selected || !g.required) && <option value="">{g.required ? 'Choose…' : 'None'}</option>}
                                {g.selected?.gone && <option value={g.selected.store_value_id ?? ''}>{g.selected.name} (no longer offered)</option>}
                                {g.choices
                                    .filter((c) => !(c.is_none && !g.required))
                                    .map((c) => (
                                        <option key={c.store_value_id ?? c.name} value={c.store_value_id ?? ''}>
                                            {c.name} {delta(c.price_delta_cents)}
                                            {c.lead_time_text ? ` · ${c.lead_time_text}` : ''}
                                            {c.needs_review ? ' · check price' : ''}
                                            {c.same_as.length > 0 ? ` · same as line ${c.same_as.join(', ')}` : ''}
                                        </option>
                                    ))}
                            </select>
                        )}
                    </label>
                    ),
                )}
            </div>
            {line.base_price_cents != null && line.options_price_cents > 0 && (
                <p className="text-muted-foreground mt-2 text-xs tabular-nums">
                    {money(line.base_price_cents)} + {money(line.options_price_cents)} options = <span className="text-foreground font-medium">{money(line.unit_price_cents)}</span> each
                    {line.quantity > 1 && (
                        <>
                            {' '}
                            × {line.quantity} = <span className="text-foreground font-medium">{money(line.line_total_cents)}</span>
                        </>
                    )}
                    {line.options_lead_time && ` · options ${line.options_lead_time}`}
                </p>
            )}
        </div>
    );
}

/** An option whose choices have pictures (curb face, color): pick by picture instead of a dropdown. */
function ChoiceTiles({ group, onChoose }: { group: OptionGroup; onChoose: (value: string) => void }) {
    const delta = (c: number | null) => (c == null ? 'price ?' : c === 0 ? '' : `+${money(c)}`);
    const choices = group.choices.filter((c) => !(c.is_none && !group.required));
    const tile = (active: boolean) =>
        cn(
            'flex w-[5.5rem] flex-col items-center gap-1 rounded-md border p-1.5 text-center text-[11px] leading-tight transition-colors',
            active ? 'border-primary bg-primary/5 ring-primary/30 ring-2' : 'hover:border-foreground/30',
        );

    return (
        <div className="min-w-0 sm:col-span-2">
            <span className="flex items-center gap-1.5 text-xs font-medium">
                {group.label}
                {group.required && <span className="text-destructive">*</span>}
                {group.selected?.source === 'notes' && (
                    <Badge variant="outline" className="border-primary/30 bg-primary/10 text-primary h-4 px-1 text-[10px] font-normal" title={group.selected.quote ?? undefined}>
                        from notes
                    </Badge>
                )}
            </span>
            <div role="radiogroup" aria-label={group.label} className="mt-1 flex flex-wrap gap-1.5">
                {!group.required && (
                    <button type="button" role="radio" aria-checked={!group.selected} onClick={() => group.selected && onChoose('')} className={tile(!group.selected)}>
                        <span className="bg-muted text-muted-foreground grid size-14 place-items-center rounded text-[10px]">None</span>
                        <span>None</span>
                    </button>
                )}
                {choices.map((c) => {
                    const active = c.store_value_id === group.selected?.store_value_id;
                    return (
                        <button
                            key={c.store_value_id ?? c.name}
                            type="button"
                            role="radio"
                            aria-checked={active}
                            title={[c.name, delta(c.price_delta_cents), c.lead_time_text, c.needs_review ? 'check price' : null].filter(Boolean).join(' · ')}
                            onClick={() => !active && onChoose(String(c.store_value_id ?? ''))}
                            className={tile(active)}
                        >
                            {c.image_url ? (
                                <img src={c.image_url} alt="" loading="lazy" className="size-14 rounded border bg-white object-contain" />
                            ) : (
                                <span className="bg-muted grid size-14 place-items-center rounded">
                                    <ImageOff className="text-muted-foreground size-4" />
                                </span>
                            )}
                            <span className="line-clamp-2">{c.name}</span>
                            {c.same_as.length > 0 && (
                                <span className="bg-primary/10 text-primary rounded px-1 text-[10px] font-medium">Same as line {c.same_as.join(', ')}</span>
                            )}
                            {(c.price_delta_cents !== 0 || c.lead_time_text) && (
                                <span className="text-muted-foreground tabular-nums">
                                    {delta(c.price_delta_cents)}
                                    {c.lead_time_text ? ` · ${c.lead_time_text}` : ''}
                                </span>
                            )}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

/**
 * Best matches first; products that contradict a stated detail stay available, collapsed.
 * Clicking a product only marks it: "Use this product" saves it (and closes the list),
 * "Cancel" leaves the line as it was.
 */
function Options({ draft, line, onClose }: { draft: Draft; line: DraftLine; onClose?: () => void }) {
    const best = line.options.filter((p) => p.group !== 'also');
    const also = line.options.filter((p) => p.group === 'also');
    const [openAlso, setOpenAlso] = useState(best.length === 0);
    const [pending, setPending] = useState<number | null>(line.selected?.id ?? null);
    const [saving, setSaving] = useState(false);
    useEffect(() => setPending(line.selected?.id ?? null), [line.selected?.id]);

    const marked = line.options.find((p) => p.id === pending) ?? null;
    const changed = pending !== null && pending !== line.selected?.id;
    const confirm = () =>
        router.patch(`/drafts/${draft.ulid}/lines/${line.id}`, { product_id: pending }, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onSuccess: () => onClose?.(),
        });

    return (
        <div className="mt-4 space-y-3">
            {best.length > 0 && (
                <OptionGroup
                    line={line}
                    products={best}
                    pending={pending}
                    onMark={setPending}
                    title={
                        line.shortlist_count && line.shortlist_count > 1
                            ? `Choose a product · ${line.shortlist_count} fit the notes equally`
                            : `Best ${best.length === 1 ? 'match' : `matches (${best.length})`}`
                    }
                    hint={
                        line.shortlist_count && line.shortlist_count > 1
                            ? 'The notes don’t settle it, so none is preselected. Likeliest first.'
                            : 'Nothing in the notes contradicts these. Confirmed details rank first.'
                    }
                />
            )}
            {also.length > 0 &&
                (openAlso ? (
                    <OptionGroup
                        line={line}
                        products={also}
                        pending={pending}
                        onMark={setPending}
                        title={`Other products to review (${also.length})`}
                        hint="These differ from a detail in the notes (marked ✗)."
                    />
                ) : (
                    <button type="button" onClick={() => setOpenAlso(true)} className="text-primary text-xs font-medium hover:underline">
                        Show {also.length} more products that differ from the notes
                    </button>
                ))}

            {(changed || onClose) && (
                <div className="bg-background/95 sticky bottom-0 -mx-1 flex flex-wrap items-center justify-end gap-2 rounded-lg border px-3 py-2 shadow-sm backdrop-blur">
                    <p className="text-muted-foreground mr-auto min-w-0 truncate text-xs">
                        {changed && marked ? (
                            <>
                                Switch to <span className="text-foreground font-mono">{marked.sku}</span>
                                {line.selected && (
                                    <>
                                        {' '}
                                        instead of <span className="font-mono">{line.selected.sku}</span>
                                    </>
                                )}
                            </>
                        ) : (
                            'Pick a product above, then confirm.'
                        )}
                    </p>
                    {onClose && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-8"
                            onClick={() => {
                                setPending(line.selected?.id ?? null);
                                onClose();
                            }}
                        >
                            Cancel
                        </Button>
                    )}
                    <Button size="sm" className="h-8" disabled={!changed || saving} onClick={confirm}>
                        {saving ? <Loader2 className="animate-spin" /> : <Check />} Use this product
                    </Button>
                </div>
            )}
        </div>
    );
}

function OptionGroup({
    line,
    products,
    pending,
    onMark,
    title,
    hint,
}: {
    line: DraftLine;
    products: DraftOption[];
    pending: number | null;
    onMark: (id: number) => void;
    title: string;
    hint: string;
}) {
    const [all, setAll] = useState(false);
    const shown = all ? products : products.slice(0, OPTIONS_SHOWN);

    return (
        <div>
            <p className="text-xs font-medium">
                {title} <span className="text-muted-foreground font-normal">· {hint}</span>
            </p>
            <ul className="mt-1.5 divide-y rounded-lg border">
                {shown.map((p) => {
                    const checked = p.id === pending;
                    const current = p.id === line.selected?.id;
                    return (
                        <li key={p.id}>
                            <label className={cn('hover:bg-muted/50 flex cursor-pointer items-start gap-3 px-3 py-2.5', checked && 'bg-primary/5')}>
                                <input
                                    type="radio"
                                    name={`line-${line.id}`}
                                    checked={checked}
                                    onChange={() => p.id !== null && onMark(p.id)}
                                    className="accent-primary mt-3 size-4 shrink-0"
                                />
                                <div className="min-w-0 flex-1">
                                    {current && (
                                        <Badge variant="outline" className="text-success border-success/30 bg-success/10 mb-1 h-4 px-1 text-[10px] font-normal">
                                            On the quote now
                                        </Badge>
                                    )}
                                    <ProductRow product={p} quantity={line.quantity} compact />
                                    {p.tags && p.tags.length > 0 && (
                                        <div className="mt-1.5 ml-[3.25rem] flex flex-wrap gap-1">
                                            {p.tags.map((t) => (
                                                <Badge key={t} variant="secondary" className="h-5 px-1.5 text-[11px] font-normal">
                                                    {t}
                                                </Badge>
                                            ))}
                                        </div>
                                    )}
                                    <FitChecks checks={p.checks ?? []} />
                                    <ProductDetails product={p} />
                                </div>
                            </label>
                        </li>
                    );
                })}
            </ul>
            {products.length > OPTIONS_SHOWN && (
                <button type="button" onClick={() => setAll((v) => !v)} className="text-primary mt-1.5 text-xs font-medium hover:underline">
                    {all ? 'Show fewer' : `Show ${products.length - OPTIONS_SHOWN} more`}
                </button>
            )}
        </div>
    );
}

const VERDICT = {
    yes: { mark: '✓', className: 'text-success border-success/30 bg-success/10', title: 'Confirmed by the catalog' },
    option: { mark: '+', className: 'text-primary border-primary/30 bg-primary/10', title: 'Available as an option' },
    unknown: { mark: '?', className: 'text-muted-foreground border-border bg-muted/50', title: 'The catalog doesn’t say' },
    no: { mark: '✗', className: 'text-destructive border-destructive/30 bg-destructive/10', title: 'The catalog says otherwise' },
} as const;

/** One chip per stated detail: ✓ confirmed, + via an option, ? unknown, ✗ differs. */
function FitChecks({ checks }: { checks: FitCheck[] }) {
    if (checks.length === 0) return null;

    return (
        <div className="mt-1.5 ml-[3.25rem] flex flex-wrap gap-1">
            {checks.map((c) => {
                const v = VERDICT[c.verdict];
                return (
                    <span
                        key={`${c.spec}-${c.value}`}
                        title={`${v.title}${c.evidence ? `: ${c.evidence}` : ''}`}
                        className={cn('inline-flex max-w-full items-center gap-1 rounded border px-1.5 py-0.5 text-[11px]', v.className)}
                    >
                        <span className="font-semibold">{v.mark}</span>
                        <span className="truncate">
                            {c.label} {c.value}
                            {c.verdict === 'option' && c.evidence ? ` · ${c.evidence}` : ''}
                        </span>
                    </span>
                );
            })}
        </div>
    );
}

function ProductRow({ product, quantity, compact = false }: { product: DraftOption; quantity: number; compact?: boolean }) {
    const [broken, setBroken] = useState(false);

    return (
        <div className="flex min-w-0 flex-1 items-center gap-3">
            {product.image_url && !broken ? (
                <img
                    src={product.image_url}
                    alt=""
                    loading="lazy"
                    onError={() => setBroken(true)}
                    className={cn('shrink-0 rounded-md border bg-white object-contain', compact ? 'size-10' : 'size-12')}
                />
            ) : (
                <div className={cn('bg-muted grid shrink-0 place-items-center rounded-md', compact ? 'size-10' : 'size-12')}>
                    <ImageOff className="text-muted-foreground size-4" />
                </div>
            )}
            <div className="min-w-0 flex-1">
                <a
                    href={product.url}
                    target="_blank"
                    rel="noopener"
                    onClick={(e) => e.stopPropagation()}
                    className="hover:text-primary group flex items-center gap-1 text-sm font-medium"
                >
                    <span className="line-clamp-1">{product.name}</span>
                    <ExternalLink className="size-3 shrink-0 opacity-0 transition-opacity group-hover:opacity-100" />
                </a>
                <p className="text-muted-foreground mt-0.5 flex flex-wrap gap-x-3 text-xs">
                    <span className="font-mono">{product.sku}</span>
                    {product.size && <span>Opening {product.size}</span>}
                </p>
            </div>
            <div className="shrink-0 text-right">
                <p className={cn('text-sm font-semibold tabular-nums', product.price_cents == null && 'text-muted-foreground font-normal')}>
                    {product.discount_pct ? (
                        <span className="text-muted-foreground mr-1.5 text-xs font-normal line-through">{money(product.list_price_cents)}</span>
                    ) : null}
                    {money(product.price_cents)}
                </p>
                {product.discount_pct ? (
                    <p className="text-success text-[11px]" title={product.discount_label ?? undefined}>
                        −{pct(product.discount_pct)} · {product.discount_label}
                    </p>
                ) : null}
                {product.price_cents != null && quantity > 1 && (
                    <p className="text-muted-foreground text-xs tabular-nums">
                        × {quantity} = {money(product.price_cents * quantity)}
                    </p>
                )}
            </div>
        </div>
    );
}

/** From the product page: key specs, the spec sheet and, for the chosen product, the description. */
function ProductDetails({ product, className = 'ml-[3.25rem]' }: { product: DraftOption; className?: string }) {
    const [open, setOpen] = useState(false);
    const specs = product.spec_lines ?? [];
    const hasMore = specs.length > 0 || !!product.description;
    if (!hasMore && !product.spec_sheet_url) return null;

    return (
        <div className={cn('mt-1.5 text-xs', className)}>
            <div className="flex items-center gap-3">
                {hasMore && (
                    <button
                        type="button"
                        onClick={(e) => {
                            e.preventDefault(); // inside the option's <label>: don't pick the product
                            setOpen((v) => !v);
                        }}
                        className="text-primary font-medium hover:underline"
                    >
                        {open ? 'Hide details' : 'Details'}
                    </button>
                )}
                {product.spec_sheet_url && (
                    <a
                        href={product.spec_sheet_url}
                        target="_blank"
                        rel="noopener"
                        onClick={(e) => e.stopPropagation()}
                        className="text-muted-foreground hover:text-primary inline-flex items-center gap-1"
                    >
                        <FileText className="size-3" /> Spec sheet
                    </a>
                )}
            </div>
            {open && (
                <div className="mt-1.5 space-y-2">
                    {specs.length > 0 && (
                        <dl className="grid grid-cols-[max-content_1fr] gap-x-3 gap-y-0.5">
                            {specs.map((s, i) => (
                                <Fragment key={i}>
                                    <dt className="text-muted-foreground">{s.label}</dt>
                                    <dd>{s.value}</dd>
                                </Fragment>
                            ))}
                        </dl>
                    )}
                    {product.description && <p className="text-muted-foreground max-h-48 overflow-y-auto whitespace-pre-line">{product.description}</p>}
                </div>
            )}
        </div>
    );
}

function Questions({ questions }: { questions: string[] }) {
    if (questions.length === 0) return null;

    return (
        <div className="bg-warning/10 mt-4 rounded-lg px-3.5 py-3">
            <p className="text-warning flex items-center gap-1.5 text-xs font-semibold tracking-wide uppercase">
                <HelpCircle className="size-3.5" /> To narrow it down
            </p>
            <ol className="mt-1.5 list-decimal space-y-1 pl-5 text-sm">
                {questions.map((q) => (
                    <li key={q}>{q}</li>
                ))}
            </ol>
            <p className="text-muted-foreground mt-2 text-xs">Answer above (opening, fire rating), or pick the product you know is right.</p>
        </div>
    );
}

function AddProduct({ draft }: { draft: Draft }) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const [results, setResults] = useState<LookupProduct[]>([]);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (!q.trim()) {
            setResults([]);
            return;
        }
        const controller = new AbortController();
        const id = setTimeout(async () => {
            setLoading(true);
            try {
                const res = await fetch(`/products/lookup?q=${encodeURIComponent(q.trim())}`, {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                setResults((await res.json()).data ?? []);
            } catch {
                /* aborted or offline: keep previous results */
            } finally {
                setLoading(false);
            }
        }, 250);
        return () => {
            clearTimeout(id);
            controller.abort();
        };
    }, [q]);

    if (!open) {
        return (
            <Button variant="outline" className="w-full border-dashed" onClick={() => setOpen(true)}>
                <Plus /> Add a product
            </Button>
        );
    }

    return (
        <Card className="gap-0 p-4">
            <div className="relative">
                <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input
                    autoFocus
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    placeholder="Search by name or SKU, e.g. “BA-PFI 24” or “floor hatch”"
                    className="pl-9"
                />
                {loading && <Loader2 className="text-muted-foreground absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin" />}
            </div>
            {results.length > 0 && (
                <ul className="mt-3 divide-y rounded-lg border">
                    {results.map((p) => (
                        <li key={p.id} className="flex items-center gap-3 px-3 py-2">
                            <div className="min-w-0 flex-1">
                                <p className="line-clamp-1 text-sm font-medium">{p.name}</p>
                                <p className="text-muted-foreground text-xs">
                                    <span className="font-mono">{p.sku}</span> · {money(p.price_cents)}
                                </p>
                            </div>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    router.post(`/drafts/${draft.ulid}/lines`, { product_id: p.id, quantity: 1 }, {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            setQ('');
                                            setOpen(false);
                                        },
                                    })
                                }
                            >
                                <Plus /> Add
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
            <div className="mt-3 flex justify-end">
                <Button variant="ghost" size="sm" onClick={() => setOpen(false)}>
                    Cancel
                </Button>
            </div>
        </Card>
    );
}

function Summary({ draft, error, ai }: { draft: Draft; error?: string; ai: { asking: boolean; ask: () => void } }) {
    const finalized = draft.status === 'finalized';
    const t = draft.totals;
    const [busy, setBusy] = useState(false);

    return (
        <Card className="gap-0 p-5">
            <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">Subtotal</p>
            <p className="mt-1 text-3xl font-semibold tracking-tight tabular-nums">{money(t.subtotal_cents)}</p>
            {t.savings_cents > 0 && <p className="text-success text-xs font-medium">Includes {money(t.savings_cents)} in discounts</p>}
            <p className="text-muted-foreground mt-1 text-xs">
                {t.unpriced > 0 && <>+ {t.unpriced} priced on request · </>}
                {draft.prices_as_of ? `Prices as of ${draft.prices_as_of}` : 'No prices loaded'}.{' '}
                {draft.account?.tax_exempt ? 'Tax exempt. Excludes shipping.' : 'Excludes tax and shipping.'}
            </p>

            {!finalized && <QuoteDiscount draft={draft} />}

            <div className="mt-4 space-y-1.5 border-t pt-4 text-sm">
                <Row label="Lines with a product" value={`${t.ready} of ${t.lines}`} />
                {!finalized && <Row label="Still to resolve" value={String(t.unresolved)} warn={t.unresolved > 0} />}
                {!finalized && t.blocking > 0 && <Row label="Blocking flags" value={String(t.blocking)} warn />}
            </div>

            {!finalized && draft.ai_suggestions.enabled && <AiSuggestionsRow draft={draft} {...ai} />}

            {draft.zoho.record_id ? (
                <p className="bg-success/10 text-success mt-4 flex items-center gap-2 rounded-lg px-3 py-2 text-xs font-medium">
                    <CheckCircle2 className="size-4 shrink-0" />
                    Sent to Zoho{draft.zoho.mode === 'dummy' && ' (dummy)'} · {draft.zoho.record_id}
                </p>
            ) : (
                <>
                    <Button
                        className="mt-4 w-full"
                        disabled={!draft.can_send || busy}
                        onClick={() =>
                            router.post(`/drafts/${draft.ulid}/zoho`, {}, {
                                preserveScroll: true,
                                onStart: () => setBusy(true),
                                onFinish: () => setBusy(false),
                            })
                        }
                    >
                        {busy ? <Loader2 className="animate-spin" /> : <Send />}
                        <span>
                            {finalized ? 'Send to Zoho' : 'Finalize & send to Zoho'}
                            {draft.zoho.mode === 'dummy' && <span className="font-normal opacity-75"> (dummy)</span>}
                        </span>
                    </Button>
                    {!draft.can_send && (
                        <p className="text-muted-foreground mt-2 text-center text-xs">
                            {t.lines === 0
                                ? 'Add at least one product.'
                                : t.blocking > 0
                                  ? 'Resolve the blocking flags before sending.'
                                  : t.unresolved > 0
                                  ? 'Pick a product on every line, or remove the line.'
                                  : 'Fix the blocking flags first.'}
                        </p>
                    )}
                    {error && (
                        <p className="text-destructive mt-2 flex items-center gap-1.5 text-xs">
                            <CircleAlert className="size-3.5" /> {error}
                        </p>
                    )}
                </>
            )}
        </Card>
    );
}

/**
 * The AI is a second opinion the salesperson asks for: variants and add-ons. It never
 * chooses a line's product. Catalog suggestions are already on the lines without it.
 */
function AiSuggestionsRow({ draft, asking, ask }: { draft: Draft; asking: boolean; ask: () => void }) {
    const s = draft.ai_suggestions;
    const ready = draft.lines.some((l) => l.selected);

    return (
        <div className="mt-3 space-y-1.5 text-xs">
            {(asking || s.status !== null) && (
                <p className={cn('flex items-start gap-1.5', s.status === 'failed' && !asking ? 'text-destructive' : 'text-muted-foreground')}>
                    {asking ? <Loader2 className="mt-px size-3.5 shrink-0 animate-spin" /> : <Sparkles className="mt-px size-3.5 shrink-0" />}
                    <span>
                        {asking
                            ? 'AI is looking for variants and add-ons…'
                            : s.status === 'failed'
                              ? `AI suggestions: ${s.error}`
                              : `AI suggestions added${s.at ? ` ${dateTime(s.at)}` : ''}${s.model ? ` · ${s.model}` : ''}`}
                    </span>
                </p>
            )}
            {!asking && (
                <Button variant="outline" size="sm" className="h-8 w-full text-xs" disabled={!ready} onClick={ask}>
                    <Sparkles /> {s.status === null ? 'Ask AI for suggestions' : 'Ask AI again'}
                </Button>
            )}
            {!asking && s.status === null && (
                <p className="text-muted-foreground text-center text-[11px]">Optional: catalog suggestions are already shown on each line.</p>
            )}
        </div>
    );
}

function Row({ label, value, warn = false }: { label: string; value: string; warn?: boolean }) {
    return (
        <div className="flex items-center justify-between">
            <span className="text-muted-foreground">{label}</span>
            <span className={cn('font-medium tabular-nums', warn && 'text-warning')}>{value}</span>
        </div>
    );
}

/** The exact request for Zoho CRM: method, URL, headers (token masked) and JSON body. */
function ZohoRequestCard({ draft }: { draft: Draft }) {
    const [copied, setCopied] = useState(false);
    const z = draft.zoho;
    const body = JSON.stringify(z.request.body, null, 2);
    const sent = z.record_id !== null;

    return (
        <Card className="gap-0 py-0">
            <div className="flex flex-wrap items-center justify-between gap-2 px-4 pt-3 pb-2">
                <div className="flex items-center gap-2">
                    <p className="text-sm font-medium">{sent ? 'Request sent to Zoho' : 'Request for Zoho'}</p>
                    {z.mode === 'dummy' && (
                        <Badge variant="outline" className="border-warning/30 bg-warning/10 text-warning font-normal">
                            Dummy: no network call
                        </Badge>
                    )}
                    {!sent && draft.status !== 'finalized' && (
                        <Badge variant="secondary" className="font-normal">
                            Live preview
                        </Badge>
                    )}
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    className="h-7 text-xs"
                    onClick={async () => {
                        await navigator.clipboard.writeText(body);
                        setCopied(true);
                        setTimeout(() => setCopied(false), 1500);
                    }}
                >
                    {copied ? <Check /> : <Copy />} {copied ? 'Copied' : 'Copy JSON'}
                </Button>
            </div>

            <div className="border-t px-4 py-3 font-mono text-xs leading-relaxed">
                <p>
                    <span className="text-primary font-semibold">{z.request.method}</span> {z.request.url}
                </p>
                {Object.entries(z.request.headers).map(([k, v]) => (
                    <p key={k} className="text-muted-foreground">
                        {k}: {v}
                    </p>
                ))}
            </div>
            <pre className="bg-muted/40 max-h-[28rem] overflow-auto border-t px-4 py-3 font-mono text-xs leading-relaxed">{body}</pre>

            {sent && z.response && (
                <>
                    <p className="text-muted-foreground border-t px-4 pt-3 text-xs font-medium">
                        Response{z.sent_at && ` · ${dateTime(z.sent_at)}`}
                    </p>
                    <pre className="max-h-60 overflow-auto px-4 pt-1 pb-3 font-mono text-xs leading-relaxed">{JSON.stringify(z.response, null, 2)}</pre>
                </>
            )}

            <div className="text-muted-foreground space-y-1 border-t px-4 py-2.5 text-[11px]">
                <p>
                    <code>ZOHO_ID_FOR_…</code> values are placeholders: a live sender looks up the Zoho account by name and each product by Product_Code (our SKU)
                    first.
                </p>
                <p>Not in Zoho's official Quotes sample, so confirm with GET /crm/v8/settings/fields?module=Quotes: {z.unverified_fields.join(', ')}.</p>
            </div>
        </Card>
    );
}

function OriginalMessage({ message, redacted }: { message: string; redacted: string | null }) {
    const [showSent, setShowSent] = useState(false);

    return (
        <Card className="gap-0 py-0">
            <details className="group">
                <summary className="cursor-pointer list-none px-4 py-3 text-sm font-medium">
                    Your notes <span className="text-muted-foreground text-xs font-normal group-open:hidden">(show)</span>
                </summary>
                {redacted && redacted !== message && (
                    <div className="flex gap-1 border-t px-4 pt-2">
                        {[
                            ['Original', false],
                            ['Sent to AI (masked)', true],
                        ].map(([label, sent]) => (
                            <button
                                key={String(label)}
                                type="button"
                                onClick={() => setShowSent(sent as boolean)}
                                className={cn(
                                    'rounded px-2 py-0.5 text-xs font-medium',
                                    showSent === sent ? 'bg-muted text-foreground' : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                )}
                <pre className="text-muted-foreground border-t px-4 py-3 font-mono text-xs leading-relaxed whitespace-pre-wrap">
                    {showSent && redacted ? redacted : message}
                </pre>
            </details>
        </Card>
    );
}

const SEVERITY: Record<FlagSeverity, { label: string; icon: typeof CircleAlert; className: string }> = {
    blocking: { label: 'Blocking', icon: OctagonX, className: 'border-destructive/30 bg-destructive/10 text-destructive' },
    warning: { label: 'Check', icon: TriangleAlert, className: 'border-warning/30 bg-warning/10 text-warning' },
    info: { label: 'Note', icon: Info, className: 'border-border bg-muted/50 text-muted-foreground' },
};

/** Draft-level flags; line flags show inside their line card. */
function FlagsPanel({ draft }: { draft: Draft }) {
    const flags = draft.flags.filter((f) => f.line_id === null);
    if (flags.length === 0) return null;

    return (
        <Card className="gap-0 py-0">
            <div className="flex items-center justify-between px-4 pt-3 pb-2">
                <p className="text-sm font-medium">Flags</p>
                <p className="text-muted-foreground text-xs">Checked by code against your account, pricing rules and notes</p>
            </div>
            <ul className="space-y-2 px-4 pb-4">
                {flags.map((f, i) => (
                    <FlagRow key={`${f.code}-${i}`} flag={f} draft={draft} />
                ))}
            </ul>
        </Card>
    );
}

function LineFlags({ draft, flags }: { draft: Draft; flags: DraftFlag[] }) {
    if (flags.length === 0) return null;

    return (
        <ul className="mt-3 space-y-1.5">
            {flags.map((f, i) => (
                <FlagRow key={`${f.code}-${i}`} flag={f} draft={draft} compact />
            ))}
        </ul>
    );
}

const PICK: Record<LinePick['confidence'], { label: string; className: string }> = {
    named: { label: 'Named in notes', className: 'text-success border-success/30 bg-success/10' },
    only: { label: 'Only match', className: 'text-success border-success/30 bg-success/10' },
    best_fit: { label: 'Best fit', className: 'text-success border-success/30 bg-success/10' },
};

/** Why this product was preselected, and how sure that is. Gone once the salesperson confirms or changes it. */
function PickReason({ pick }: { pick: LinePick }) {
    const p = PICK[pick.confidence];

    return (
        <p className="mt-2 ml-[3.75rem] flex flex-wrap items-center gap-1.5 text-xs">
            <Badge variant="outline" className={cn('h-5 gap-1 font-normal', p.className)}>
                Preselected · {p.label}
            </Badge>
            <span className="text-muted-foreground">{pick.reason}</span>
        </p>
    );
}

function FlagRow({ flag, draft, compact = false }: { flag: DraftFlag; draft?: Draft; compact?: boolean }) {
    const s = SEVERITY[flag.severity];
    const Icon = s.icon;

    return (
        <li className={cn('flex items-start gap-2.5 rounded-lg border px-3', compact ? 'py-1.5' : 'py-2', s.className)}>
            <Icon className="mt-0.5 size-4 shrink-0" />
            <div className="min-w-0 flex-1 text-sm">
                <p className="text-foreground">{flag.message.replace(/^Line \d+: /, compact ? '' : '$&')}</p>
                {flag.quote && <p className="text-muted-foreground mt-0.5 truncate text-xs italic">“{flag.quote}”</p>}
            </div>
            {flag.action?.type === 'apply_discount' && draft && (
                <Button
                    size="sm"
                    variant="outline"
                    className="h-7 shrink-0 text-xs"
                    onClick={() => router.patch(`/drafts/${draft.ulid}`, { discount_pct: flag.action!.value }, { preserveScroll: true })}
                >
                    Apply {pct(flag.action.value)}
                </Button>
            )}
            {flag.action?.type === 'choose_option' && draft && flag.line_id && (
                <Button
                    size="sm"
                    variant="outline"
                    className="h-7 shrink-0 text-xs"
                    onClick={() => router.patch(`/drafts/${draft.ulid}/lines/${flag.line_id}`, { option: flag.action!.value }, { preserveScroll: true })}
                >
                    Use it
                </Button>
            )}
        </li>
    );
}

/** Quote-level discount. Above the account max it's allowed in but blocks finalize (flag). */
function QuoteDiscount({ draft }: { draft: Draft }) {
    const current = draft.discount_pct ?? 0;
    const [value, setValue] = useState(String(current || ''));
    useEffect(() => setValue(String(draft.discount_pct || '')), [draft.discount_pct]);

    const commit = () => {
        const n = value.trim() === '' ? 0 : Number(value);
        if (!Number.isFinite(n) || n < 0 || n > 100) return setValue(String(current || ''));
        if (n !== current) router.patch(`/drafts/${draft.ulid}`, { discount_pct: n || null }, { preserveScroll: true });
    };

    const over = current > draft.max_discount_pct;

    return (
        <div className="mt-4 flex items-center justify-between gap-3 border-t pt-4">
            <div>
                <p className="text-sm font-medium">Quote discount</p>
                <p className="text-muted-foreground text-xs">
                    Max {pct(draft.max_discount_pct)}. Best single discount per line wins.
                </p>
            </div>
            <label className="flex items-center gap-1">
                <Input
                    type="number"
                    min={0}
                    max={100}
                    step="0.5"
                    value={value}
                    placeholder="0"
                    onChange={(e) => setValue(e.target.value)}
                    onBlur={commit}
                    onKeyDown={(e) => e.key === 'Enter' && (e.target as HTMLInputElement).blur()}
                    aria-label="Quote discount percent"
                    aria-invalid={over}
                    className="h-8 w-20 text-right tabular-nums"
                />
                <span className="text-muted-foreground text-sm">%</span>
            </label>
        </div>
    );
}

/** A draft always has an account: it can be changed, not removed. */
function AccountCard({ draft }: { draft: Draft }) {
    const finalized = draft.status === 'finalized';
    const a = draft.account;
    const [changing, setChanging] = useState(false);

    return (
        <Card className="gap-3 p-4">
            <div className="flex items-center justify-between">
                <p className="text-sm font-medium">Customer</p>
                {!finalized && changing && a && (
                    <button type="button" onClick={() => setChanging(false)} className="text-muted-foreground hover:text-foreground text-xs">
                        Cancel
                    </button>
                )}
            </div>
            {finalized ? (
                a ? <AccountDetails account={a} /> : <p className="text-muted-foreground text-sm">No account</p>
            ) : (
                <>
                    <AccountPicker
                        value={changing ? null : a}
                        onChange={(next: Account | null) => {
                            if (!next) return setChanging(true); // "clear" means "pick another"
                            setChanging(false);
                            router.patch(`/drafts/${draft.ulid}`, { account_id: next.id }, { preserveScroll: true });
                        }}
                    />
                    {a && !changing && <AccountDetails account={a} />}
                </>
            )}
        </Card>
    );
}

function AccountDetails({ account }: { account: Account }) {
    return (
        <div className="space-y-1.5 text-xs">
            {account.contacts[0] && (
                <p>
                    <span className="text-muted-foreground">Contact: </span>
                    {account.contacts[0].name}
                    {account.contacts[0].email && <span className="text-muted-foreground"> · {account.contacts[0].email}</span>}
                </p>
            )}
            {account.ship_to && (
                <p className="whitespace-pre-line">
                    <span className="text-muted-foreground">Ship to: </span>
                    {account.ship_to}
                </p>
            )}
            <div className="flex flex-wrap gap-1 pt-1">
                {account.discount_pct > 0 && <Badge variant="secondary">{pct(account.discount_pct)} contract</Badge>}
                <Badge variant="outline">max {pct(account.max_discount_pct)}</Badge>
                {account.payment_terms && <Badge variant="outline">{account.payment_terms}</Badge>}
                {account.tax_exempt && <Badge variant="outline">Tax exempt</Badge>}
            </div>
        </div>
    );
}
