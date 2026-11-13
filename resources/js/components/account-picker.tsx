import { Building2, Loader2, Search, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Input } from '@/components/ui/input';
import { pct } from '@/lib/format';
import type { Account } from '@/types';

interface Props {
    value: Account | null;
    onChange: (account: Account | null) => void;
    disabled?: boolean;
}

/** Search-as-you-type account picker (Scout pgsql on account names, typo tolerant). */
export function AccountPicker({ value, onChange, disabled }: Props) {
    const [q, setQ] = useState('');
    const [open, setOpen] = useState(false);
    const [results, setResults] = useState<Account[]>([]);
    const [loading, setLoading] = useState(false);
    const box = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        const id = setTimeout(async () => {
            setLoading(true);
            try {
                const res = await fetch(`/accounts/lookup?q=${encodeURIComponent(q.trim())}`, { headers: { Accept: 'application/json' }, signal: controller.signal });
                setResults((await res.json()).data ?? []);
            } catch {
                /* aborted */
            } finally {
                setLoading(false);
            }
        }, 200);
        return () => {
            clearTimeout(id);
            controller.abort();
        };
    }, [q, open]);

    useEffect(() => {
        const close = (e: MouseEvent) => box.current && !box.current.contains(e.target as Node) && setOpen(false);
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, []);

    if (value) {
        return (
            <div className="bg-background flex items-center gap-3 rounded-md border px-3 py-2">
                <Building2 className="text-muted-foreground size-4 shrink-0" />
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{value.name}</p>
                    <p className="text-muted-foreground text-xs">
                        {value.discount_pct > 0 ? `${pct(value.discount_pct)} contract discount` : 'List prices'} · max {pct(value.max_discount_pct)}
                        {value.payment_terms && ` · ${value.payment_terms}`}
                    </p>
                </div>
                {!disabled && (
                    <button type="button" onClick={() => onChange(null)} className="text-muted-foreground hover:text-foreground" aria-label="Clear account">
                        <X className="size-4" />
                    </button>
                )}
            </div>
        );
    }

    return (
        <div ref={box} className="relative">
            <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
            <Input
                value={q}
                disabled={disabled}
                onChange={(e) => {
                    setQ(e.target.value);
                    setOpen(true);
                }}
                onFocus={() => setOpen(true)}
                placeholder="Search customer accounts…"
                aria-label="Customer account"
                className="bg-background pl-9"
            />
            {loading && <Loader2 className="text-muted-foreground absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin" />}
            {open && (
                <ul className="bg-popover absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-md border shadow-md">
                    {results.length === 0 && !loading && <li className="text-muted-foreground px-3 py-2 text-sm">No matching accounts</li>}
                    {results.map((a) => (
                        <li key={a.id}>
                            <button
                                type="button"
                                onClick={() => {
                                    onChange(a);
                                    setOpen(false);
                                    setQ('');
                                }}
                                className="hover:bg-muted flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm"
                            >
                                <span className="truncate">{a.name}</span>
                                {a.discount_pct > 0 && <span className="text-muted-foreground shrink-0 text-xs">{pct(a.discount_pct)}</span>}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
