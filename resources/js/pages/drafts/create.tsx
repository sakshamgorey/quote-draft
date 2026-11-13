import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowRight, CircleAlert, FileText, Loader2, ShieldCheck, Sparkles, X } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';

import { AccountPicker } from '@/components/account-picker';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { EXAMPLE_NOTES } from '@/lib/example-notes';
import AppLayout from '@/layouts/app-layout';
import { dateTime } from '@/lib/format';
import type { Account } from '@/types';

interface RecentDraft {
    ulid: string;
    status: 'draft' | 'finalized';
    account: string | null;
    excerpt: string;
    created_at: string;
}

interface Props {
    maxLength: number;
    recent: RecentDraft[];
}

const STEPS = [
    'Pick the customer account, then paste your notes as-is',
    'Contact details are masked; the AI reads the notes once and lists each item with your exact words',
    'Products and prices come from the catalog and the account’s pricing rules, never from the AI',
    'You resolve flags and open lines, finalize and copy the quote',
];

export default function CreateDraft({ maxLength, recent }: Props) {
    const [account, setAccount] = useState<Account | null>(null);
    const form = useForm({ message: '', account_id: null as number | null });
    const textareaRef = useRef<HTMLTextAreaElement>(null);

    function submit(e?: FormEvent) {
        e?.preventDefault();
        if (!form.data.message.trim() || form.processing) return;
        form.post('/drafts', { preserveScroll: true });
    }

    return (
        <AppLayout>
            <Head title="New draft" />

            <div className="mb-6">
                <h1 className="text-2xl font-semibold tracking-tight">Create a quote draft</h1>
                <p className="text-muted-foreground mt-1 text-sm">
                    Turn your call, email or site-visit notes into a priced draft you review and finalize. Nothing is ever sent automatically.
                </p>
            </div>

            <div className="grid gap-6 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]">
                <Card className="gap-0 py-0">
                    <form onSubmit={submit} className="space-y-4 p-4 sm:p-5">
                        <div>
                            <p className="mb-1.5 text-sm font-medium">Customer account</p>
                            <AccountPicker
                                value={account}
                                disabled={form.processing}
                                onChange={(a) => {
                                    setAccount(a);
                                    form.setData('account_id', a?.id ?? null);
                                }}
                            />
                            {form.errors.account_id ? (
                                <p className="text-destructive mt-1.5 flex items-center gap-1.5 text-xs">
                                    <CircleAlert className="size-3.5 shrink-0" /> {form.errors.account_id}
                                </p>
                            ) : (
                                !account && (
                                    <p className="text-muted-foreground mt-1.5 text-xs">
                                        Required. It sets the prices, discount limits and ship-to, and lets contact details be masked before the AI reads your
                                        notes.
                                    </p>
                                )
                            )}
                        </div>

                        <div>
                            <div className="flex flex-wrap items-baseline justify-between gap-x-2 gap-y-1">
                                <label htmlFor="message" className="text-sm font-medium">
                                    Your notes
                                </label>
                                <label className="text-primary flex items-center gap-1 text-xs font-medium">
                                    <Sparkles className="size-3" />
                                    <select
                                        value=""
                                        disabled={form.processing}
                                        aria-label="Fill in example notes"
                                        onChange={(e) => {
                                            const example = EXAMPLE_NOTES[Number(e.target.value)];
                                            if (!example) return;
                                            if (form.data.message.trim() && !confirm('Replace your notes with this example?')) return;
                                            form.setData('message', example.text);
                                            textareaRef.current?.focus();
                                        }}
                                        className="cursor-pointer bg-transparent font-medium outline-none hover:underline"
                                    >
                                        <option value="" disabled>
                                            Try example notes…
                                        </option>
                                        {EXAMPLE_NOTES.map((ex, i) => (
                                            <option key={ex.label} value={i}>
                                                {ex.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            </div>
                            <Textarea
                                id="message"
                                ref={textareaRef}
                                value={form.data.message}
                                onChange={(e) => form.setData('message', e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) submit();
                                }}
                                maxLength={maxLength}
                                readOnly={form.processing}
                                aria-invalid={!!form.errors.message}
                                autoFocus
                                placeholder={'Shorthand is fine, e.g.\n6x 24x24 FR clg panels, drywall\nroof hatch 30x36, she wants 10%'}
                                className="bg-background mt-2 min-h-72 resize-y font-mono text-[13px] leading-relaxed [field-sizing:fixed]"
                            />
                        </div>

                        {form.errors.message && (
                            <p className="text-destructive flex items-center gap-1.5 text-sm">
                                <CircleAlert className="size-4 shrink-0" /> {form.errors.message}
                            </p>
                        )}

                        <div className="flex items-center justify-between gap-3">
                            <span className="text-muted-foreground text-xs tabular-nums">
                                {form.data.message.length.toLocaleString()} / {maxLength.toLocaleString()}
                            </span>
                            <div className="flex items-center gap-2">
                                {form.data.message && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        disabled={form.processing}
                                        onClick={() => {
                                            form.setData('message', '');
                                            form.clearErrors();
                                            textareaRef.current?.focus();
                                        }}
                                    >
                                        <X /> Clear
                                    </Button>
                                )}
                                <Button
                                    type="submit"
                                    disabled={form.processing || !form.data.message.trim() || !account}
                                    title={!account ? 'Pick the customer account first' : undefined}
                                >
                                    {form.processing ? <Loader2 className="animate-spin" /> : <FileText />}
                                    {form.processing ? 'Reading notes…' : 'Create draft'}
                                </Button>
                            </div>
                        </div>
                    </form>
                </Card>

                <div className="space-y-6">
                    <Card className="gap-3 p-5">
                        <p className="flex items-center gap-1.5 text-sm font-medium">
                            <ShieldCheck className="text-primary size-4" /> How it works
                        </p>
                        <ol className="space-y-2.5">
                            {STEPS.map((s, i) => (
                                <li key={s} className="flex gap-3 text-sm">
                                    <span className="bg-primary/10 text-primary grid size-5 shrink-0 place-items-center rounded-full text-xs font-semibold">
                                        {i + 1}
                                    </span>
                                    <span className="text-muted-foreground">{s}</span>
                                </li>
                            ))}
                        </ol>
                    </Card>

                    {recent.length > 0 && (
                        <Card className="gap-0 py-0">
                            <div className="flex items-center justify-between px-5 pt-4 pb-2">
                                <p className="text-sm font-medium">Recent drafts</p>
                                <Link href="/drafts" className="text-primary text-xs font-medium hover:underline">
                                    View all
                                </Link>
                            </div>
                            <ul className="divide-y">
                                {recent.map((d) => (
                                    <li key={d.ulid}>
                                        <Link href={`/drafts/${d.ulid}`} className="hover:bg-muted/50 group flex items-center gap-3 px-5 py-3">
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm">
                                                    {d.account && <span className="font-medium">{d.account} · </span>}
                                                    {d.excerpt}
                                                </p>
                                                <p className="text-muted-foreground mt-0.5 text-xs">{dateTime(d.created_at)}</p>
                                            </div>
                                            <Badge variant={d.status === 'finalized' ? 'default' : 'outline'}>
                                                {d.status === 'finalized' ? 'Finalized' : 'Draft'}
                                            </Badge>
                                            <ArrowRight className="text-muted-foreground size-4 opacity-0 transition-opacity group-hover:opacity-100" />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
