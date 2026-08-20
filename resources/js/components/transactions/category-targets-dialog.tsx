import { useForm } from '@inertiajs/react';
import { Target } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    formatMinorForInput,
    formatMoney,
    parseMoneyToMinor,
} from '@/lib/money';
import { cn } from '@/lib/utils';
import { store as storeTargets } from '@/routes/category-targets';
import type { CategoryOption, CategoryTargets } from '@/types/transactions';

interface Props {
    /** The label of the month on screen, for the dialog title. */
    monthLabel: string;
    targets: CategoryTargets;
    categories: CategoryOption[];
}

interface TargetsForm {
    month: string;
    /** Category value to the amount as typed, in pounds. */
    targets: Record<string, string>;
}

export function CategoryTargetsDialog({
    monthLabel,
    targets,
    categories,
}: Props) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant="outline"
                    size="icon"
                    className="size-8"
                    title="Targets"
                    aria-label="Targets"
                >
                    <Target className="h-4 w-4" />
                </Button>
            </DialogTrigger>

            <DialogContent className="max-h-[85vh]">
                {open && (
                    /*
                     * Keyed by the month, so stepping to another month mounts
                     * a fresh form rather than carrying over the last one's
                     * draft and errors.
                     */
                    <TargetsFormBody
                        key={targets.month}
                        monthLabel={monthLabel}
                        targets={targets}
                        categories={categories}
                        onDone={() => setOpen(false)}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function TargetsFormBody({
    monthLabel,
    targets,
    categories,
    onDone,
}: Props & { onDone: () => void }) {
    /**
     * A blank field is no target at all rather than a target of zero, and
     * formatMinorForInput already draws that line: '' for a category with no
     * target, '0.00' for one deliberately set to nothing.
     */
    const initial: TargetsForm = {
        month: targets.month,
        targets: Object.fromEntries(
            categories.map((category) => [
                category.value,
                formatMinorForInput(targets.prefill[category.value] ?? null),
            ]),
        ),
    };

    const form = useForm<TargetsForm>(initial);
    const { data, setData, processing } = form;

    /**
     * The fields a month inherited from an earlier one are shown greyed until
     * they are touched, because nothing has been saved for this month yet.
     */
    const [touched, setTouched] = useState<Record<string, true>>({});
    const isSuggested = (value: string) =>
        targets.copiedFrom !== null && touched[value] === undefined;

    /**
     * Errors come back keyed by path, which useForm's own error type does not
     * describe.
     */
    const errorFor = (value: string) =>
        (form.errors as Record<string, string | undefined>)[`targets.${value}`];

    const total = categories.reduce(
        (sum, category) =>
            sum + (parseMoneyToMinor(data.targets[category.value]) ?? 0),
        0,
    );

    function setTarget(value: string, amount: string) {
        setTouched((previous) => ({ ...previous, [value]: true }));
        setData('targets', { ...data.targets, [value]: amount });
    }

    function submit(event: React.FormEvent) {
        event.preventDefault();

        form.post(storeTargets.url(), {
            preserveScroll: true,
            preserveState: true,
            /** Scoped so the filters, month and scroll position all survive. */
            only: ['targets'],
            onSuccess: onDone,
        });
    }

    return (
        <form onSubmit={submit} className="grid gap-4 overflow-hidden">
            <DialogTitle>Targets for {monthLabel}</DialogTitle>

            <DialogDescription className="sr-only">
                A spending target for each category in {monthLabel}.
            </DialogDescription>

            <div className="max-h-[55vh] overflow-y-auto pr-1">
                <div className="grid grid-cols-[1fr_auto] items-center gap-x-4 gap-y-2">
                    {categories.map((category) => (
                        <div key={category.value} className="contents">
                            <Label
                                htmlFor={`target-${category.value}`}
                                className="font-normal"
                            >
                                {category.label}
                            </Label>

                            <div className="grid justify-items-end gap-1">
                                <div className="flex items-center gap-1">
                                    <span className="text-muted-foreground">
                                        £
                                    </span>
                                    <Input
                                        id={`target-${category.value}`}
                                        type="number"
                                        inputMode="decimal"
                                        step="0.01"
                                        min="0"
                                        className={cn(
                                            'h-8 w-28 text-right tabular-nums',
                                            isSuggested(category.value) &&
                                                'text-muted-foreground',
                                        )}
                                        value={data.targets[category.value]}
                                        onChange={(event) =>
                                            setTarget(
                                                category.value,
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>

                                <InputError
                                    message={errorFor(category.value)}
                                />
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            <div className="flex items-center justify-between border-t pt-4 text-sm">
                <span className="text-muted-foreground">Total</span>
                <span className="font-medium tabular-nums">
                    {formatMoney(total)}
                </span>
            </div>

            <DialogFooter className="gap-2">
                <DialogClose asChild>
                    <Button type="button" variant="secondary">
                        Cancel
                    </Button>
                </DialogClose>

                <Button type="submit" disabled={processing}>
                    {processing && <Spinner />}
                    Save
                </Button>
            </DialogFooter>
        </form>
    );
}
