import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus, Save, Trash2, X } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { pct } from '@/lib/format';
import type { Account, AccountContact, PriceRule } from '@/types';

type EditableAccount = Account & {
    notes: string | null;
    own_max_discount_pct: number | null;
    rules: Pick<PriceRule, 'id' | 'label' | 'product_type' | 'brand' | 'min_quantity' | 'discount_pct' | 'active'>[];
};

interface Props {
    account: EditableAccount | null;
    defaultMaxDiscount: number;
}

const EMPTY_CONTACT: AccountContact = { name: '', email: '', phone: '' };

export default function AccountForm({ account, defaultMaxDiscount }: Props) {
    const form = useForm({
        name: account?.name ?? '',
        contacts: account?.contacts.length ? account.contacts : [EMPTY_CONTACT],
        ship_to: account?.ship_to ?? '',
        payment_terms: account?.payment_terms ?? '',
        tax_exempt: account?.tax_exempt ?? false,
        discount_pct: String(account?.discount_pct ?? 0),
        max_discount_pct: account?.own_max_discount_pct != null ? String(account.own_max_discount_pct) : '',
        notes: account?.notes ?? '',
    });

    const err = form.errors as Record<string, string | undefined>;

    function submit(e: FormEvent) {
        e.preventDefault();
        // Blank contact rows are dropped rather than failing validation.
        form.transform((data) => ({ ...data, contacts: data.contacts.filter((c) => c.name.trim() || c.email?.trim() || c.phone?.trim()) }));
        if (account) form.put(`/accounts/${account.id}`, { preserveScroll: true });
        else form.post('/accounts');
    }

    function setContact(i: number, key: keyof AccountContact, value: string) {
        form.setData('contacts', form.data.contacts.map((c, j) => (j === i ? { ...c, [key]: value } : c)));
    }

    return (
        <AppLayout>
            <Head title={account ? account.name : 'New account'} />

            <Link href="/accounts" className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm">
                <ArrowLeft className="size-4" /> All accounts
            </Link>
            <h1 className="mt-2 mb-6 text-2xl font-semibold tracking-tight">{account ? account.name : 'New account'}</h1>

            <form onSubmit={submit} className="grid gap-6 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]">
                <div className="space-y-6">
                    <Card className="gap-4 p-5">
                        <Field label="Company name" error={err.name}>
                            <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus={!account} />
                        </Field>

                        <div>
                            <p className="mb-1.5 text-sm font-medium">Contacts</p>
                            <p className="text-muted-foreground mb-2 text-xs">Names, emails and phones are masked in notes before they reach the AI.</p>
                            <div className="space-y-2">
                                {form.data.contacts.map((c, i) => (
                                    <div key={i} className="grid grid-cols-[1fr_1fr_8rem_auto] gap-2">
                                        <Input placeholder="Name" value={c.name} onChange={(e) => setContact(i, 'name', e.target.value)} aria-invalid={!!err[`contacts.${i}.name`]} />
                                        <Input placeholder="Email" type="email" value={c.email ?? ''} onChange={(e) => setContact(i, 'email', e.target.value)} aria-invalid={!!err[`contacts.${i}.email`]} />
                                        <Input placeholder="Phone" value={c.phone ?? ''} onChange={(e) => setContact(i, 'phone', e.target.value)} />
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label="Remove contact"
                                            onClick={() => form.setData('contacts', form.data.contacts.filter((_, j) => j !== i))}
                                        >
                                            <X />
                                        </Button>
                                    </div>
                                ))}
                            </div>
                            <Button type="button" variant="outline" size="sm" className="mt-2" onClick={() => form.setData('contacts', [...form.data.contacts, EMPTY_CONTACT])}>
                                <Plus /> Add contact
                            </Button>
                        </div>

                        <Field label="Ship-to address" error={err.ship_to}>
                            <Textarea rows={3} value={form.data.ship_to} onChange={(e) => form.setData('ship_to', e.target.value)} className="[field-sizing:fixed]" />
                        </Field>

                        <Field label="Internal notes" hint="Never sent to the AI or the customer." error={err.notes}>
                            <Textarea rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} className="[field-sizing:fixed]" />
                        </Field>
                    </Card>
                </div>

                <div className="space-y-6">
                    <Card className="gap-4 p-5">
                        <p className="text-sm font-medium">Pricing and terms</p>
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Contract discount %" error={err.discount_pct}>
                                <Input type="number" min={0} max={100} step="0.5" value={form.data.discount_pct} onChange={(e) => form.setData('discount_pct', e.target.value)} />
                            </Field>
                            <Field label="Max discount %" hint={`Blank = company default (${pct(defaultMaxDiscount)})`} error={err.max_discount_pct}>
                                <Input type="number" min={0} max={100} step="0.5" value={form.data.max_discount_pct} onChange={(e) => form.setData('max_discount_pct', e.target.value)} />
                            </Field>
                        </div>
                        <Field label="Payment terms" error={err.payment_terms}>
                            <Input value={form.data.payment_terms} onChange={(e) => form.setData('payment_terms', e.target.value)} placeholder="Net 30" />
                        </Field>
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={form.data.tax_exempt} onChange={(e) => form.setData('tax_exempt', e.target.checked)} className="accent-primary size-4" />
                            Tax exempt
                        </label>
                    </Card>

                    {account && (
                        <Card className="gap-3 p-5">
                            <div className="flex items-center justify-between">
                                <p className="text-sm font-medium">Account price rules</p>
                                <Link href="/pricing" className="text-primary text-xs font-medium hover:underline">
                                    Manage rules
                                </Link>
                            </div>
                            {account.rules.length === 0 ? (
                                <p className="text-muted-foreground text-sm">None. Global rules and the contract discount still apply.</p>
                            ) : (
                                <ul className="divide-y text-sm">
                                    {account.rules.map((r) => (
                                        <li key={r.id} className="flex items-center justify-between gap-2 py-2">
                                            <span className={r.active ? '' : 'text-muted-foreground line-through'}>{r.label}</span>
                                            <Badge variant="secondary">{pct(r.discount_pct)}</Badge>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    )}

                    <div className="flex items-center justify-between gap-2">
                        {account ? (
                            <Button
                                type="button"
                                variant="ghost"
                                className="text-destructive"
                                onClick={() => confirm(`Delete ${account.name}? Finalized drafts keep their copy of the account.`) && router.delete(`/accounts/${account.id}`)}
                            >
                                <Trash2 /> Delete
                            </Button>
                        ) : (
                            <span />
                        )}
                        <Button type="submit" disabled={form.processing}>
                            <Save /> {account ? 'Save account' : 'Create account'}
                        </Button>
                    </div>
                </div>
            </form>
        </AppLayout>
    );
}

function Field({ label, hint, error, children }: { label: string; hint?: string; error?: string; children: ReactNode }) {
    return (
        <label className="block">
            <span className="text-sm font-medium">{label}</span>
            <div className="mt-1.5">{children}</div>
            {hint && !error && <span className="text-muted-foreground mt-1 block text-xs">{hint}</span>}
            {error && <span className="text-destructive mt-1 block text-xs">{error}</span>}
        </label>
    );
}
