const usd = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

export function money(cents: number | null | undefined): string {
    return cents == null ? 'Price on request' : usd.format(cents / 100);
}

/** 10 → "10%", 12.5 → "12.5%". */
export function pct(value: number | null | undefined): string {
    return value == null ? '—' : `${Number(value.toFixed(2))}%`;
}

export function dateTime(iso: string): string {
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}
