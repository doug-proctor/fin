import { useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Import } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store as storeImport } from '@/routes/transactions/import';

export interface ImportResult {
    status: 'success' | 'error';
    filename: string | null;
    message?: string;
    total?: number;
    imported?: number;
    updated?: number;
    skipped?: number;
}

interface Props {
    result: ImportResult | null;
}

export function ImportAmexDialog({ result }: Props) {
    const [open, setOpen] = useState(false);

    /**
     * Whether an upload has been sent from this opening of the dialog. The
     * result prop outlives the dialog being closed, so without this a second
     * visit would open straight onto the previous import's outcome instead of
     * a fresh file picker.
     */
    const [submitted, setSubmitted] = useState(false);

    const form = useForm<{ file: File | null }>({ file: null });

    /**
     * The result arrives as a prop on the redirect that follows the upload.
     * The dialog is still mounted at that point, so it swaps from the file
     * picker to the outcome rather than closing behind the user's back.
     */
    const showingResult =
        open && submitted && !form.processing && result !== null;

    function reset(next: boolean) {
        setOpen(next);
        setSubmitted(false);

        if (!next) {
            form.reset();
            form.clearErrors();
        }
    }

    function submit(event: React.FormEvent) {
        event.preventDefault();
        setSubmitted(true);

        form.post(storeImport.url(), {
            preserveState: true,
            preserveScroll: true,
            /** Clear the picker so closing and reopening starts clean. */
            onSuccess: () => form.reset(),
            onError: () => setSubmitted(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={reset}>
            <DialogTrigger asChild>
                <Button
                    variant="outline"
                    size="icon"
                    className="size-8"
                    title="Import AMEX"
                    aria-label="Import AMEX"
                >
                    <Import className="h-4 w-4" />
                </Button>
            </DialogTrigger>

            <DialogContent>
                {showingResult ? (
                    <>
                        <DialogTitle className="flex items-center gap-2">
                            {result.status === 'success' ? (
                                <>
                                    <CheckCircle2 className="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                                    Import complete
                                </>
                            ) : (
                                <>
                                    <AlertTriangle className="h-5 w-5 text-rose-600 dark:text-rose-400" />
                                    Import failed
                                </>
                            )}
                        </DialogTitle>

                        <DialogDescription asChild>
                            <div className="space-y-2">
                                {result.filename && (
                                    <p className="font-medium text-foreground">
                                        {result.filename}
                                    </p>
                                )}

                                {result.status === 'success' ? (
                                    <ul className="space-y-1">
                                        <li>
                                            <strong>{result.imported}</strong>{' '}
                                            new{' '}
                                            {result.imported === 1
                                                ? 'transaction'
                                                : 'transactions'}{' '}
                                            imported
                                        </li>
                                        <li>
                                            <strong>{result.updated}</strong>{' '}
                                            already present and refreshed
                                        </li>
                                        {(result.skipped ?? 0) > 0 && (
                                            <li>
                                                <strong>
                                                    {result.skipped}
                                                </strong>{' '}
                                                skipped, because the date or
                                                amount could not be read
                                            </li>
                                        )}
                                        <li className="text-muted-foreground">
                                            {result.total} rows read in total
                                        </li>
                                    </ul>
                                ) : (
                                    <p>{result.message}</p>
                                )}
                            </div>
                        </DialogDescription>

                        <DialogFooter>
                            <Button onClick={() => reset(false)}>Close</Button>
                        </DialogFooter>
                    </>
                ) : (
                    <form onSubmit={submit}>
                        <DialogTitle>Import AMEX transactions</DialogTitle>
                        <DialogDescription>
                            Upload the activity you downloaded from
                            americanexpress.com. Columns can be in any order.
                        </DialogDescription>

                        <div className="mt-4 space-y-2">
                            <Label htmlFor="file">CSV file</Label>
                            <Input
                                id="file"
                                type="file"
                                accept=".csv,text/csv"
                                onChange={(event) =>
                                    form.setData(
                                        'file',
                                        event.target.files?.[0] ?? null,
                                    )
                                }
                            />
                            <InputError message={form.errors.file} />

                            {form.progress && (
                                <progress
                                    value={form.progress.percentage}
                                    max="100"
                                    className="w-full"
                                >
                                    {form.progress.percentage}%
                                </progress>
                            )}
                        </div>

                        <DialogFooter className="mt-6 gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => reset(false)}
                            >
                                Cancel
                            </Button>

                            <Button
                                type="submit"
                                disabled={form.processing || !form.data.file}
                            >
                                {form.processing && <Spinner />}
                                Import
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
