import { Head, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, StopCircle, Trash2 } from 'lucide-react';
import { Fragment, useState } from 'react';
import Heading from '@/components/heading';
import { RuleEditDialog } from '@/components/rules/rule-edit-dialog';
import type { RuleFormValues } from '@/components/rules/rule-form-fields';
import {
    FIELD_LABELS,
    RuleFormFields,
    TYPE_LABELS,
    ordinal,
    ruleFormPayload,
    ruleFormValues,
} from '@/components/rules/rule-form-fields';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatMoney } from '@/lib/money';
import {
    apply,
    destroy,
    index as rulesIndex,
    store,
} from '@/routes/category-rules';
import type { CategoryRuleAccount, CategoryRuleRow } from '@/types/rules';
import type { CategoryOption } from '@/types/transactions';

interface Props {
    rules: CategoryRuleRow[];
    accounts: CategoryRuleAccount[];
    categories: CategoryOption[];
    /** Every tag in use, on a transaction or on a rule, for the tag field. */
    tags: string[];
    matchFields: string[];
    matchTypes: string[];
    recategorisableCount: number;
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
    tags,
    matchFields,
    matchTypes,
    recategorisableCount,
}: Props) {
    const [showForm, setShowForm] = useState(rules.length === 0);
    const [confirmingReapply, setConfirmingReapply] = useState(false);
    const [rulePendingDeletion, setRulePendingDeletion] =
        useState<CategoryRuleRow | null>(null);
    const [ruleBeingEdited, setRuleBeingEdited] =
        useState<CategoryRuleRow | null>(null);
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

                    {/*
                     * Re-applying overwrites categories that are already set,
                     * so it asks first.
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
                                            {},
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
                    <CreateRuleForm
                        categories={categories}
                        accounts={accounts}
                        tags={tags}
                        matchFields={matchFields}
                        matchTypes={matchTypes}
                        onDone={() => setShowForm(false)}
                    />
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
                                /*
                                 * The whole row opens the edit dialog, but a
                                 * click handler on a <tr> is invisible to a
                                 * keyboard, and giving the row a button role
                                 * would cost the table its semantics. So the
                                 * mouse gets the row and the keyboard gets the
                                 * real button in the name cell.
                                 */
                                <TableRow
                                    key={rule.id}
                                    onClick={() => setRuleBeingEdited(rule)}
                                    className={
                                        rule.isActive
                                            ? 'cursor-pointer'
                                            : 'cursor-pointer opacity-50'
                                    }
                                >
                                    <TableCell className="font-medium">
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setRuleBeingEdited(rule)
                                            }
                                            aria-label={`Edit ${rule.name}`}
                                            className="rounded text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        >
                                            {rule.name}
                                        </button>
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
                                        {rule.matchValues.map(
                                            (value, index) => (
                                                <Fragment key={value}>
                                                    {index > 0 && ' or '}
                                                    <code className="text-foreground">
                                                        {value}
                                                    </code>
                                                </Fragment>
                                            ),
                                        )}
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
                                        {rule.setName !== null && (
                                            <Badge
                                                variant="outline"
                                                className="ml-1"
                                            >
                                                <Pencil className="h-3 w-3" />
                                                {rule.setName}
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
                                            onClick={(event) => {
                                                event.stopPropagation();
                                                setRulePendingDeletion(rule);
                                            }}
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <RuleEditDialog
                    rule={ruleBeingEdited}
                    categories={categories}
                    accounts={accounts}
                    tags={tags}
                    matchFields={matchFields}
                    matchTypes={matchTypes}
                    onClose={() => setRuleBeingEdited(null)}
                />

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

function CreateRuleForm({
    categories,
    accounts,
    tags,
    matchFields,
    matchTypes,
    onDone,
}: {
    categories: CategoryOption[];
    accounts: CategoryRuleAccount[];
    tags: string[];
    matchFields: string[];
    matchTypes: string[];
    onDone: () => void;
}) {
    const form = useForm<RuleFormValues>(ruleFormValues());
    const { data, setData, errors, processing } = form;

    function submit(event: React.FormEvent) {
        event.preventDefault();

        form.transform(ruleFormPayload);

        form.post(store.url(), {
            preserveScroll: true,
            onSuccess: onDone,
        });
    }

    return (
        <form onSubmit={submit} className="max-w-3xl rounded-lg border p-4">
            <RuleFormFields
                values={data}
                setValue={setData}
                errors={errors}
                categories={categories}
                accounts={accounts}
                tags={tags}
                matchFields={matchFields}
                matchTypes={matchTypes}
            />

            <div className="mt-4 flex gap-2">
                <Button type="submit" disabled={processing}>
                    {processing && <Spinner />}
                    Save rule
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

CategoryRules.layout = {
    breadcrumbs: [{ title: 'Rules', href: rulesIndex() }],
};
