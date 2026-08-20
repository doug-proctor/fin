import { ChevronDown, SlidersHorizontal, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { FacetedFilter } from '@/components/transactions/faceted-filter';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useDebouncedValue } from '@/hooks/use-transaction-filters';
import type { useTransactionFilters } from '@/hooks/use-transaction-filters';
import { cn } from '@/lib/utils';
import type {
    CategoryOption,
    GroupBy,
    TransactionAccount,
    TransactionDirection,
    TransactionFacets,
    TransactionFilterState,
} from '@/types/transactions';

interface Props {
    filters: TransactionFilterState;
    accounts: TransactionAccount[];
    facets: TransactionFacets;
    categories: CategoryOption[];
    controls: ReturnType<typeof useTransactionFilters>;
}

const DATE_PRESETS: { value: string; label: string }[] = [
    { value: 'all', label: 'All time' },
    { value: 'this_month', label: 'This month' },
    { value: 'last_month', label: 'Last month' },
    { value: 'last_3_months', label: 'Last 3 months' },
    { value: 'this_year', label: 'This year' },
    { value: 'last_year', label: 'Last year' },
    { value: 'custom', label: 'Custom range' },
];

const GROUPS: { value: GroupBy; label: string }[] = [
    { value: 'none', label: 'No grouping' },
    { value: 'day', label: 'Group by day' },
    { value: 'week', label: 'Group by week' },
    { value: 'month', label: 'Group by month' },
    { value: 'category', label: 'Group by category' },
    { value: 'account', label: 'Group by account' },
    { value: 'merchant', label: 'Group by merchant' },
];

const DIRECTIONS: { value: TransactionDirection; label: string }[] = [
    { value: 'all', label: 'In and out' },
    { value: 'in', label: 'Money in' },
    { value: 'out', label: 'Money out' },
];

/**
 * Radix works the selected label out from the item list, which only exists on
 * the client, so a server rendered trigger comes through empty and fills in a
 * beat later. Naming the label here renders it either side.
 */
function labelOf<T extends string>(
    options: { value: T; label: string }[],
    value: T,
): string | undefined {
    return options.find((option) => option.value === value)?.label;
}

export function TransactionsFilterBar({
    filters,
    accounts,
    facets,
    categories,
    controls,
}: Props) {
    const { apply, toggleInArray, reset } = controls;

    const [search, setSearch] = useState(filters.search ?? '');
    const debouncedSearch = useDebouncedValue(search);

    const [panelOpen, setPanelOpen] = useState(false);
    const searchRef = useRef<HTMLInputElement>(null);

    /**
     * Pressing f anywhere jumps to the search box, unless the keystroke was
     * meant for whatever already has focus, or carries a modifier that belongs
     * to the browser such as ctrl+F.
     */
    useEffect(() => {
        function focusSearch(event: KeyboardEvent) {
            if (event.key !== 'f' && event.key !== 'F') {
                return;
            }

            if (event.metaKey || event.ctrlKey || event.altKey) {
                return;
            }

            const target = event.target as HTMLElement | null;

            if (
                target instanceof HTMLInputElement ||
                target instanceof HTMLTextAreaElement ||
                target instanceof HTMLSelectElement ||
                target?.isContentEditable
            ) {
                return;
            }

            event.preventDefault();
            searchRef.current?.focus();
            searchRef.current?.select();
        }

        window.addEventListener('keydown', focusSearch);

        return () => window.removeEventListener('keydown', focusSearch);
    }, []);

    useEffect(() => {
        if ((filters.search ?? '') !== debouncedSearch) {
            apply({ search: debouncedSearch });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedSearch]);

    const datePreset = filters.date_preset ?? 'all';
    const isCustomRange = datePreset === 'custom';
    const direction = filters.direction ?? 'all';
    const groupBy = filters.group_by ?? 'none';

    /**
     * Category options are limited to those actually present, so a filter can
     * never produce an empty table.
     */
    const categoryOptions = categories.filter((category) =>
        facets.categories.includes(category.value),
    );

    const activeFilterCount = [
        filters.accounts?.length,
        filters.categories?.length,
        filters.tags?.length,
        filters.types?.length,
        filters.search ? 1 : 0,
        filters.direction && filters.direction !== 'all' ? 1 : 0,
        filters.amount_min ? 1 : 0,
        filters.amount_max ? 1 : 0,
        filters.unprocessed ? 1 : 0,
        datePreset !== 'all' ? 1 : 0,
    ].reduce<number>((total, value) => total + (value ?? 0), 0);

    return (
        <Collapsible
            open={panelOpen}
            onOpenChange={setPanelOpen}
            className="contents"
        >
            <div className="flex flex-wrap items-center gap-2">
                <div className="relative w-full sm:w-64">
                    <Input
                        ref={searchRef}
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Search..."
                        className="h-9 pr-8"
                        aria-label="Search transactions"
                    />
                    {/* The shortcut is only worth advertising while unused. */}
                    {search === '' && (
                        <kbd
                            aria-hidden
                            className="pointer-events-none absolute top-1/2 right-2 -translate-y-1/2 rounded border px-1.5 font-mono text-[10px] text-muted-foreground"
                        >
                            f
                        </kbd>
                    )}
                </div>

                <CollapsibleTrigger asChild>
                    <Button variant="outline" size="sm">
                        <SlidersHorizontal className="h-4 w-4" />
                        Filters
                        {activeFilterCount > 0 && (
                            <Badge
                                variant="secondary"
                                className="ml-1 rounded-sm px-1 font-normal"
                            >
                                {activeFilterCount}
                            </Badge>
                        )}
                        <ChevronDown
                            className={cn(
                                'h-3.5 w-3.5 opacity-50 transition-transform',
                                panelOpen && 'rotate-180',
                            )}
                        />
                    </Button>
                </CollapsibleTrigger>

                {activeFilterCount > 0 && (
                    <Button variant="ghost" size="sm" onClick={reset}>
                        <X className="h-3.5 w-3.5" />
                        Clear {activeFilterCount}{' '}
                        {activeFilterCount === 1 ? 'filter' : 'filters'}
                    </Button>
                )}
            </div>

            {/*
             * The trigger row sits beside the page title, so the root is
             * display:contents and the panel drops to its own full width line
             * after everything else on that row.
             */}
            <CollapsibleContent className="order-last basis-full space-y-3 rounded-lg border p-3">
                <div className="flex flex-wrap items-center gap-2">
                    <Select
                        value={datePreset}
                        onValueChange={(value) => apply({ date_preset: value })}
                    >
                        <SelectTrigger size="sm" className="w-[150px]">
                            <SelectValue placeholder="Date">
                                {labelOf(DATE_PRESETS, datePreset)}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            {DATE_PRESETS.map((preset) => (
                                <SelectItem
                                    key={preset.value}
                                    value={preset.value}
                                >
                                    {preset.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    {isCustomRange && (
                        <div className="flex items-center gap-2">
                            <Input
                                type="date"
                                value={filters.date_from ?? ''}
                                onChange={(event) =>
                                    apply({ date_from: event.target.value })
                                }
                                className="h-9 w-[150px]"
                                aria-label="From date"
                            />
                            <span className="text-sm text-muted-foreground">
                                to
                            </span>
                            <Input
                                type="date"
                                value={filters.date_to ?? ''}
                                onChange={(event) =>
                                    apply({ date_to: event.target.value })
                                }
                                className="h-9 w-[150px]"
                                aria-label="To date"
                            />
                        </div>
                    )}

                    <FacetedFilter
                        label="Account"
                        options={accounts.map((account) => ({
                            value: account.id,
                            label: account.name,
                        }))}
                        selected={filters.accounts ?? []}
                        onToggle={(value) => toggleInArray('accounts', value)}
                        onClear={() => apply({ accounts: [] })}
                    />

                    <FacetedFilter
                        label="Category"
                        options={categoryOptions}
                        selected={filters.categories ?? []}
                        onToggle={(value) => toggleInArray('categories', value)}
                        onClear={() => apply({ categories: [] })}
                    />

                    <FacetedFilter
                        label="Tag"
                        options={facets.tags.map((tag) => ({
                            value: tag,
                            label: `#${tag}`,
                        }))}
                        selected={filters.tags ?? []}
                        onToggle={(value) => toggleInArray('tags', value)}
                        onClear={() => apply({ tags: [] })}
                    />

                    <FacetedFilter
                        label="Type"
                        options={facets.types.map((type) => ({
                            value: type,
                            label: type.replaceAll('_', ' '),
                        }))}
                        selected={filters.types ?? []}
                        onToggle={(value) => toggleInArray('types', value)}
                        onClear={() => apply({ types: [] })}
                    />

                    <Select
                        value={direction}
                        onValueChange={(value) =>
                            apply({ direction: value as TransactionDirection })
                        }
                    >
                        <SelectTrigger size="sm" className="w-[130px]">
                            <SelectValue>
                                {labelOf(DIRECTIONS, direction)}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            {DIRECTIONS.map((option) => (
                                <SelectItem
                                    key={option.value}
                                    value={option.value}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={groupBy}
                        onValueChange={(value) =>
                            apply({ group_by: value as GroupBy })
                        }
                    >
                        <SelectTrigger size="sm" className="w-[150px]">
                            <SelectValue>
                                {labelOf(GROUPS, groupBy)}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            {GROUPS.map((group) => (
                                <SelectItem
                                    key={group.value}
                                    value={group.value}
                                >
                                    {group.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <div className="flex items-center gap-2">
                        <Label
                            htmlFor="amount-min"
                            className="text-xs text-muted-foreground"
                        >
                            Amount £
                        </Label>
                        <Input
                            id="amount-min"
                            type="number"
                            inputMode="decimal"
                            step="0.01"
                            min="0"
                            placeholder="min"
                            defaultValue={filters.amount_min ?? ''}
                            onBlur={(event) =>
                                apply({ amount_min: event.target.value })
                            }
                            className="h-8 w-24"
                        />
                        <span className="text-sm text-muted-foreground">
                            to
                        </span>
                        <Input
                            type="number"
                            inputMode="decimal"
                            step="0.01"
                            min="0"
                            placeholder="max"
                            defaultValue={filters.amount_max ?? ''}
                            onBlur={(event) =>
                                apply({ amount_max: event.target.value })
                            }
                            className="h-8 w-24"
                            aria-label="Maximum amount"
                        />
                    </div>

                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox
                            checked={filters.unprocessed === true}
                            onCheckedChange={(checked) =>
                                apply({ unprocessed: checked === true })
                            }
                        />
                        Show unprocessed only
                    </label>
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}
