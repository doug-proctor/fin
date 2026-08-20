import { router } from '@inertiajs/react';
import { CheckCheck } from 'lucide-react';
import { useState } from 'react';
import type { MonthNav } from '@/components/transactions/transactions-month-nav';
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
import { markProcessed } from '@/routes/transactions';

interface Props {
    month: MonthNav;
    /** Rows in this month not marked off yet, over the whole month. */
    unprocessedCount: number;
}

/**
 * Marks off a whole month in one go.
 *
 * The write covers the month on screen and nothing else, so the confirmation
 * names that month and its own count rather than anything the filter bar has
 * narrowed the table to.
 */
export function MarkMonthProcessedDialog({ month, unprocessedCount }: Props) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    function confirm() {
        router.post(
            markProcessed.url(),
            { month: month.current },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    setOpen(false);
                },
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant="outline"
                    size="icon"
                    className="size-8"
                    title="Mark all as processed"
                    aria-label="Mark all as processed"
                    disabled={unprocessedCount === 0}
                >
                    <CheckCheck className="h-4 w-4" />
                </Button>
            </DialogTrigger>

            <DialogContent>
                <DialogTitle>
                    Mark every transaction in {month.label} as processed?
                </DialogTitle>
                <DialogDescription>
                    This marks{' '}
                    <strong>{unprocessedCount.toLocaleString()}</strong>{' '}
                    {unprocessedCount === 1 ? 'transaction' : 'transactions'} in{' '}
                    {month.label} as processed. Other months are left alone.
                </DialogDescription>

                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>

                    <Button
                        data-dialog-autofocus
                        disabled={processing}
                        onClick={confirm}
                    >
                        {processing && <Spinner />}
                        Mark all as processed
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
