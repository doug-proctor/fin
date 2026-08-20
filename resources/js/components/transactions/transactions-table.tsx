import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-react';
import { Fragment } from 'react';
import { AccountProviderIcon } from '@/components/transactions/account-provider-icon';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate, formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import type {
    GroupBy,
    SortDirection,
    SortKey,
    Totals,
    TransactionAccount,
    TransactionRow,
} from '@/types/transactions';

interface Props {
    transactions: TransactionRow[];
    subtotals: Record<string, Totals>;
    groupBy: GroupBy;
    sort: SortKey;
    sortDirection: SortDirection;
    accounts: TransactionAccount[];
    onSort: (key: SortKey) => void;
    onEdit: (transaction: TransactionRow) => void;
}

const COLUMNS: { key: SortKey | null; label: string; align?: 'right' }[] = [
    { key: 'account', label: 'Account' },
    { key: 'date', label: 'Date' },
    { key: 'name', label: 'Name' },
    { key: null, label: 'Description' },
    { key: 'category', label: 'Category' },
    { key: 'amount', label: 'In / out', align: 'right' },
    { key: null, label: 'Notes' },
    { key: null, label: 'Tags' },
];

/**
 * A group header labels everything left of the money column, puts the group
 * total in the money column, then fills whatever sits to its right. Derived
 * from the column list, so reordering cannot leave the header misaligned.
 */
const MONEY_COLUMN = COLUMNS.findIndex((column) => column.key === 'amount');
const COLUMNS_BEFORE_MONEY = MONEY_COLUMN;
const COLUMNS_AFTER_MONEY = COLUMNS.length - MONEY_COLUMN - 1;

/**
 * A row that counts towards a different month is marked in the month it
 * counts in, so it can be told apart from the ones that were actually booked
 * there. In the month it was booked it is greyed out instead.
 */
const TIME_TRAVELLER = '\u{1F47D}';

/**
 * The month an accounting date falls in, written out.
 *
 * The T00:00:00 matters: a bare 'YYYY-MM-DD' parses as UTC midnight and reads
 * as the month before west of Greenwich, which is exactly the boundary this
 * is describing.
 */
function accountingMonthLabel(accountingDate: string): string {
    return new Date(`${accountingDate}T00:00:00`).toLocaleDateString(
        undefined,
        {
            month: 'long',
            year: 'numeric',
        },
    );
}

/**
 * A value the user has corrected by hand is marked, because a sync will now
 * leave it alone and that is worth being able to see at a glance.
 */
function CellText({
    value,
    isOverridden,
    placeholder = '\u2014',
    className,
}: {
    value: string | null;
    isOverridden: boolean;
    placeholder?: string;
    className?: string;
}) {
    return (
        <span
            title={
                isOverridden
                    ? 'Edited by you \u2014 syncs will not change it'
                    : undefined
            }
            className={cn(
                'block',
                isOverridden &&
                    'underline decoration-primary/60 decoration-dotted',
                className,
            )}
        >
            {value || (
                <span className="text-muted-foreground">{placeholder}</span>
            )}
        </span>
    );
}

/**
 * Renders one page of rows. When grouping is on the rows arrive already
 * ordered by their group, so a header is inserted wherever the group key
 * changes.
 */
export function TransactionsTable({
    transactions,
    subtotals,
    groupBy,
    sort,
    sortDirection,
    accounts,
    onSort,
    onEdit,
}: Props) {
    const isGrouped = groupBy !== 'none';

    const accountNames = new Map(
        accounts.map((account) => [String(account.id), account.name]),
    );

    function groupLabel(key: string): string {
        if (key === '') {
            return 'Uncategorised';
        }

        switch (groupBy) {
            case 'account':
                return accountNames.get(key) ?? 'Unknown account';
            case 'day':
                return formatDate(key);
            case 'month': {
                const [year, month] = key.split('-');

                return new Date(
                    Number(year),
                    Number(month) - 1,
                    1,
                ).toLocaleDateString(undefined, {
                    month: 'long',
                    year: 'numeric',
                });
            }
            case 'category':
                return (
                    transactions.find((row) => row.groupKey === key)
                        ?.categoryLabel ?? key
                );
            default:
                return key;
        }
    }

    /**
     * Group boundaries are worked out up front rather than by mutating a
     * cursor while rendering, which the React compiler rightly rejects.
     */
    const rows = transactions.map((transaction, index) => ({
        transaction,
        startsGroup:
            isGrouped &&
            transaction.groupKey !== null &&
            transaction.groupKey !== transactions[index - 1]?.groupKey,
        /** Guarded on the name, so a nameless row keeps its placeholder. */
        displayName:
            transaction.timeTravel === 'arrival' && transaction.name
                ? `${TIME_TRAVELLER} ${transaction.name}`
                : transaction.name,
        travelNote:
            transaction.accountingDate === null
                ? undefined
                : transaction.timeTravel === 'ghost'
                  ? `Counted in ${accountingMonthLabel(transaction.accountingDate)} \u2014 not in this month's totals`
                  : transaction.timeTravel === 'arrival'
                    ? `Booked ${formatDate(transaction.bookedAt)} \u2014 counted in this month`
                    : undefined,
    }));

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    {COLUMNS.map((column) => (
                        <TableHead
                            key={column.label}
                            className={cn(
                                column.align === 'right' && 'text-right',
                            )}
                        >
                            {column.key ? (
                                <button
                                    type="button"
                                    onClick={() =>
                                        onSort(column.key as SortKey)
                                    }
                                    className="inline-flex items-center gap-1 hover:text-foreground"
                                >
                                    {column.label}
                                    {sort === column.key ? (
                                        sortDirection === 'asc' ? (
                                            <ArrowUp className="h-3 w-3" />
                                        ) : (
                                            <ArrowDown className="h-3 w-3" />
                                        )
                                    ) : (
                                        <ChevronsUpDown className="h-3 w-3 opacity-40" />
                                    )}
                                </button>
                            ) : (
                                column.label
                            )}
                        </TableHead>
                    ))}
                </TableRow>
            </TableHeader>

            <TableBody>
                {transactions.length === 0 && (
                    <TableRow>
                        <TableCell
                            colSpan={COLUMNS.length}
                            className="py-12 text-center text-muted-foreground"
                        >
                            No transactions match these filters.
                        </TableCell>
                    </TableRow>
                )}

                {rows.map(
                    ({ transaction, startsGroup, displayName, travelNote }) => {
                        const key = transaction.groupKey;
                        const subtotal = key ? subtotals[key] : undefined;

                        return (
                            <Fragment key={transaction.id}>
                                {startsGroup && key !== null && (
                                    <TableRow className="bg-muted/50 hover:bg-muted/50">
                                        <TableCell
                                            colSpan={COLUMNS_BEFORE_MONEY}
                                            className="font-medium"
                                        >
                                            {groupLabel(key)}
                                            {subtotal && (
                                                <span className="ml-2 font-normal text-muted-foreground">
                                                    {subtotal.count.toLocaleString()}{' '}
                                                    {subtotal.count === 1
                                                        ? 'transaction'
                                                        : 'transactions'}
                                                </span>
                                            )}
                                        </TableCell>

                                        <TableCell
                                            className={cn(
                                                'text-right font-medium tabular-nums',
                                                subtotal &&
                                                    subtotal.net < 0 &&
                                                    'text-rose-600 dark:text-rose-400',
                                                subtotal &&
                                                    subtotal.net > 0 &&
                                                    'text-emerald-600 dark:text-emerald-400',
                                            )}
                                        >
                                            {subtotal
                                                ? formatMoney(
                                                      subtotal.net,
                                                      'GBP',
                                                      {
                                                          signed: true,
                                                      },
                                                  )
                                                : null}
                                        </TableCell>

                                        {Array.from({
                                            length: COLUMNS_AFTER_MONEY,
                                        }).map((_, index) => (
                                            <TableCell key={index} />
                                        ))}
                                    </TableRow>
                                )}

                                {/*
                                 * The whole row opens the edit dialog, but a click
                                 * handler on a <tr> is invisible to a keyboard, and
                                 * giving the row a button role would cost the table
                                 * its semantics. So the mouse gets the row and the
                                 * keyboard gets the real button in the name cell.
                                 */}
                                <TableRow
                                    onClick={() => onEdit(transaction)}
                                    title={travelNote}
                                    className={cn(
                                        'cursor-pointer',
                                        /**
                                         * Booked here but counted elsewhere, so it
                                         * is listed for context only and fades to
                                         * say it is no part of the figures.
                                         */
                                        transaction.timeTravel === 'ghost' &&
                                            'opacity-50',
                                    )}
                                >
                                    <TableCell>
                                        <AccountProviderIcon
                                            provider={
                                                transaction.accountProvider
                                            }
                                            accountName={
                                                transaction.accountName
                                            }
                                        />
                                    </TableCell>

                                    <TableCell className="whitespace-nowrap text-muted-foreground">
                                        <CellText
                                            value={formatDate(
                                                transaction.displayDate,
                                            )}
                                            isOverridden={transaction.overriddenFields.includes(
                                                'booked_at',
                                            )}
                                        />
                                    </TableCell>

                                    <TableCell className="max-w-[16rem]">
                                        <button
                                            type="button"
                                            onClick={() => onEdit(transaction)}
                                            aria-label={`Edit ${transaction.name ?? 'transaction'}`}
                                            className="w-full rounded text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        >
                                            <CellText
                                                value={displayName}
                                                isOverridden={transaction.overriddenFields.includes(
                                                    'name',
                                                )}
                                                className="truncate font-medium"
                                            />
                                        </button>
                                    </TableCell>

                                    <TableCell className="max-w-[18rem]">
                                        <CellText
                                            value={transaction.description}
                                            isOverridden={transaction.overriddenFields.includes(
                                                'description',
                                            )}
                                            className="truncate text-muted-foreground"
                                        />
                                    </TableCell>

                                    <TableCell className="max-w-[10rem]">
                                        <span
                                            className="block truncate"
                                            title={
                                                transaction.categorisedBy ===
                                                'rule'
                                                    ? 'Set by one of your category rules'
                                                    : transaction.categorisedBy ===
                                                        'user'
                                                      ? 'Set by you \u2014 syncs will not change it'
                                                      : undefined
                                            }
                                        >
                                            {transaction.categoryLabel ?? (
                                                <span className="text-muted-foreground">
                                                    Uncategorised
                                                </span>
                                            )}
                                        </span>
                                    </TableCell>

                                    {/*
                                     * One signed figure rather than two columns:
                                     * money out reads negative and red, money in
                                     * positive and green. An amount the totals
                                     * leave out drops the colour instead, so it
                                     * is visibly no part of the sums above it.
                                     */}
                                    <TableCell
                                        title={
                                            transaction.excludedFromTotals
                                                ? 'Transfer \u2014 not counted in any total'
                                                : undefined
                                        }
                                        className={cn(
                                            'text-right tabular-nums',
                                            transaction.excludedFromTotals ||
                                                transaction.timeTravel ===
                                                    'ghost'
                                                ? 'text-muted-foreground/50'
                                                : transaction.amountMinor < 0
                                                  ? 'text-rose-600 dark:text-rose-400'
                                                  : 'text-emerald-600 dark:text-emerald-400',
                                        )}
                                    >
                                        {formatMoney(
                                            transaction.amountMinor,
                                            transaction.currency,
                                            { signed: true },
                                        )}
                                    </TableCell>

                                    <TableCell className="max-w-[16rem]">
                                        <CellText
                                            value={transaction.notes}
                                            isOverridden={transaction.overriddenFields.includes(
                                                'notes',
                                            )}
                                            className="truncate"
                                        />
                                    </TableCell>

                                    {/*
                                     * Tags follow the note rather than standing on
                                     * their own: they are the "#tag" words inside
                                     * it, rewritten whenever it is edited.
                                     */}
                                    <TableCell className="max-w-[12rem]">
                                        {transaction.tags.length > 0 && (
                                            <div className="flex flex-wrap gap-1">
                                                {transaction.tags.map((tag) => (
                                                    <Badge
                                                        key={tag}
                                                        variant="outline"
                                                        className="px-1 py-0 text-sm"
                                                    >
                                                        #{tag}
                                                    </Badge>
                                                ))}
                                            </div>
                                        )}
                                    </TableCell>
                                </TableRow>
                            </Fragment>
                        );
                    },
                )}
            </TableBody>
        </Table>
    );
}
