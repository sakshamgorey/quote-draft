import { Head, Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, FilePlus2, Files } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { dateTime } from '@/lib/format';
import type { DraftSummary } from '@/types';

interface Props {
    drafts: { data: DraftSummary[]; prev_url: string | null; next_url: string | null; total: number };
}

export default function DraftIndex({ drafts }: Props) {
    return (
        <AppLayout>
            <Head title="Drafts" />

            <div className="mb-6 flex items-end justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Drafts</h1>
                    <p className="text-muted-foreground mt-1 text-sm">{drafts.total.toLocaleString()} saved quote drafts, newest first.</p>
                </div>
                <Button asChild>
                    <Link href="/">
                        <FilePlus2 /> New draft
                    </Link>
                </Button>
            </div>

            {drafts.data.length === 0 ? (
                <Card className="items-center border-dashed px-6 py-14 text-center shadow-none">
                    <div className="bg-muted grid size-12 place-items-center rounded-full">
                        <Files className="text-muted-foreground size-5" />
                    </div>
                    <p className="font-medium">No drafts yet</p>
                    <Button asChild variant="outline" size="sm">
                        <Link href="/">Create your first draft</Link>
                    </Button>
                </Card>
            ) : (
                <Card className="gap-0 overflow-hidden py-0">
                    <ul className="divide-y">
                        {drafts.data.map((d) => (
                            <li key={d.ulid}>
                                <Link href={`/drafts/${d.ulid}`} className="hover:bg-muted/50 flex items-center gap-4 px-4 py-3.5 transition-colors">
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm">
                                            <span className="font-medium">{d.account ?? 'No account'}</span>
                                            <span className="text-muted-foreground"> · {d.excerpt}</span>
                                        </p>
                                        <p className="text-muted-foreground mt-0.5 text-xs">
                                            {dateTime(d.created_at)} · {d.lines} {d.lines === 1 ? 'line' : 'lines'} · {d.ready} with a product
                                        </p>
                                    </div>
                                    <Badge variant={d.status === 'finalized' ? 'default' : 'outline'}>
                                        {d.status === 'finalized' ? 'Finalized' : 'Draft'}
                                    </Badge>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            {(drafts.prev_url || drafts.next_url) && (
                <div className="mt-6 flex justify-center gap-2">
                    <Button asChild variant="outline" size="sm" disabled={!drafts.prev_url}>
                        <Link href={drafts.prev_url ?? '#'} preserveScroll>
                            <ChevronLeft /> Newer
                        </Link>
                    </Button>
                    <Button asChild variant="outline" size="sm" disabled={!drafts.next_url}>
                        <Link href={drafts.next_url ?? '#'} preserveScroll>
                            Older <ChevronRight />
                        </Link>
                    </Button>
                </div>
            )}
        </AppLayout>
    );
}
