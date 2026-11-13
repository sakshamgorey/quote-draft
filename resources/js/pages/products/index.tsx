import { Head, router } from '@inertiajs/react';
import { ChevronDown, ExternalLink, EyeOff, Flame, ImageOff, Loader2, Search as SearchIcon, SearchX, SlidersHorizontal, Star, Thermometer, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Pagination,
    PaginationContent,
    PaginationEllipsis,
    PaginationItem,
    PaginationLink,
    PaginationNext,
    PaginationPrevious,
} from '@/components/ui/pagination';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import type { Facet, FacetKey, ProductFilters, ProductPage, ProductRow, ProductSort } from '@/types';

interface Props {
    filters: ProductFilters;
    products: ProductPage;
    facets: Facet[];
}

const EMPTY: ProductFilters = {
    q: '', type: '', width: '', height: '', fire_rated: false, insulated: false, show_unavailable: false,
    brand: [], application: [], surface_type: [], material: [], flange_type: [], purpose: [], price: [], sort: 'popular',
};

const SORTS: { value: ProductSort; label: string }[] = [
    { value: 'relevance', label: 'Best match' },
    { value: 'popular', label: 'Bestselling' },
    { value: 'price_asc', label: 'Price: low to high' },
    { value: 'price_desc', label: 'Price: high to low' },
    { value: 'size', label: 'Size' },
];

const OPTIONS_SHOWN = 6;

const money = (cents: number) => (cents / 100).toLocaleString('en-US', { style: 'currency', currency: 'USD' });

export default function ProductIndex({ filters, products, facets }: Props) {
    const [form, setForm] = useState<ProductFilters>(filters);
    const [loading, setLoading] = useState(false);
    const [showFilters, setShowFilters] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);
    const firstRender = useRef(true);

    const set = <K extends keyof ProductFilters>(key: K, value: ProductFilters[K]) => setForm((f) => ({ ...f, [key]: value }));

    const toggle = (key: FacetKey, value: string) =>
        setForm((f) => {
            if (key === 'type') return { ...f, type: f.type === value ? '' : (value as ProductFilters['type']) };
            const current = f[key] as string[];
            return { ...f, [key]: current.includes(value) ? current.filter((v) => v !== value) : [...current, value] };
        });

    const selected = (key: FacetKey): string[] => (key === 'type' ? (form.type ? [form.type] : []) : (form[key] as string[]));

    const facetChips = facets.flatMap((facet) =>
        selected(facet.key).map((value) => ({
            key: facet.key,
            value,
            label: facet.options.find((o) => o.value === value)?.label ?? value,
        })),
    );
    const active = facetChips.length > 0 || form.q || form.width || form.height || form.fire_rated || form.insulated || form.show_unavailable;

    // Live search: debounce typing and clicks into partial Inertia visits.
    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return;
        }
        const id = setTimeout(() => visit(form), 250);
        return () => clearTimeout(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form]);

    useEffect(() => {
        const start = router.on('start', () => setLoading(true));
        const finish = router.on('finish', () => setLoading(false));
        return () => {
            start();
            finish();
        };
    }, []);

    // "/" focuses search.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes((e.target as HTMLElement)?.tagName);
            if (e.key === '/' && !typing) {
                e.preventDefault();
                inputRef.current?.focus();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    function visit(f: ProductFilters) {
        const params: Record<string, string | string[]> = {};
        if (f.q.trim()) params.q = f.q.trim();
        if (f.type) params.type = f.type;
        if (f.width) params.width = f.width;
        if (f.height) params.height = f.height;
        if (f.fire_rated) params.fire_rated = '1';
        if (f.insulated) params.insulated = '1';
        if (f.show_unavailable) params.show_unavailable = '1';
        for (const key of ['brand', 'application', 'surface_type', 'material', 'flange_type', 'purpose', 'price'] as const) {
            if (f[key].length) params[key] = f[key];
        }
        const defaultSort = f.q.trim() ? 'relevance' : 'popular';
        if (f.sort !== defaultSort) params.sort = f.sort;

        router.get('/products', params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['filters', 'products', 'facets'],
        });
    }

    const sidebar = (
        <div className="space-y-1">
            <div className="space-y-2 pb-3">
                <Toggle on={form.fire_rated} onClick={() => set('fire_rated', !form.fire_rated)} icon={<Flame className="size-3.5" />}>
                    Fire-rated only
                </Toggle>
                <Toggle on={form.insulated} onClick={() => set('insulated', !form.insulated)} icon={<Thermometer className="size-3.5" />}>
                    Insulated only
                </Toggle>
                <Toggle on={form.show_unavailable} onClick={() => set('show_unavailable', !form.show_unavailable)} icon={<EyeOff className="size-3.5" />}>
                    Show unavailable
                </Toggle>
            </div>

            <FacetSection label="Opening size" defaultOpen>
                <div className="flex items-center gap-1.5">
                    <SizeInput label="Width" value={form.width} onChange={(v) => set('width', v)} />
                    <span className="text-muted-foreground text-xs">×</span>
                    <SizeInput label="Height" value={form.height} onChange={(v) => set('height', v)} />
                    <span className="text-muted-foreground text-xs">in</span>
                </div>
            </FacetSection>

            {facets.map((facet) => (
                <FacetGroup key={facet.key} facet={facet} selected={selected(facet.key)} onToggle={(v) => toggle(facet.key, v)} />
            ))}
        </div>
    );

    return (
        <AppLayout>
            <Head title={filters.q || 'Products'} />

            <div className="mb-5">
                <h1 className="text-2xl font-semibold tracking-tight">Products</h1>
                <p className="text-muted-foreground mt-1 text-sm">
                    Search by name, SKU or model, then narrow down. Sizes are the <span className="text-foreground font-medium">opening</span> size
                    customers quote.
                </p>
            </div>

            <form
                role="search"
                onSubmit={(e) => {
                    e.preventDefault();
                    visit(form);
                }}
                className="relative mb-6"
            >
                <SearchIcon className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input
                    ref={inputRef}
                    type="search"
                    value={form.q}
                    onChange={(e) => set('q', e.target.value)}
                    placeholder="Try “fire rated drywall”, “BA-PFI”, “Bilco” or “floor hatch”"
                    aria-label="Search products"
                    autoFocus
                    className="bg-card h-11 pr-10 pl-9 text-base shadow-sm [&::-webkit-search-cancel-button]:hidden"
                />
                <div className="absolute top-1/2 right-3 -translate-y-1/2">
                    {loading ? (
                        <Loader2 className="text-muted-foreground size-4 animate-spin" aria-label="Searching" />
                    ) : (
                        <kbd className="text-muted-foreground bg-muted hidden rounded border px-1.5 font-mono text-[11px] sm:inline-block">/</kbd>
                    )}
                </div>
            </form>

            <div className="grid gap-6 lg:grid-cols-[15rem_minmax(0,1fr)]">
                <aside className="hidden lg:block">
                    <div className="sticky top-20 max-h-[calc(100vh-6rem)] overflow-y-auto pr-2">{sidebar}</div>
                </aside>

                <section className="min-w-0">
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-2">
                            <Button variant="outline" size="sm" className="lg:hidden" onClick={() => setShowFilters((v) => !v)}>
                                <SlidersHorizontal /> Filters{facetChips.length ? ` (${facetChips.length})` : ''}
                            </Button>
                            <h2 className="text-sm font-medium">
                                {products.total.toLocaleString()} {products.total === 1 ? 'product' : 'products'}
                                {filters.q && (
                                    <>
                                        {' '}
                                        for <span className="text-primary">“{filters.q}”</span>
                                    </>
                                )}
                            </h2>
                        </div>
                        <label className="text-muted-foreground flex items-center gap-2 text-xs">
                            Sort
                            <select
                                value={form.sort}
                                onChange={(e) => set('sort', e.target.value as ProductSort)}
                                className="border-input bg-background text-foreground focus-visible:border-ring focus-visible:ring-ring/50 h-8 rounded-md border px-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                            >
                                {SORTS.filter((s) => s.value !== 'relevance' || form.q.trim()).map((s) => (
                                    <option key={s.value} value={s.value}>
                                        {s.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>

                    {showFilters && (
                        <Card className="mb-4 gap-0 p-4 lg:hidden">
                            {sidebar}
                            <Button className="mt-3 w-full" onClick={() => setShowFilters(false)}>
                                Show {products.total.toLocaleString()} products
                            </Button>
                        </Card>
                    )}

                    {active && (
                        <div className="mb-4 flex flex-wrap items-center gap-1.5">
                            {facetChips.map((chip) => (
                                <Chip key={`${chip.key}:${chip.value}`} onRemove={() => toggle(chip.key, chip.value)}>
                                    {chip.label}
                                </Chip>
                            ))}
                            {form.fire_rated && <Chip onRemove={() => set('fire_rated', false)}>Fire-rated</Chip>}
                            {form.insulated && <Chip onRemove={() => set('insulated', false)}>Insulated</Chip>}
                            {form.show_unavailable && <Chip onRemove={() => set('show_unavailable', false)}>Showing unavailable</Chip>}
                            {(form.width || form.height) && (
                                <Chip
                                    onRemove={() => setForm((f) => ({ ...f, width: '', height: '' }))}
                                >{`Opening ${form.width || '?'}" × ${form.height || '?'}"`}</Chip>
                            )}
                            <button
                                type="button"
                                onClick={() => setForm({ ...EMPTY })}
                                className="text-muted-foreground hover:text-foreground ml-1 text-xs font-medium underline-offset-4 hover:underline"
                            >
                                Clear all
                            </button>
                        </div>
                    )}

                    {products.data.length === 0 ? (
                        <EmptyState onClear={() => setForm({ ...EMPTY })} />
                    ) : (
                        <Card className={cn('gap-0 overflow-hidden py-0 transition-opacity', loading && 'pointer-events-none opacity-50')} aria-busy={loading}>
                            <ul className="divide-y">
                                {products.data.map((p) => (
                                    <ProductItem key={p.id} product={p} />
                                ))}
                            </ul>
                        </Card>
                    )}

                    {products.last_page > 1 && <Pager products={products} />}
                </section>
            </div>
        </AppLayout>
    );
}

function FacetSection({ label, count, defaultOpen = false, children }: { label: string; count?: number; defaultOpen?: boolean; children: React.ReactNode }) {
    const [open, setOpen] = useState(defaultOpen);

    return (
        <div className="border-b py-2.5 last:border-b-0">
            <button type="button" onClick={() => setOpen((v) => !v)} className="flex w-full items-center justify-between gap-2 py-1 text-left text-sm font-medium">
                <span>
                    {label}
                    {count ? <span className="bg-primary text-primary-foreground ml-1.5 rounded-full px-1.5 text-[10px]">{count}</span> : null}
                </span>
                <ChevronDown className={cn('text-muted-foreground size-4 transition-transform', open && 'rotate-180')} />
            </button>
            {open && <div className="pt-2">{children}</div>}
        </div>
    );
}

function FacetGroup({ facet, selected, onToggle }: { facet: Facet; selected: string[]; onToggle: (value: string) => void }) {
    const [expanded, setExpanded] = useState(false);
    const [find, setFind] = useState('');
    const many = facet.options.length > 12;
    const options = facet.options.filter((o) => !find || o.label.toLowerCase().includes(find.toLowerCase()));
    // Selected values stay visible even when collapsed.
    const shown = expanded || find ? options : [...options.slice(0, OPTIONS_SHOWN), ...options.slice(OPTIONS_SHOWN).filter((o) => selected.includes(o.value))];

    return (
        <FacetSection label={facet.label} count={selected.length} defaultOpen={selected.length > 0 || ['type', 'surface_type', 'brand'].includes(facet.key)}>
            {many && (
                <Input
                    value={find}
                    onChange={(e) => setFind(e.target.value)}
                    placeholder={`Find ${facet.label.toLowerCase()}…`}
                    className="bg-background mb-2 h-7 text-xs"
                />
            )}
            <ul className="space-y-0.5">
                {shown.map((o) => {
                    const checked = selected.includes(o.value);
                    return (
                        <li key={o.value}>
                            <label
                                className={cn(
                                    'hover:bg-muted flex cursor-pointer items-center gap-2 rounded px-1.5 py-1 text-sm',
                                    o.count === 0 && !checked && 'opacity-40',
                                )}
                            >
                                <input
                                    type={facet.multiple ? 'checkbox' : 'radio'}
                                    name={facet.key}
                                    checked={checked}
                                    aria-label={`${facet.label}: ${o.label} (${o.count})`}
                                    onChange={() => onToggle(o.value)}
                                    onClick={() => !facet.multiple && checked && onToggle(o.value)}
                                    className="accent-primary size-3.5 shrink-0"
                                />
                                <span className="min-w-0 flex-1 truncate">{o.label}</span>
                                <span className="text-muted-foreground text-xs tabular-nums">{o.count.toLocaleString()}</span>
                            </label>
                        </li>
                    );
                })}
            </ul>
            {!find && options.length > OPTIONS_SHOWN && (
                <button type="button" onClick={() => setExpanded((v) => !v)} className="text-primary mt-1 px-1.5 text-xs font-medium hover:underline">
                    {expanded ? 'Show less' : `Show all ${options.length}`}
                </button>
            )}
        </FacetSection>
    );
}

function Toggle({ on, onClick, icon, children }: { on: boolean; onClick: () => void; icon: React.ReactNode; children: React.ReactNode }) {
    return (
        <Button type="button" variant={on ? 'default' : 'outline'} size="sm" className="w-full justify-start" aria-pressed={on} onClick={onClick}>
            {icon}
            {children}
        </Button>
    );
}

function Chip({ children, onRemove }: { children: React.ReactNode; onRemove: () => void }) {
    return (
        <span className="bg-secondary text-secondary-foreground inline-flex items-center gap-1 rounded-full py-0.5 pr-1 pl-2.5 text-xs font-medium">
            {children}
            <button type="button" onClick={onRemove} aria-label="Remove filter" className="hover:bg-background/60 rounded-full p-0.5">
                <X className="size-3" />
            </button>
        </span>
    );
}

function SizeInput({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }) {
    return (
        <Input
            type="number"
            inputMode="decimal"
            min={0}
            step="0.25"
            value={value}
            onChange={(e) => onChange(e.target.value)}
            placeholder={label === 'Width' ? 'W' : 'H'}
            aria-label={`Opening ${label.toLowerCase()} in inches`}
            className="bg-background h-8 w-16 px-2 text-center [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none"
        />
    );
}

function ProductItem({ product }: { product: ProductRow }) {
    const [broken, setBroken] = useState(false);

    return (
        <li>
            <a href={product.url} target="_blank" rel="noopener" className="group hover:bg-muted/50 flex items-center gap-4 px-4 py-3 transition-colors">
                {product.image_url && !broken ? (
                    <img
                        src={product.image_url}
                        alt=""
                        loading="lazy"
                        onError={() => setBroken(true)}
                        className="size-14 shrink-0 rounded-md border bg-white object-contain p-0.5"
                    />
                ) : (
                    <div className="bg-muted grid size-14 shrink-0 place-items-center rounded-md">
                        <ImageOff className="text-muted-foreground size-5" />
                    </div>
                )}

                <div className="min-w-0 flex-1">
                    <div className="flex items-start gap-2">
                        <h3 className="group-hover:text-primary line-clamp-2 text-sm leading-snug font-medium">{product.name}</h3>
                        <ExternalLink className="text-muted-foreground mt-0.5 size-3.5 shrink-0 opacity-0 transition-opacity group-hover:opacity-100" />
                    </div>
                    <div className="text-muted-foreground mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                        <span className="text-foreground/80 font-mono">{product.sku}</span>
                        {product.brand && <span>{product.brand}</span>}
                        <span>{product.type_label}</span>
                        {!product.is_available && (
                            <Badge variant="destructive" className="font-normal">
                                Unavailable
                            </Badge>
                        )}
                        {product.fire_rated && (
                            <span className="text-warning flex items-center gap-0.5 font-medium">
                                <Flame className="size-3" /> Fire-rated
                            </span>
                        )}
                        {product.rating !== null && product.rating_count > 0 && (
                            <span className="flex items-center gap-0.5">
                                <Star className="size-3 fill-current text-amber-500" /> {product.rating.toFixed(1)} ({product.rating_count})
                            </span>
                        )}
                    </div>
                    <p className="mt-1 text-sm font-semibold tabular-nums sm:hidden">
                        {product.price_cents !== null ? money(product.price_cents) : <span className="text-muted-foreground text-xs font-normal">Price on request</span>}
                        <span className="text-muted-foreground ml-2 text-xs font-normal">{product.size ?? '—'} opening</span>
                    </p>
                    {product.tags.length > 0 && (
                        <div className="mt-1.5 hidden flex-wrap gap-1 sm:flex">
                            {product.tags.map((t) => (
                                <Badge key={t} variant="secondary" className="font-normal">
                                    {t}
                                </Badge>
                            ))}
                        </div>
                    )}
                </div>

                <div className="hidden shrink-0 space-y-0.5 text-right sm:block">
                    <p className="text-sm font-semibold tabular-nums">
                        {product.price_cents !== null ? money(product.price_cents) : <span className="text-muted-foreground text-xs font-normal">Price on request</span>}
                    </p>
                    {product.price_cents !== null && product.msrp_cents !== null && product.msrp_cents > product.price_cents && (
                        <p className="text-muted-foreground text-[11px] tabular-nums line-through">{money(product.msrp_cents)}</p>
                    )}
                    <p className="text-muted-foreground text-xs tabular-nums">{product.size ?? '—'} opening</p>
                </div>
            </a>
        </li>
    );
}

function EmptyState({ onClear }: { onClear: () => void }) {
    return (
        <Card className="items-center border-dashed px-6 py-14 text-center shadow-none">
            <div className="bg-muted grid size-12 place-items-center rounded-full">
                <SearchX className="text-muted-foreground size-5" />
            </div>
            <div>
                <p className="font-medium">No products match</p>
                <p className="text-muted-foreground mt-1 text-sm">Remove a filter, try fewer words, or a different size.</p>
            </div>
            <Button variant="outline" size="sm" onClick={onClear}>
                Clear all filters
            </Button>
        </Card>
    );
}

function Pager({ products }: { products: ProductPage }) {
    const first = products.window[0]?.page ?? 1;
    const last = products.window[products.window.length - 1]?.page ?? products.last_page;

    return (
        <Pagination className="mt-8">
            <PaginationContent>
                <PaginationItem>
                    <PaginationPrevious href={products.prev_url} />
                </PaginationItem>

                {first > 1 && (
                    <>
                        <PaginationItem>
                            <PaginationLink href={products.first_url}>1</PaginationLink>
                        </PaginationItem>
                        {first > 2 && (
                            <PaginationItem>
                                <PaginationEllipsis />
                            </PaginationItem>
                        )}
                    </>
                )}

                {products.window.map((link) => (
                    <PaginationItem key={link.page}>
                        <PaginationLink href={link.url} isActive={link.page === products.current_page}>
                            {link.page}
                        </PaginationLink>
                    </PaginationItem>
                ))}

                {last < products.last_page && (
                    <>
                        {last < products.last_page - 1 && (
                            <PaginationItem>
                                <PaginationEllipsis />
                            </PaginationItem>
                        )}
                        <PaginationItem>
                            <PaginationLink href={products.last_url}>{products.last_page}</PaginationLink>
                        </PaginationItem>
                    </>
                )}

                <PaginationItem>
                    <PaginationNext href={products.next_url} />
                </PaginationItem>
            </PaginationContent>
        </Pagination>
    );
}
