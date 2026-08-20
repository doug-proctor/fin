import { Form, Head, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import Heading from '@/components/heading';
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
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { index as categoriesIndex, store, update } from '@/routes/categories';

interface Category {
    id: number;
    value: string;
    label: string;
    count: number;
}

interface Props {
    categories: Category[];
}

/**
 * Renames one category. The value is the stable handle its transactions are
 * filed under and is never editable, so only the display name is in play.
 */
function LabelCell({ category }: { category: Category }) {
    const [isEditing, setIsEditing] = useState(false);
    const [draft, setDraft] = useState(category.label);
    const inputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (isEditing) {
            inputRef.current?.focus();
            inputRef.current?.select();
        }
    }, [isEditing]);

    function commit() {
        setIsEditing(false);

        if (draft.trim() === '' || draft === category.label) {
            setDraft(category.label);

            return;
        }

        router.patch(
            update.url(category.id),
            { label: draft.trim() },
            { preserveScroll: true },
        );
    }

    if (isEditing) {
        return (
            <Input
                ref={inputRef}
                value={draft}
                onChange={(event) => setDraft(event.target.value)}
                onBlur={commit}
                onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                        commit();
                    }

                    if (event.key === 'Escape') {
                        setDraft(category.label);
                        setIsEditing(false);
                    }
                }}
                className="h-8"
                aria-label={`Rename ${category.label}`}
            />
        );
    }

    return (
        <button
            type="button"
            onClick={() => {
                setDraft(category.label);
                setIsEditing(true);
            }}
            className="-mx-1 w-full rounded px-1 py-0.5 text-left hover:bg-muted"
        >
            {category.label}
        </button>
    );
}

export default function Categories({ categories }: Props) {
    const [creating, setCreating] = useState(false);

    return (
        <>
            <Head title="Categories" />

            <div className="flex h-full flex-1 flex-col p-4">
                <div className="mx-auto flex w-full max-w-2xl flex-col gap-4">
                    <div className="flex items-start justify-between gap-4">
                        <Heading
                            title="Categories"
                            description="Click a name to change it"
                        />

                        <Dialog open={creating} onOpenChange={setCreating}>
                            <DialogTrigger asChild>
                                <Button>
                                    <Plus className="h-4 w-4" />
                                    New category
                                </Button>
                            </DialogTrigger>

                            <DialogContent>
                                <DialogTitle>New category</DialogTitle>
                                <DialogDescription>
                                    A new category to file transactions under.
                                </DialogDescription>

                                <Form
                                    {...store.form()}
                                    options={{ preserveScroll: true }}
                                    resetOnSuccess
                                    onSuccess={() => setCreating(false)}
                                    className="mt-2 grid gap-4"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <div className="space-y-2">
                                                <Label htmlFor="label">
                                                    Name
                                                </Label>
                                                <Input
                                                    id="label"
                                                    name="label"
                                                    placeholder="Coffee"
                                                    autoFocus
                                                    required
                                                />
                                                <InputError
                                                    message={errors.label}
                                                />
                                            </div>

                                            <DialogFooter className="gap-2">
                                                {/*
                                                 * Inside a form a button
                                                 * submits unless told not to.
                                                 */}
                                                <DialogClose asChild>
                                                    <Button
                                                        type="button"
                                                        variant="secondary"
                                                    >
                                                        Cancel
                                                    </Button>
                                                </DialogClose>

                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                >
                                                    {processing && <Spinner />}
                                                    Create category
                                                </Button>
                                            </DialogFooter>
                                        </>
                                    )}
                                </Form>
                            </DialogContent>
                        </Dialog>
                    </div>

                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead className="text-right">
                                    Transactions
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {categories.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={2}
                                        className="py-12 text-center text-muted-foreground"
                                    >
                                        No categories yet. Add one to start
                                        filing transactions.
                                    </TableCell>
                                </TableRow>
                            )}

                            {categories.map((category) => (
                                <TableRow key={category.id}>
                                    <TableCell className="max-w-[24rem]">
                                        <LabelCell category={category} />
                                    </TableCell>
                                    <TableCell className="text-right text-muted-foreground tabular-nums">
                                        {category.count.toLocaleString()}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </>
    );
}

Categories.layout = {
    breadcrumbs: [{ title: 'Categories', href: categoriesIndex() }],
};
