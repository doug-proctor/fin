import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { Totals } from '@/types/transactions';

interface Props {
    summary: Totals;
    /**
     * The month's targets added up, or null when none is set. Fixed for the
     * month, unlike the figures beside it, which follow the filters.
     */
    targetTotal: number | null;
}

/**
 * Totals for the current filter set, not the current page, so narrowing the
 * filters immediately answers "how much did this come to".
 */
export function TransactionsSummary({ summary, targetTotal }: Props) {
    const stats: { label: string; value: string; className?: string }[] = [
        { label: 'Transactions', value: summary.count.toLocaleString() },
        {
            label: 'Money in',
            value: formatMoney(summary.moneyIn),
            className: 'text-emerald-600 dark:text-emerald-400',
        },
        {
            label: 'Money out',
            value: formatMoney(summary.moneyOut),
            className: 'text-rose-600 dark:text-rose-400',
        },
        {
            label: 'Net',
            value: formatMoney(summary.net, 'GBP', { signed: true }),
            className:
                summary.net < 0
                    ? 'text-rose-600 dark:text-rose-400'
                    : 'text-emerald-600 dark:text-emerald-400',
        },
    ];

    /** Only shown once there is something to compare the month against. */
    if (targetTotal !== null) {
        stats.push({
            label: 'Target',
            value: formatMoney(targetTotal),
            className:
                summary.moneyOut > targetTotal
                    ? 'text-rose-600 dark:text-rose-400'
                    : undefined,
        });
    }

    return (
        <dl
            className={cn(
                'grid grid-cols-2 gap-px overflow-hidden rounded-lg border bg-border',
                targetTotal === null
                    ? 'sm:grid-cols-4'
                    : 'sm:grid-cols-3 lg:grid-cols-5',
            )}
        >
            {stats.map((stat) => (
                <div key={stat.label} className="bg-background p-4">
                    <dt className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        {stat.label}
                    </dt>
                    <dd
                        className={`mt-1 text-xl font-semibold tabular-nums ${stat.className ?? ''}`}
                    >
                        {stat.value}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
