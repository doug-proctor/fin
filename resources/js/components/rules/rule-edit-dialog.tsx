import { router, useForm } from '@inertiajs/react';
import { Wand2 } from 'lucide-react';
import { useState } from 'react';
import type { RuleFormValues } from '@/components/rules/rule-form-fields';
import {
    RuleFormFields,
    ruleFormPayload,
    ruleFormValues,
} from '@/components/rules/rule-form-fields';
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
import { applyOne, update } from '@/routes/category-rules';
import type { CategoryRuleAccount, CategoryRuleRow } from '@/types/rules';
import type { CategoryOption } from '@/types/transactions';

interface Props {
    rule: CategoryRuleRow | null;
    categories: CategoryOption[];
    accounts: CategoryRuleAccount[];
    /** Every tag already in use, offered by the tag field's dropdown. */
    tags: string[];
    matchFields: string[];
    matchTypes: string[];
    onClose: () => void;
}

export function RuleEditDialog({ rule, onClose, ...lists }: Props) {
    return (
        <Dialog
            open={rule !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <DialogContent
                className="max-h-[90vh] overflow-y-auto sm:max-w-3xl"
                aria-describedby={undefined}
            >
                {rule && (
                    /*
                     * Keyed by the rule, so opening a different one mounts a
                     * fresh form rather than carrying over the last one's
                     * draft and errors.
                     */
                    <EditRuleForm
                        key={rule.id}
                        rule={rule}
                        onClose={onClose}
                        {...lists}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function EditRuleForm({
    rule,
    categories,
    accounts,
    tags,
    matchFields,
    matchTypes,
    onClose,
}: Props & { rule: CategoryRuleRow }) {
    const form = useForm<RuleFormValues>(ruleFormValues(rule));
    const { data, setData, errors, processing } = form;

    function submit(event: React.FormEvent) {
        event.preventDefault();

        form.transform(ruleFormPayload);

        form.patch(update.url(rule.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    return (
        <form onSubmit={submit}>
            <DialogTitle>Edit rule</DialogTitle>

            <div className="mt-6">
                <RuleFormFields
                    idPrefix="edit-"
                    values={data}
                    setValue={setData}
                    errors={errors}
                    categories={categories}
                    accounts={accounts}
                    tags={tags}
                    matchFields={matchFields}
                    matchTypes={matchTypes}
                />
            </div>

            <DialogFooter className="mt-6 gap-2 sm:justify-between">
                <ApplyRuleButton rule={rule} />

                <div className="flex flex-col-reverse gap-2 sm:flex-row">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>

                    <Button type="submit" disabled={processing}>
                        {processing && <Spinner />}
                        Save changes
                    </Button>
                </div>
            </DialogFooter>
        </form>
    );
}

/**
 * Runs this one rule back over every stored transaction.
 *
 * It applies the rule as it is saved, not as the form currently reads, so an
 * unsaved edit in the dialog behind it has no part in what runs.
 */
function ApplyRuleButton({ rule }: { rule: CategoryRuleRow }) {
    const [confirming, setConfirming] = useState(false);
    const [applying, setApplying] = useState(false);

    return (
        <Dialog open={confirming} onOpenChange={setConfirming}>
            <DialogTrigger asChild>
                <Button type="button" variant="outline">
                    <Wand2 className="h-4 w-4" />
                    Apply this rule to all transactions
                </Button>
            </DialogTrigger>

            <DialogContent>
                <DialogTitle>
                    Apply the &ldquo;{rule.name}&rdquo; rule to every
                    transaction?
                </DialogTitle>
                <DialogDescription asChild>
                    <div className="space-y-2">
                        <p>
                            It runs over every transaction and overwrites the
                            category, name and tags on the ones it matches. It
                            cannot be undone.
                        </p>
                        <p>Anything you set by hand is left alone.</p>
                    </div>
                </DialogDescription>

                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button type="button" variant="secondary">
                            Cancel
                        </Button>
                    </DialogClose>

                    <Button
                        type="button"
                        variant="destructive"
                        data-dialog-autofocus
                        disabled={applying}
                        onClick={() =>
                            router.post(
                                applyOne.url(rule.id),
                                {},
                                {
                                    preserveScroll: true,
                                    onStart: () => setApplying(true),
                                    onFinish: () => {
                                        setApplying(false);
                                        setConfirming(false);
                                    },
                                },
                            )
                        }
                    >
                        {applying && <Spinner />}
                        Apply this rule
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
