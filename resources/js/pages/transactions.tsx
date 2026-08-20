import { Form, Head } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { ImportAmexDialog } from '@/components/transactions/import-amex-dialog';
import type { ImportResult } from '@/components/transactions/import-amex-dialog';
import { MarkMonthProcessedDialog } from '@/components/transactions/mark-month-processed-dialog';
import { TransactionEditDialog } from '@/components/transactions/transaction-edit-dialog';
import { TransactionsFilterBar } from '@/components/transactions/transactions-filter-bar';
import type { MonthNav } from '@/components/transactions/transactions-month-nav';
import { TransactionsMonthNav } from '@/components/transactions/transactions-month-nav';
import { TransactionsSummary } from '@/components/transactions/transactions-summary';
import { TransactionsTable } from '@/components/transactions/transactions-table';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTransactionFilters } from '@/hooks/use-transaction-filters';
import { cn } from '@/lib/utils';
import { sync as syncMonzo } from '@/routes/monzo';
import { index as transactionsIndex } from '@/routes/transactions';
import type {
    CategoryOption,
    GroupBy,
    SortKey,
    Totals,
    TransactionAccount,
    TransactionFacets,
    TransactionFilterState,
    TransactionRow,
} from '@/types/transactions';

interface Props {
    transactions: TransactionRow[];
    month: MonthNav;
    /** Rows in the month on screen not marked off yet, ignoring the filters. */
    unprocessedCount: number;
    summary: Totals;
    subtotals: Record<string, Totals>;
    filters: TransactionFilterState;
    facets: TransactionFacets;
    accounts: TransactionAccount[];
    monzoConnected: boolean;
    importResult: ImportResult | null;
    options: {
        categories: CategoryOption[];
    };
}

export default function TransactionsIndex({
    transactions,
    month,
    unprocessedCount,
    summary,
    subtotals,
    filters,
    facets,
    accounts,
    monzoConnected,
    importResult,
    options,
}: Props) {
    const controls = useTransactionFilters(filters);
    const { apply, pending } = controls;

    /**
     * The row being edited, held by id rather than by value so the dialog
     * always reads the freshest row once a save has reloaded the table.
     */
    const [editingId, setEditingId] = useState<number | null>(null);
    const editing =
        transactions.find((transaction) => transaction.id === editingId) ??
        null;

    const sort = filters.sort ?? 'date';
    const sortDirection = filters.sort_direction ?? 'desc';

    /**
     * Clicking the active column flips it; clicking another column starts it
     * in the direction that is most useful for that column.
     */
    function handleSort(key: SortKey) {
        if (key === sort) {
            apply({ sort_direction: sortDirection === 'asc' ? 'desc' : 'asc' });

            return;
        }

        apply({
            sort: key,
            sort_direction:
                key === 'name' || key === 'category' ? 'asc' : 'desc',
        });
    }

    return (
        <>
            <Head title="Transactions" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center gap-2">
                    <h1 className="text-xl font-semibold">Transactions</h1>

                    <TransactionsFilterBar
                        filters={filters}
                        accounts={accounts}
                        facets={facets}
                        categories={options.categories}
                        controls={controls}
                    />

                    <div className="ml-auto flex flex-wrap gap-2">
                        <MarkMonthProcessedDialog
                            month={month}
                            unprocessedCount={unprocessedCount}
                        />

                        {monzoConnected && (
                            <Form
                                {...syncMonzo.form()}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        {processing ? (
                                            <Spinner />
                                        ) : (
                                            <RefreshCw className="h-4 w-4" />
                                        )}
                                        Import Monzo
                                    </Button>
                                )}
                            </Form>
                        )}

                        <ImportAmexDialog result={importResult} />
                    </div>
                </div>

                <TransactionsSummary summary={summary} />

                <TransactionsMonthNav
                    month={month}
                    onChange={(value) => apply({ month: value })}
                />

                <div
                    className={cn(
                        'rounded-lg border transition-opacity',
                        pending && 'opacity-60',
                    )}
                    aria-busy={pending}
                >
                    <TransactionsTable
                        transactions={transactions}
                        subtotals={subtotals}
                        groupBy={(filters.group_by ?? 'none') as GroupBy}
                        sort={sort}
                        sortDirection={sortDirection}
                        accounts={accounts}
                        onSort={handleSort}
                        onEdit={(transaction) => setEditingId(transaction.id)}
                    />
                </div>
            </div>

            <TransactionEditDialog
                transaction={editing}
                categories={options.categories}
                tags={facets.tags}
                onClose={() => setEditingId(null)}
            />
        </>
    );
}

TransactionsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Transactions',
            href: transactionsIndex(),
        },
    ],
};
