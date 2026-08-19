import { formatMoney } from '@/lib/money';
import type { Totals } from '@/types/transactions';

interface Props {
    summary: Totals;
}

/**
 * Totals for the current filter set, not the current page, so narrowing the
 * filters immediately answers "how much did this come to".
 */
export function TransactionsSummary({ summary }: Props) {
    const stats = [
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

    return (
        <dl className="grid grid-cols-2 gap-px overflow-hidden rounded-lg border bg-border sm:grid-cols-4">
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
