import { useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { TagInput } from '@/components/transactions/tag-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
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
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatMoney } from '@/lib/money';
import { update as updateTransaction } from '@/routes/transactions';
import type { CategoryOption, TransactionRow } from '@/types/transactions';

interface Props {
    transaction: TransactionRow | null;
    categories: CategoryOption[];
    /** Every tag already in use, offered by the tag field's dropdown. */
    tags: string[];
    onClose: () => void;
}

/**
 * A select cannot hold an empty value, so clearing the category needs a
 * sentinel. Category values are slugs, so the surrounding underscores keep
 * this one from ever colliding with a real category.
 */
const NO_CATEGORY = '__none__';

/**
 * The form fields carry the names the server validates against, so an error
 * comes back keyed to the input that caused it.
 */
interface EditForm {
    description: string;
    category: string;
    notes: string;
<<<<<<< Updated upstream
    accounting_date: string;
=======
    tags: string[];
    processed: boolean;
>>>>>>> Stashed changes
}

/** Fields that are sent as typed, and blank back to null. */
const NULLABLE_FIELDS = ['description', 'notes', 'accounting_date'] as const;

export function TransactionEditDialog({
    transaction,
    categories,
    tags,
    onClose,
}: Props) {
    return (
        <Dialog
            open={transaction !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <DialogContent>
                {transaction && (
                    /*
                     * Keyed by the row, so opening a different transaction
                     * mounts a fresh form rather than carrying over the last
                     * one's draft and errors.
                     */
                    <EditTransactionForm
                        key={transaction.id}
                        transaction={transaction}
                        categories={categories}
                        tags={tags}
                        onClose={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function EditTransactionForm({
    transaction,
    categories,
    tags,
    onClose,
}: {
    transaction: TransactionRow;
    categories: CategoryOption[];
    tags: string[];
    onClose: () => void;
}) {
    const initial: EditForm = {
        description: transaction.description ?? '',
        category: transaction.category ?? NO_CATEGORY,
        notes: transaction.notes ?? '',
<<<<<<< Updated upstream
        accounting_date: transaction.accountingDate ?? '',
=======
        tags: transaction.tags,
        processed: transaction.processed,
>>>>>>> Stashed changes
    };

    const form = useForm<EditForm>(initial);
    const { data, setData, errors } = form;

    /**
     * Only the fields actually changed are sent. Every field on a request is
     * recorded as a hand edit the next sync must not undo, so submitting the
     * whole form would quietly freeze columns the user never touched.
     */
    function changedFields(): Record<string, unknown> {
        const changed: Record<string, unknown> = {};

        for (const field of NULLABLE_FIELDS) {
            if (data[field] !== initial[field]) {
                changed[field] = data[field].trim() === '' ? null : data[field];
            }
        }

        if (data.category !== initial.category) {
            changed.category =
                data.category === NO_CATEGORY ? null : data.category;
        }

        /**
         * Tags are their own field rather than words lifted out of the note,
         * so they are sent whenever the list itself differs. Order is part of
         * the value: it is the order they were added in and the order they are
         * shown in.
         */
        if (
            data.tags.length !== initial.tags.length ||
            data.tags.some((tag, index) => tag !== initial.tags[index])
        ) {
            changed.tags = data.tags;
        }

        /**
         * Local state rather than a bank field, so sending it records no
         * override; it still only goes when it actually changed, to keep the
         * "nothing to save" case closing without a request.
         */
        if (data.processed !== initial.processed) {
            changed.processed = data.processed;
        }

        return changed;
    }

    function submit(event: React.FormEvent) {
        event.preventDefault();

        const changed = changedFields();

        /** Nothing to save is not an error; just close. */
        if (Object.keys(changed).length === 0) {
            onClose();

            return;
        }

        /** The request carries only the changed fields, not the whole form. */
        form.transform(() => changed);

        form.patch(updateTransaction.url(transaction.id), {
            preserveScroll: true,
            preserveState: true,
            /**
             * Scoped to the props an edit can affect, so the filters, month
             * and scroll position all survive the save.
             */
            only: ['transactions', 'summary', 'subtotals', 'facets'],
            onSuccess: onClose,
        });
    }

    return (
        <form onSubmit={submit}>
            <DialogTitle>Edit transaction</DialogTitle>

            {/*
             * The name, date and amount are not editable here, so they are
             * listed as read-only context instead: without them the dialog
             * gives no sign of which row it belongs to. Labelled and stacked
             * so it reads the same way as the fields below it.
             */}
            <DialogDescription asChild>
                <dl className="mt-4 grid grid-cols-[auto_1fr] gap-x-6 gap-y-2">
                    <dt>Name</dt>
                    <dd className="font-medium text-foreground">
                        {transaction.name ?? 'Unnamed transaction'}
                    </dd>

                    <dt>Date</dt>
                    <dd className="text-foreground">
                        {formatDate(transaction.bookedAt)}
                    </dd>

                    <dt>Amount</dt>
                    <dd className="text-foreground tabular-nums">
                        {formatMoney(
                            transaction.amountMinor,
                            transaction.currency,
                            { signed: true },
                        )}
                    </dd>
                </dl>
            </DialogDescription>

            <div className="mt-6 grid gap-4">
                <div className="space-y-2">
                    <Label htmlFor="description">Description</Label>
                    <Textarea
                        id="description"
                        rows={2}
                        value={data.description}
                        onChange={(event) =>
                            setData('description', event.target.value)
                        }
                    />
                    <InputError message={errors.description} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="category">Category</Label>
                    <Select
                        value={data.category}
                        onValueChange={(value) => setData('category', value)}
                    >
                        <SelectTrigger id="category" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NO_CATEGORY}>
                                Uncategorised
                            </SelectItem>
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
                    <InputError message={errors.category} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="tags">Tags</Label>
                    <TagInput
                        id="tags"
                        value={data.tags}
                        suggestions={tags}
                        onChange={(next) => setData('tags', next)}
                    />
                    <InputError message={errors.tags} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="notes">Notes</Label>
                    <Textarea
                        id="notes"
                        rows={3}
                        value={data.notes}
                        onChange={(event) =>
                            setData('notes', event.target.value)
                        }
                    />
                    <InputError message={errors.notes} />
                </div>

<<<<<<< Updated upstream
                {/*
                 * For a charge that landed in the wrong month: a meal eaten in
                 * May and settled up with a friend in June. The booked date
                 * stays as the bank recorded it; only the month the amount is
                 * counted in moves.
                 */}
                <div className="space-y-2">
                    <Label htmlFor="accounting-date">Counts towards</Label>
                    <Input
                        id="accounting-date"
                        type="date"
                        value={data.accounting_date}
                        onChange={(event) =>
                            setData('accounting_date', event.target.value)
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        Leave blank to count this in the month it was booked.
                    </p>
                    <InputError message={errors.accounting_date} />
                </div>
=======
                <label className="flex items-center gap-2 text-sm">
                    <Checkbox
                        checked={data.processed}
                        onCheckedChange={(checked) =>
                            setData('processed', checked === true)
                        }
                    />
                    Processed
                </label>
>>>>>>> Stashed changes
            </div>

            <DialogFooter className="mt-6 gap-2">
                <Button type="button" variant="secondary" onClick={onClose}>
                    Cancel
                </Button>

                <Button type="submit" disabled={form.processing}>
                    {form.processing && <Spinner />}
                    Save changes
                </Button>
            </DialogFooter>
        </form>
    );
}
