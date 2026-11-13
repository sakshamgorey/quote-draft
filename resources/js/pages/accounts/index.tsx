import { Head, Link, router } from '@inertiajs/react';
import { Building2, ChevronLeft, ChevronRight, Plus, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { pct } from '@/lib/format';
import type { Account } from '@/types';

interface Props {
    q: string;
    accounts: { data: (Account & { drafts_count: number })[]; total: number; prev_url: string | null; next_url: string | null };
}

export default function AccountIndex({ q, accounts }: Props) {
    const [term, setTerm] = useState(q);
    const first = useRef(true);

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const id = setTimeout(
            () => router.get('/accounts', term.trim() ? { q: term.trim() } : {}, { preserveState: true, replace: true, only: ['q', 'accounts'] }),
            250,
        );
        return () => clearTimeout(id);
    }, [term]);

    return (
        <AppLayout>
            <Head title="Accounts" />

            <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Customer accounts</h1>
                    <p className="text-muted-foreground mt-1 text-sm">Who a quote is for, their contract discount, limits and terms.</p>
                </div>
                <Button asChild>
                    <Link href="/accounts/create">
                        <Plus /> New account
                    </Link>
                </Button>
            </div>

            <div className="relative mb-4 max-w-md">
                <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input value={term} onChange={(e) => setTerm(e.target.value)} placeholder="Search accounts" className="bg-card pl-9" aria-label="Search accounts" />
            </div>

            {accounts.data.length === 0 ? (
                <Card className="items-center border-dashed px-6 py-14 text-center shadow-none">
                    <div className="bg-muted grid size-12 place-items-center rounded-full">
                        <Building2 className="text-muted-foreground size-5" />
                    </div>
                    <p className="font-medium">{q ? `No accounts match “${q}”` : 'No accounts yet'}</p>
                </Card>
            ) : (
                <Card className="gap-0 overflow-hidden py-0">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                <tr>
                                    <th className="px-4 py-2.5 font-medium">Account</th>
                                    <th className="px-4 py-2.5 font-medium">Main contact</th>
                                    <th className="px-4 py-2.5 text-right font-medium">Contract</th>
                                    <th className="px-4 py-2.5 text-right font-medium">Max</th>
                                    <th className="px-4 py-2.5 font-medium">Terms</th>
                                    <th className="px-4 py-2.5 text-right font-medium">Drafts</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {accounts.data.map((a) => (
                                    <tr key={a.id} className="hover:bg-muted/40">
                                        <td className="px-4 py-3">
                                            <Link href={`/accounts/${a.id}/edit`} className="hover:text-primary font-medium">
                                                {a.name}
                                            </Link>
                                            {a.tax_exempt && (
                                                <Badge variant="secondary" className="ml-2 font-normal">
                                                    Tax exempt
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="text-muted-foreground px-4 py-3">{a.contacts[0]?.name ?? '—'}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">{a.discount_pct > 0 ? pct(a.discount_pct) : '—'}</td>
                                        <td className="text-muted-foreground px-4 py-3 text-right tabular-nums">{pct(a.max_discount_pct)}</td>
                                        <td className="text-muted-foreground px-4 py-3">{a.payment_terms ?? '—'}</td>
                                        <td className="text-muted-foreground px-4 py-3 text-right tabular-nums">{a.drafts_count}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>
            )}

            {(accounts.prev_url || accounts.next_url) && (
                <div className="mt-6 flex justify-center gap-2">
                    <Button asChild variant="outline" size="sm" disabled={!accounts.prev_url}>
                        <Link href={accounts.prev_url ?? '#'}>
                            <ChevronLeft /> Previous
                        </Link>
                    </Button>
                    <Button asChild variant="outline" size="sm" disabled={!accounts.next_url}>
                        <Link href={accounts.next_url ?? '#'}>
                            Next <ChevronRight />
                        </Link>
                    </Button>
                </div>
            )}
        </AppLayout>
    );
}
