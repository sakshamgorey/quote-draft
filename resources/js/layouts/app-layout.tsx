import { Link, usePage } from '@inertiajs/react';
import { BadgePercent, Building2, DoorOpen, FilePlus2, Files, Search } from 'lucide-react';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

const NAV = [
    { href: '/', label: 'New draft', icon: FilePlus2, match: (p: string) => p === '/' },
    { href: '/drafts', label: 'Drafts', icon: Files, match: (p: string) => p.startsWith('/drafts') },
    { href: '/products', label: 'Products', icon: Search, match: (p: string) => p.startsWith('/products') },
    { href: '/accounts', label: 'Accounts', icon: Building2, match: (p: string) => p.startsWith('/accounts') },
    { href: '/pricing', label: 'Pricing', icon: BadgePercent, match: (p: string) => p.startsWith('/pricing') },
];

/** One-line confirmation after a save ("Account saved."). */
function FlashStatus() {
    const { flash } = usePage<{ flash?: { status: string | null } }>().props;
    if (!flash?.status) return null;

    return (
        <div className="bg-success/10 text-success border-success/20 border-b px-4 py-2 text-center text-sm font-medium">{flash.status}</div>
    );
}

export default function AppLayout({ children }: { children: ReactNode }) {
    const { url, props } = usePage<{ catalogTotal: number }>();
    const path = url.split('?')[0];

    return (
        <div className="flex min-h-screen flex-col">
            <header className="bg-background/80 sticky top-0 z-10 border-b backdrop-blur">
                <div className="mx-auto flex h-14 max-w-6xl items-center justify-between gap-4 px-4">
                    <div className="flex items-center gap-6">
                        <Link href="/" className="flex items-center gap-2 font-semibold tracking-tight">
                            <span className="bg-primary text-primary-foreground grid size-7 place-items-center rounded-md">
                                <DoorOpen className="size-4" />
                            </span>
                            <span className="hidden sm:inline">BEST Quote Draft</span>
                        </Link>
                        <nav className="flex items-center gap-1">
                            {NAV.map(({ href, label, icon: Icon, match }) => (
                                <Link
                                    key={href}
                                    href={href}
                                    title={label}
                                    className={cn(
                                        'text-muted-foreground hover:text-foreground hover:bg-muted flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium transition-colors',
                                        match(path) && 'bg-muted text-foreground',
                                    )}
                                >
                                    <Icon className="size-4" />
                                    <span className="hidden md:inline">{label}</span>
                                </Link>
                            ))}
                        </nav>
                    </div>
                    <span className="text-muted-foreground hidden text-xs lg:inline">
                        {props.catalogTotal.toLocaleString()} products in catalog
                    </span>
                </div>
            </header>
            <FlashStatus />

            <main className="mx-auto w-full max-w-6xl flex-1 px-4 pt-8 pb-16">{children}</main>

            <footer className="text-muted-foreground border-t py-5 text-center text-xs">
                Catalog from{' '}
                <a className="hover:text-foreground underline underline-offset-4" href="https://www.bestaccessdoors.com" target="_blank" rel="noopener">
                    bestaccessdoors.com
                </a>
                . Opening sizes shown, not overall (flange) sizes.
            </footer>
        </div>
    );
}
