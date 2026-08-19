import { Head } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import Heading from '@/components/heading';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate } from '@/lib/money';
import { index as syncReportsIndex } from '@/routes/sync-reports';

interface SyncReport {
    id: string;
    provider: 'monzo' | 'amex';
    status: string;
    imported: number;
    updated: number | null;
    skipped: number | null;
    filename: string | null;
    oldestBookedAt: string | null;
    newestBookedAt: string | null;
    error: string | null;
    gapFrom: string | null;
    gapTo: string | null;
    startedAt: string;
}

interface Props {
    reports: SyncReport[];
}

const providerLabels: Record<SyncReport['provider'], string> = {
    monzo: 'Monzo',
    amex: 'AMEX',
};

function ranAt(iso: string): string {
    return new Date(iso).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

/**
 * The span the imported transactions cover. A run that brought nothing in has
 * no span, which is the normal result for a quiet night.
 */
function range(report: SyncReport): string {
    if (report.oldestBookedAt === null || report.newestBookedAt === null) {
        return '—';
    }

    const from = formatDate(report.oldestBookedAt);
    const to = formatDate(report.newestBookedAt);

    return from === to ? from : `${from} – ${to}`;
}

/** Whole days between the two ends of a gap. */
function gapDays(report: SyncReport): number {
    const from = new Date(report.gapFrom as string).getTime();
    const to = new Date(report.gapTo as string).getTime();

    return Math.round((to - from) / 86_400_000);
}

/**
 * An AMEX upload reports rows it updated and skipped as well as rows it added;
 * a Monzo sync only counts what it brought in.
 */
function outcome(report: SyncReport): string | null {
    if (report.provider !== 'amex' || report.status === 'failed') {
        return null;
    }

    const parts: string[] = [];

    if (report.updated) {
        parts.push(`${report.updated.toLocaleString()} updated`);
    }

    if (report.skipped) {
        parts.push(`${report.skipped.toLocaleString()} skipped`);
    }

    return parts.length === 0 ? null : parts.join(', ');
}

export default function SyncReports({ reports }: Props) {
    /**
     * Monzo serves 89 days and refuses anything older, so a run that starts
     * more than 89 days after the previous one leaves a span neither covered.
     * Those transactions are unreachable, so the warning stays put rather
     * than clearing on the next successful sync.
     */
    const gaps = reports.filter(
        (report) => report.gapFrom !== null && report.gapTo !== null,
    );

    return (
        <>
            <Head title="Syncs" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Syncs"
                    description="Every Monzo sync and AMEX upload, newest first"
                />

                {gaps.length > 0 && (
                    <Alert variant="destructive">
                        <AlertTriangle className="h-4 w-4" />
                        <AlertTitle>Transactions may be missing</AlertTitle>
                        <AlertDescription>
                            <p>
                                Monzo only serves the last 89 days. These syncs
                                ran more than 89 days after the one before, so
                                nothing dated in between was ever offered to
                                either, and Monzo will not serve it now.
                            </p>
                            <ul className="mt-2 list-disc pl-4">
                                {gaps.map((report) => (
                                    <li key={report.id}>
                                        {formatDate(report.gapFrom as string)}{' '}
                                        to {formatDate(report.gapTo as string)}
                                        <span className="text-muted-foreground">
                                            {' '}
                                            ({gapDays(report)} days)
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </AlertDescription>
                    </Alert>
                )}

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Ran</TableHead>
                            <TableHead>Source</TableHead>
                            <TableHead className="text-right">
                                Imported
                            </TableHead>
                            <TableHead>Transactions dated</TableHead>
                            <TableHead>Result</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {reports.length === 0 && (
                            <TableRow>
                                <TableCell
                                    colSpan={5}
                                    className="py-12 text-center text-muted-foreground"
                                >
                                    No syncs yet.
                                </TableCell>
                            </TableRow>
                        )}

                        {reports.map((report) => (
                            <TableRow key={report.id}>
                                <TableCell className="whitespace-nowrap text-muted-foreground">
                                    {ranAt(report.startedAt)}
                                </TableCell>
                                <TableCell>
                                    <Badge variant="secondary">
                                        {providerLabels[report.provider]}
                                    </Badge>
                                    {report.filename !== null && (
                                        <div className="mt-1 max-w-[16rem] truncate text-xs text-muted-foreground">
                                            {report.filename}
                                        </div>
                                    )}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {report.status === 'failed'
                                        ? '—'
                                        : report.imported.toLocaleString()}
                                </TableCell>
                                <TableCell className="whitespace-nowrap text-muted-foreground">
                                    {report.status === 'failed'
                                        ? '—'
                                        : range(report)}
                                </TableCell>
                                <TableCell className="max-w-[28rem]">
                                    {report.gapFrom !== null && (
                                        <Badge
                                            variant="destructive"
                                            className="mb-1"
                                        >
                                            {gapDays(report)} day gap
                                        </Badge>
                                    )}
                                    {report.status === 'failed' ? (
                                        <div className="flex flex-col gap-1">
                                            <Badge variant="destructive">
                                                Failed
                                            </Badge>
                                            <span className="text-xs text-muted-foreground">
                                                {report.error}
                                            </span>
                                        </div>
                                    ) : (
                                        <div className="flex flex-col gap-1">
                                            <Badge variant="outline">
                                                {report.status === 'running'
                                                    ? 'Running'
                                                    : 'Completed'}
                                            </Badge>
                                            {outcome(report) !== null && (
                                                <span className="text-xs text-muted-foreground">
                                                    {outcome(report)}
                                                </span>
                                            )}
                                        </div>
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </>
    );
}

SyncReports.layout = {
    breadcrumbs: [{ title: 'Syncs', href: syncReportsIndex() }],
};
