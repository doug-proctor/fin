export interface CategoryRuleAccount {
    id: number;
    name: string;
}

export interface CategoryRuleRow {
    id: number;
    name: string;
    matchField: string;
    matchType: string;
    /** The strings the rule looks for; it matches when any one of them does. */
    matchValues: string[];
    accountId: number | null;
    amountMinMinor: number | null;
    amountMaxMinor: number | null;
    amountMinor: number | null;
    dayOfMonth: number | null;
    setCategory: string | null;
    setCategoryLabel: string | null;
    setName: string | null;
    setTags: string[];
    priority: number;
    stopsProcessing: boolean;
    isActive: boolean;
    /** How many stored transactions the rule's conditions select. */
    matchCount: number;
}
