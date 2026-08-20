export type TransactionDirection = 'all' | 'in' | 'out';
export type GroupBy =
    'none' | 'day' | 'week' | 'month' | 'category' | 'account' | 'merchant';
export type SortKey =
    'date' | 'amount' | 'name' | 'category' | 'account' | 'added';
export type SortDirection = 'asc' | 'desc';

export interface TransactionRow {
    id: number;
    bookedAt: string;
    /**
     * The date this row reads as in the month on screen: its booked date,
     * unless it travelled here from another month.
     */
    displayDate: string;
    /** 'YYYY-MM-DD', or null when the row counts in the month it was booked. */
    accountingDate: string | null;
    /**
     * 'ghost' when the row was booked in the month on screen but counts in
     * another, 'arrival' when it counts here but was booked elsewhere.
     */
    timeTravel: 'ghost' | 'arrival' | null;
    name: string | null;
    description: string | null;
    category: string | null;
    categoryLabel: string | null;
    amountMinor: number;
    moneyInMinor: number;
    moneyOutMinor: number;
    currency: string;
    type: string | null;
    merchantName: string | null;
    notes: string | null;
    tags: string[];
    accountId: number;
    accountName: string | null;
    accountProvider: string | null;
    categorisedBy: string | null;
    /** False until the user has reviewed the row and marked it off. */
    processed: boolean;
    /** True when the row's value is deliberately left out of every total. */
    excludedFromTotals: boolean;
    overriddenFields: string[];
    groupKey: string | null;
}

export interface Totals {
    count: number;
    moneyIn: number;
    moneyOut: number;
    net: number;
}

export interface TransactionAccount {
    id: number;
    name: string;
    provider: string;
}

export interface CategoryOption {
    value: string;
    label: string;
}

export interface TransactionFacets {
    categories: string[];
    types: string[];
    tags: string[];
}

/**
 * The filter state, mirrored between the URL query string and the controls.
 * Every key is optional because defaults are omitted from the URL.
 */
export interface TransactionFilterState {
    date_preset?: string;
    date_from?: string;
    date_to?: string;
    accounts?: number[];
    categories?: string[];
    direction?: TransactionDirection;
    amount_min?: string;
    amount_max?: string;
    search?: string;
    tags?: string[];
    types?: string[];
    unprocessed?: boolean;
    sort?: SortKey;
    sort_direction?: SortDirection;
    group_by?: GroupBy;
    month?: string;
}
