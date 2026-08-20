import { Form, Head, router } from '@inertiajs/react';
import { Plus, StopCircle, Trash2, Wand2 } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatMoney, parseMoneyToMinor } from '@/lib/money';
import {
    apply,
    destroy,
    index as rulesIndex,
    store,
} from '@/routes/category-rules';
import type { CategoryOption } from '@/types/transactions';

interface Rule {
    id: number;
    name: string;
    matchField: string;
    matchType: string;
    matchValue: string;
    accountId: number | null;
    amountMinMinor: number | null;
    amountMaxMinor: number | null;
    amountMinor: number | null;
    dayOfMonth: number | null;
    setCategory: string | null;
    setCategoryLabel: string | null;
    setTags: string[];
    priority: number;
    stopsProcessing: boolean;
    isActive: boolean;
    matchCount: number;
}

interface Props {
    rules: Rule[];
    accounts: { id: number; name: string }[];
    categories: CategoryOption[];
    matchFields: string[];
    matchTypes: string[];
    uncategorisedCount: number;
    recategorisableCount: number;
}

const FIELD_LABELS: Record<string, string> = {
    any: 'Name, description or merchant',
    name: 'Name',
    description: 'Description',
    merchant_name: 'Merchant',
};

const TYPE_LABELS: Record<string, string> = {
    contains: 'contains',
    equals: 'is exactly',
    starts_with: 'starts with',
    regex: 'matches regex',
};

/**
 * 31 whatever the month. A rule pinned to the 31st simply never fires in
 * February, which is the same answer the user would get from a date picker.
 */
const DAYS_OF_MONTH = Array.from({ length: 31 }, (_, index) => index + 1);

function ordinal(day: number): string {
    const suffix =
        day % 100 >= 11 && day % 100 <= 13
            ? 'th'
            : ({ 1: 'st', 2: 'nd', 3: 'rd' }[day % 10] ?? 'th');

    return `${day}${suffix}`;
}

/**
 * Either end of the range is optional, so the wording has to cover a rule
 * bounded on one side as readably as one bounded on both.
 */
function amountBound(min: number | null, max: number | null): string {
    const money = (value: number) =>
        formatMoney(value, 'GBP', { signed: true });

    if (min !== null && max !== null) {
        return `${money(min)} to ${money(max)}`;
    }

    return min !== null
        ? `${money(min)} or more`
        : `${money(max as number)} or less`;
}

export default function CategoryRules({
    rules,
    accounts,
    categories,
    matchFields,
    matchTypes,
    uncategorisedCount,
    recategorisableCount,
}: Props) {
    const [showForm, setShowForm] = useState(rules.length === 0);
    const [confirmingReapply, setConfirmingReapply] = useState(false);
    const [rulePendingDeletion, setRulePendingDeletion] = useState<Rule | null>(
        null,
    );
    const accountNames = new Map(accounts.map((a) => [a.id, a.name]));

    return (
        <>
            <Head title="Rules" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Rules"
                    description="Give matching transactions a category, whichever account they came from"
                />

                <div className="flex flex-wrap items-center gap-2">
                    <Button onClick={() => setShowForm((open) => !open)}>
                        <Plus className="h-4 w-4" />
                        New rule
                    </Button>

                    <Button
                        variant="secondary"
                        onClick={() =>
                            router.post(
                                apply.url(),
                                { only_uncategorised: true },
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Wand2 className="h-4 w-4" />
                        Apply to {uncategorisedCount.toLocaleString()}{' '}
                        uncategorised
                    </Button>

                    {/*
                     * Re-applying overwrites categories that are already set,
                     * so it asks first. Applying to uncategorised rows only
                     * fills in blanks and needs no confirmation.
                     */}
                    <Dialog
                        open={confirmingReapply}
                        onOpenChange={setConfirmingReapply}
                    >
                        <DialogTrigger asChild>
                            <Button variant="outline">
                                Re-apply to everything
                            </Button>
                        </DialogTrigger>

                        <DialogContent>
                            <DialogTitle>
                                Re-apply your rules to every transaction?
                            </DialogTitle>
                            <DialogDescription asChild>
                                <div className="space-y-2">
                                    <p>
                                        This re-runs every active rule over{' '}
                                        <strong>
                                            {recategorisableCount.toLocaleString()}
                                        </strong>{' '}
                                        {recategorisableCount === 1
                                            ? 'transaction'
                                            : 'transactions'}
                                        , overwriting categories previously set
                                        by a rule or by the bank. It cannot be
                                        undone.
                                    </p>
                                    <p>
                                        Categories you set by hand are left
                                        alone.
                                    </p>
                                </div>
                            </DialogDescription>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>

                                <Button
                                    variant="destructive"
                                    data-dialog-autofocus
                                    onClick={() =>
                                        router.post(
                                            apply.url(),
                                            { only_uncategorised: false },
                                            {
                                                preserveScroll: true,
                                                onFinish: () =>
                                                    setConfirmingReapply(false),
                                            },
                                        )
                                    }
                                >
                                    Re-apply to everything
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>

                {showForm && (
                    <Form
                        {...store.form()}
                        options={{ preserveScroll: true }}
                        /*
                         * The amount is typed in pounds but stored in signed
                         * minor units, and a blank optional field has to reach
                         * the server as null rather than as an empty string
                         * the integer and date rules would both reject.
                         */
                        transform={(data) => {
                            const { amount, amount_min, amount_max, ...rest } =
                                data as Record<string, string>;

                            return {
                                ...rest,
                                amount_min_minor: parseMoneyToMinor(amount_min),
                                amount_max_minor: parseMoneyToMinor(amount_max),
                                amount_minor: parseMoneyToMinor(amount),
                                day_of_month:
                                    rest.day_of_month === '' ||
                                    rest.day_of_month === undefined
                                        ? null
                                        : Number(rest.day_of_month),
                            };
                        }}
                        onSuccess={() => setShowForm(false)}
                        className="grid max-w-3xl gap-4 rounded-lg border p-4 sm:grid-cols-2"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="space-y-2 sm:col-span-2">
                                    <Label htmlFor="name">Rule name</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        placeholder="Supermarkets"
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-4 sm:col-span-2 sm:grid-cols-3">
                                    <div className="space-y-2">
                                        <Label htmlFor="match_field">
                                            Look at
                                        </Label>
                                        <Select
                                            name="match_field"
                                            defaultValue="any"
                                        >
                                            <SelectTrigger id="match_field">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {matchFields.map((field) => (
                                                    <SelectItem
                                                        key={field}
                                                        value={field}
                                                    >
                                                        {FIELD_LABELS[field] ??
                                                            field}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="match_type">
                                            Which
                                        </Label>
                                        <Select
                                            name="match_type"
                                            defaultValue="contains"
                                        >
                                            <SelectTrigger id="match_type">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {matchTypes.map((type) => (
                                                    <SelectItem
                                                        key={type}
                                                        value={type}
                                                    >
                                                        {TYPE_LABELS[type] ??
                                                            type}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="match_value">
                                            This text
                                        </Label>
                                        <Input
                                            id="match_value"
                                            name="match_value"
                                            placeholder="tesco"
                                            required
                                        />
                                        <InputError
                                            message={errors.match_value}
                                        />
                                    </div>
                                </div>

                                {/*
                                 * All four optional. Left blank the rule
                                 * matches on its text alone, which is how
                                 * every rule written before these existed
                                 * behaves. An exact amount and a range are
                                 * refused together, because a rule cannot
                                 * sensibly be both.
                                 */}
                                <div className="space-y-2">
                                    <Label htmlFor="amount_min_minor">
                                        At least
                                    </Label>
                                    <Input
                                        id="amount_min_minor"
                                        name="amount_min"
                                        type="number"
                                        step="0.01"
                                        placeholder="No lower bound"
                                    />
                                    <InputError
                                        message={errors.amount_min_minor}
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="amount_max_minor">
                                        At most
                                    </Label>
                                    <Input
                                        id="amount_max_minor"
                                        name="amount_max"
                                        type="number"
                                        step="0.01"
                                        placeholder="No upper bound"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Money out is negative, so −50 to −10 is
                                        a spend between £10 and £50.
                                    </p>
                                    <InputError
                                        message={errors.amount_max_minor}
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="amount_minor">
                                        Only this amount
                                    </Label>
                                    <Input
                                        id="amount_minor"
                                        name="amount"
                                        type="number"
                                        step="0.01"
                                        placeholder="Any amount"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Negative for money out, so −9.99 is a
                                        charge.
                                    </p>
                                    <InputError message={errors.amount_minor} />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="day_of_month">
                                        Only this day of the month
                                    </Label>
                                    <Select name="day_of_month">
                                        <SelectTrigger id="day_of_month">
                                            <SelectValue placeholder="Any day" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {DAYS_OF_MONTH.map((day) => (
                                                <SelectItem
                                                    key={day}
                                                    value={String(day)}
                                                >
                                                    {ordinal(day)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.day_of_month} />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="set_category">
                                        Set category
                                    </Label>
                                    <Select name="set_category">
                                        <SelectTrigger id="set_category">
                                            <SelectValue placeholder="Choose…" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {categories.map((category) => (
                                                <SelectItem
                                                    key={category.value}
                                                    value={category.value}
                                                >
                                                    {category.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.set_category} />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="priority">
                                        Priority (higher runs first)
                                    </Label>
                                    <Input
                                        id="priority"
                                        name="priority"
                                        type="number"
                                        defaultValue={0}
                                    />
                                </div>

                                <div className="space-y-2 sm:col-span-2">
                                    <Label htmlFor="account_id">
                                        Limit to account
                                    </Label>
                                    <Select name="account_id">
                                        <SelectTrigger id="account_id">
                                            <SelectValue placeholder="All accounts" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {accounts.map((account) => (
                                                <SelectItem
                                                    key={account.id}
                                                    value={String(account.id)}
                                                >
                                                    {account.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <label className="flex items-center gap-2 text-sm sm:col-span-2">
                                    <Checkbox
                                        name="stops_processing"
                                        defaultChecked
                                        value="1"
                                    />
                                    Stop checking further rules once this one
                                    matches
                                </label>

                                <input
                                    type="hidden"
                                    name="is_active"
                                    value="1"
                                />

                                <div className="flex gap-2 sm:col-span-2">
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        Save rule
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => setShowForm(false)}
                                    >
                                        Cancel
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                )}

                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Rule</TableHead>
                                <TableHead>Matches</TableHead>
                                <TableHead>Applies</TableHead>
                                <TableHead>Scope</TableHead>
                                <TableHead className="text-right">
                                    Transactions
                                </TableHead>
                                <TableHead className="text-right">
                                    Priority
                                </TableHead>
                                <TableHead className="w-10" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rules.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={7}
                                        className="py-12 text-center text-muted-foreground"
                                    >
                                        No rules yet. Add one to start
                                        categorising automatically.
                                    </TableCell>
                                </TableRow>
                            )}

                            {rules.map((rule) => (
                                <TableRow
                                    key={rule.id}
                                    className={
                                        rule.isActive ? undefined : 'opacity-50'
                                    }
                                >
                                    <TableCell className="font-medium">
                                        {rule.name}
                                        {!rule.isActive && (
                                            <Badge
                                                variant="outline"
                                                className="ml-2"
                                            >
                                                Off
                                            </Badge>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-xs text-muted-foreground">
                                        {FIELD_LABELS[rule.matchField] ??
                                            rule.matchField}{' '}
                                        {TYPE_LABELS[rule.matchType] ??
                                            rule.matchType}{' '}
                                        <code className="text-foreground">
                                            {rule.matchValue}
                                        </code>
                                        {rule.amountMinor !== null && (
                                            <span className="block">
                                                only{' '}
                                                <span className="text-foreground tabular-nums">
                                                    {formatMoney(
                                                        rule.amountMinor,
                                                        'GBP',
                                                        { signed: true },
                                                    )}
                                                </span>
                                            </span>
                                        )}
                                        {(rule.amountMinMinor !== null ||
                                            rule.amountMaxMinor !== null) && (
                                            <span className="block">
                                                only{' '}
                                                <span className="text-foreground tabular-nums">
                                                    {amountBound(
                                                        rule.amountMinMinor,
                                                        rule.amountMaxMinor,
                                                    )}
                                                </span>
                                            </span>
                                        )}
                                        {rule.dayOfMonth !== null && (
                                            <span className="block">
                                                only on the{' '}
                                                <span className="text-foreground">
                                                    {ordinal(rule.dayOfMonth)}
                                                </span>{' '}
                                                of the month
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {rule.setCategoryLabel && (
                                            <Badge variant="secondary">
                                                {rule.setCategoryLabel}
                                            </Badge>
                                        )}
                                        {rule.setTags.map((tag) => (
                                            <Badge
                                                key={tag}
                                                variant="outline"
                                                className="ml-1"
                                            >
                                                #{tag}
                                            </Badge>
                                        ))}
                                    </TableCell>
                                    <TableCell className="text-xs text-muted-foreground">
                                        {rule.accountId
                                            ? (accountNames.get(
                                                  rule.accountId,
                                              ) ?? 'Unknown')
                                            : 'All accounts'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        <span
                                            className={
                                                rule.matchCount === 0
                                                    ? 'text-muted-foreground'
                                                    : undefined
                                            }
                                        >
                                            {rule.matchCount}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        <span className="inline-flex items-center justify-end gap-1">
                                            {rule.priority}
                                            {rule.stopsProcessing && (
                                                <StopCircle
                                                    className="h-3.5 w-3.5 text-muted-foreground"
                                                    aria-label="Stops further rules"
                                                />
                                            )}
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Delete ${rule.name}`}
                                            onClick={() =>
                                                setRulePendingDeletion(rule)
                                            }
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                {/*
                 * One dialog for the table, told which rule it is confirming,
                 * rather than an instance rendered per row.
                 */}
                <Dialog
                    open={rulePendingDeletion !== null}
                    onOpenChange={(open) =>
                        setRulePendingDeletion(
                            open ? rulePendingDeletion : null,
                        )
                    }
                >
                    <DialogContent>
                        <DialogTitle>
                            Delete the &ldquo;{rulePendingDeletion?.name}&rdquo;
                            rule?
                        </DialogTitle>
                        <DialogDescription asChild>
                            <div className="space-y-2">
                                <p>
                                    It will stop categorising new transactions
                                    as they arrive. This cannot be undone.
                                </p>
                                <p>
                                    Transactions it has already categorised keep
                                    their category.
                                </p>
                            </div>
                        </DialogDescription>

                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary">Cancel</Button>
                            </DialogClose>

                            <Button
                                variant="destructive"
                                data-dialog-autofocus
                                onClick={() => {
                                    if (rulePendingDeletion === null) {
                                        return;
                                    }

                                    router.delete(
                                        destroy.url(rulePendingDeletion.id),
                                        {
                                            preserveScroll: true,
                                            onFinish: () =>
                                                setRulePendingDeletion(null),
                                        },
                                    );
                                }}
                            >
                                Delete rule
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </>
    );
}

CategoryRules.layout = {
    breadcrumbs: [{ title: 'Rules', href: rulesIndex() }],
};
