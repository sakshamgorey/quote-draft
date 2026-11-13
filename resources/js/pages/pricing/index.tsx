import { Head, router, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { pct } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { PriceRule } from '@/types';

interface Props {
    rules: PriceRule[];
    accounts: { id: number; name: string }[];
    productTypes: { value: string; label: string }[];
    brands: string[];
    maxDiscount: number;
}

const selectClass =
    'border-input bg-background focus-visible:border-ring focus-visible:ring-ring/50 h-9 w-full rounded-md border px-2.5 text-sm shadow-xs outline-none focus-visible:ring-[3px]';

export default function Pricing({ rules, accounts, productTypes, brands, maxDiscount }: Props) {
    const form = useForm({ label: '', account_id: '', product_type: '', brand: '', min_quantity: '1', discount_pct: '', starts_on: '', ends_on: '', active: true });
    const err = form.errors as Record<string, string | undefined>;
    const typeLabel = (t: string | null) => productTypes.find((p) => p.value === t)?.label ?? 'Any product';

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post('/pricing', { preserveScroll: true, onSuccess: () => form.reset() });
    }

    return (
        <AppLayout>
            <Head title="Pricing rules" />

            <div className="mb-6">
                <h1 className="text-2xl font-semibold tracking-tight">Pricing rules</h1>
                <p className="text-muted-foreground mt-1 max-w-3xl text-sm">
                    Each quote line gets the single best discount from: the account's contract discount, the rules below that match, or the quote discount.
                    Discounts never stack. Quote discounts above an account's maximum (default {pct(maxDiscount)}) block finalizing.
                </p>
            </div>

            <Card className="mb-6 gap-0 p-5">
                <p className="mb-3 text-sm font-medium">Add a rule</p>
                <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Field label="Label" error={err.label} className="sm:col-span-2">
                        <Input value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} placeholder="Volume: 10+ access doors" />
                    </Field>
                    <Field label="Account" error={err.account_id}>
                        <select value={form.data.account_id} onChange={(e) => form.setData('account_id', e.target.value)} className={selectClass}>
                            <option value="">Everyone</option>
                            {accounts.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Discount %" error={err.discount_pct}>
                        <Input type="number" min={0} max={100} step="0.5" value={form.data.discount_pct} onChange={(e) => form.setData('discount_pct', e.target.value)} />
                    </Field>
                    <Field label="Product type" error={err.product_type}>
                        <select value={form.data.product_type} onChange={(e) => form.setData('product_type', e.target.value)} className={selectClass}>
                            <option value="">Any product</option>
                            {productTypes.map((t) => (
                                <option key={t.value} value={t.value}>
                                    {t.label}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Brand" error={err.brand}>
                        <select value={form.data.brand} onChange={(e) => form.setData('brand', e.target.value)} className={selectClass}>
                            <option value="">Any brand</option>
                            {brands.map((b) => (
                                <option key={b}>{b}</option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Minimum quantity" error={err.min_quantity}>
                        <Input type="number" min={1} value={form.data.min_quantity} onChange={(e) => form.setData('min_quantity', e.target.value)} />
                    </Field>
                    <div className="grid grid-cols-2 gap-2">
                        <Field label="Starts" error={err.starts_on}>
                            <Input type="date" value={form.data.starts_on} onChange={(e) => form.setData('starts_on', e.target.value)} />
                        </Field>
                        <Field label="Ends" error={err.ends_on}>
                            <Input type="date" value={form.data.ends_on} onChange={(e) => form.setData('ends_on', e.target.value)} />
                        </Field>
                    </div>
                    <div className="flex items-end sm:col-span-2 lg:col-span-4 lg:justify-end">
                        <Button type="submit" disabled={form.processing}>
                            <Plus /> Add rule
                        </Button>
                    </div>
                </form>
            </Card>

            <Card className="gap-0 overflow-hidden py-0">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                            <tr>
                                <th className="px-4 py-2.5 font-medium">Rule</th>
                                <th className="px-4 py-2.5 font-medium">Applies to</th>
                                <th className="px-4 py-2.5 text-right font-medium">Min qty</th>
                                <th className="px-4 py-2.5 text-right font-medium">Discount</th>
                                <th className="px-4 py-2.5 font-medium">Dates</th>
                                <th className="px-4 py-2.5 font-medium">Active</th>
                                <th className="px-4 py-2.5" />
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {rules.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-muted-foreground px-4 py-8 text-center">
                                        No rules yet.
                                    </td>
                                </tr>
                            )}
                            {rules.map((r) => (
                                <tr key={r.id} className={cn(!r.active && 'text-muted-foreground')}>
                                    <td className="px-4 py-3 font-medium">{r.label}</td>
                                    <td className="px-4 py-3">
                                        <div className="flex flex-wrap gap-1">
                                            <Badge variant={r.account ? 'default' : 'outline'} className="font-normal">
                                                {r.account?.name ?? 'Everyone'}
                                            </Badge>
                                            <Badge variant="secondary" className="font-normal">
                                                {typeLabel(r.product_type)}
                                            </Badge>
                                            {r.brand && (
                                                <Badge variant="secondary" className="font-normal">
                                                    {r.brand}
                                                </Badge>
                                            )}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">{r.min_quantity}</td>
                                    <td className="px-4 py-3 text-right font-medium tabular-nums">{pct(r.discount_pct)}</td>
                                    <td className="text-muted-foreground px-4 py-3 text-xs">
                                        {r.starts_on || r.ends_on ? `${r.starts_on ?? '…'} → ${r.ends_on ?? '…'}` : 'Always'}
                                    </td>
                                    <td className="px-4 py-3">
                                        <input
                                            type="checkbox"
                                            checked={r.active}
                                            aria-label={`${r.label} active`}
                                            onChange={(e) => router.patch(`/pricing/${r.id}`, { active: e.target.checked }, { preserveScroll: true })}
                                            className="accent-primary size-4"
                                        />
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="text-muted-foreground hover:text-destructive size-8"
                                            aria-label={`Delete ${r.label}`}
                                            onClick={() => confirm(`Delete “${r.label}”?`) && router.delete(`/pricing/${r.id}`, { preserveScroll: true })}
                                        >
                                            <Trash2 />
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Card>
        </AppLayout>
    );
}

function Field({ label, error, className, children }: { label: string; error?: string; className?: string; children: ReactNode }) {
    return (
        <label className={cn('block', className)}>
            <span className="text-xs font-medium">{label}</span>
            <div className="mt-1">{children}</div>
            {error && <span className="text-destructive mt-1 block text-xs">{error}</span>}
        </label>
    );
}
