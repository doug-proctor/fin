import { useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
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
}

/** Text fields that are sent as-is, and blank back to null. */
const TEXT_FIELDS = ['description', 'notes'] as const;

export function TransactionEditDialog({
    transaction,
    categories,
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
    onClose,
}: {
    transaction: TransactionRow;
    categories: CategoryOption[];
    onClose: () => void;
}) {
    const initial: EditForm = {
        description: transaction.description ?? '',
        category: transaction.category ?? NO_CATEGORY,
        notes: transaction.notes ?? '',
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

        for (const field of TEXT_FIELDS) {
            if (data[field] !== initial[field]) {
                changed[field] = data[field].trim() === '' ? null : data[field];
            }
        }

        if (data.category !== initial.category) {
            changed.category =
                data.category === NO_CATEGORY ? null : data.category;
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

                {/*
                 * Tags have no field of their own, because they are the "#tag"
                 * words inside the note and get rewritten whenever it changes.
                 */}
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
                    <p className="text-xs text-muted-foreground">
                        Words starting with # become tags.
                    </p>
                    <InputError message={errors.notes} />
                </div>
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
