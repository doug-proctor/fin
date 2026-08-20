import { X } from 'lucide-react';
import InputError from '@/components/input-error';
import { TagInput } from '@/components/transactions/tag-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { formatMinorForInput, parseMoneyToMinor } from '@/lib/money';
import type { CategoryRuleAccount, CategoryRuleRow } from '@/types/rules';
import type { CategoryOption } from '@/types/transactions';

export const FIELD_LABELS: Record<string, string> = {
    any: 'Name, description or merchant',
    name: 'Name',
    description: 'Description',
    merchant_name: 'Merchant',
};

export const TYPE_LABELS: Record<string, string> = {
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

/**
 * A select cannot hold an empty value, so each optional one needs a sentinel
 * standing for the absence of a choice. They are the same shape as the
 * transaction dialog's, and cannot collide with a category slug or an id.
 */
const ANY_DAY = '__any__';
const ALL_ACCOUNTS = '__all__';
const NO_CATEGORY = '__none__';

/**
 * The form as it is typed: strings and booleans, amounts still in pounds.
 * ruleFormPayload() is what turns it into what the server validates.
 */
export interface RuleFormValues {
    name: string;
    match_field: string;
    match_type: string;
    match_values: string[];
    amount_min: string;
    amount_max: string;
    amount: string;
    day_of_month: string;
    account_id: string;
    set_category: string;
    set_name: string;
    set_tags: string[];
    priority: string;
    stops_processing: boolean;
    is_active: boolean;
}

/** A blank rule, or an existing one loaded back into the form. */
export function ruleFormValues(rule?: CategoryRuleRow): RuleFormValues {
    return {
        name: rule?.name ?? '',
        match_field: rule?.matchField ?? 'any',
        match_type: rule?.matchType ?? 'contains',
        /**
         * One empty box to start with: a rule always looks for at least one
         * string, so there is nothing sensible to show an empty list as.
         */
        match_values: rule?.matchValues?.length ? rule.matchValues : [''],
        amount_min: formatMinorForInput(rule?.amountMinMinor ?? null),
        amount_max: formatMinorForInput(rule?.amountMaxMinor ?? null),
        amount: formatMinorForInput(rule?.amountMinor ?? null),
        day_of_month:
            rule?.dayOfMonth == null ? ANY_DAY : String(rule.dayOfMonth),
        account_id:
            rule?.accountId == null ? ALL_ACCOUNTS : String(rule.accountId),
        set_category: rule?.setCategory ?? NO_CATEGORY,
        set_name: rule?.setName ?? '',
        set_tags: rule?.setTags ?? [],
        priority: String(rule?.priority ?? 0),
        stops_processing: rule?.stopsProcessing ?? true,
        is_active: rule?.isActive ?? true,
    };
}

/**
 * The amounts are typed in pounds but stored in signed minor units, and every
 * optional field has to reach the server as null rather than as the empty
 * string the integer and exists rules would both reject.
 */
export function ruleFormPayload(
    values: RuleFormValues,
): Record<string, unknown> {
    const setName = values.set_name.trim();

    return {
        name: values.name,
        match_field: values.match_field,
        match_type: values.match_type,
        /**
         * Sent as typed, empty boxes and all. The server trims them, drops the
         * blanks and refuses a list with nothing left, rather than the form
         * quietly deciding which boxes counted.
         */
        match_values: values.match_values,
        amount_min_minor: parseMoneyToMinor(values.amount_min),
        amount_max_minor: parseMoneyToMinor(values.amount_max),
        amount_minor: parseMoneyToMinor(values.amount),
        day_of_month:
            values.day_of_month === ANY_DAY
                ? null
                : Number(values.day_of_month),
        account_id:
            values.account_id === ALL_ACCOUNTS
                ? null
                : Number(values.account_id),
        set_category:
            values.set_category === NO_CATEGORY ? null : values.set_category,
        set_name: setName === '' ? null : setName,
        /**
         * Sent as a list even when it is empty; the server normalises that
         * back to null, which is how a rule says it sets no tags.
         */
        set_tags: values.set_tags,
        priority: values.priority === '' ? 0 : Number(values.priority),
        stops_processing: values.stops_processing,
        is_active: values.is_active,
    };
}

interface Props {
    values: RuleFormValues;
    /**
     * The page's own form and the edit dialog can be open at once, so the ids
     * a label points at have to be unique per instance.
     */
    idPrefix?: string;
    setValue: <K extends keyof RuleFormValues>(
        field: K,
        value: RuleFormValues[K],
    ) => void;
    errors: Partial<Record<string, string>>;
    categories: CategoryOption[];
    accounts: CategoryRuleAccount[];
    /** Every tag already in use, offered by the tag field's dropdown. */
    tags: string[];
    matchFields: string[];
    matchTypes: string[];
}

/**
 * Every field of a rule, laid out the same way whether it is being written for
 * the first time on the page or edited in the dialog.
 */
export function RuleFormFields({
    values,
    setValue,
    errors,
    categories,
    accounts,
    tags,
    matchFields,
    matchTypes,
    idPrefix = '',
}: Props) {
    const fieldId = (field: string): string => `${idPrefix}${field}`;

    const setMatchValue = (index: number, next: string): void => {
        setValue(
            'match_values',
            values.match_values.map((value, at) =>
                at === index ? next : value,
            ),
        );
    };

    const addMatchValue = (): void => {
        setValue('match_values', [...values.match_values, '']);
    };

    /**
     * Removing the last remaining box would leave a rule that looks for
     * nothing, so the control that does it is only rendered while there is
     * more than one.
     */
    const removeMatchValue = (index: number): void => {
        setValue(
            'match_values',
            values.match_values.filter((_, at) => at !== index),
        );
    };

    return (
        <div className="grid gap-4 sm:grid-cols-3">
            <div className="space-y-2 sm:col-span-3">
                <Label htmlFor={fieldId('name')}>Rule name</Label>
                <Input
                    id={fieldId('name')}
                    name="name"
                    placeholder="Supermarkets"
                    required
                    value={values.name}
                    onChange={(event) => setValue('name', event.target.value)}
                />
                <InputError message={errors.name} />
            </div>

            <Separator className="sm:col-span-3" />

            <h3 className="text-sm font-medium sm:col-span-3">Matching</h3>

            <div className="space-y-2">
                <Label htmlFor={fieldId('match_field')}>Look at</Label>
                <Select
                    value={values.match_field}
                    onValueChange={(value) => setValue('match_field', value)}
                >
                    <SelectTrigger
                        id={fieldId('match_field')}
                        className="w-full"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {matchFields.map((field) => (
                            <SelectItem key={field} value={field}>
                                {FIELD_LABELS[field] ?? field}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <div className="space-y-2">
                <Label htmlFor={fieldId('match_type')}>Which</Label>
                <Select
                    value={values.match_type}
                    onValueChange={(value) => setValue('match_type', value)}
                >
                    <SelectTrigger
                        id={fieldId('match_type')}
                        className="w-full"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {matchTypes.map((type) => (
                            <SelectItem key={type} value={type}>
                                {TYPE_LABELS[type] ?? type}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            {/*
             * One box per string the rule looks for. The rule matches when any
             * one of them does, which is what the "or" between the boxes says.
             */}
            <div className="space-y-2">
                <Label htmlFor={fieldId('match_values.0')}>This text</Label>

                {values.match_values.map((value, index) => (
                    <div key={index} className="space-y-2">
                        {index > 0 && (
                            <div className="text-xs text-muted-foreground">
                                or
                            </div>
                        )}

                        <div className="flex items-center gap-1">
                            <Input
                                id={fieldId(`match_values.${index}`)}
                                name={`match_values[${index}]`}
                                placeholder="tesco"
                                value={value}
                                onChange={(event) =>
                                    setMatchValue(index, event.target.value)
                                }
                            />

                            {values.match_values.length > 1 && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Remove text ${index + 1}`}
                                    onClick={() => removeMatchValue(index)}
                                >
                                    <X className="h-4 w-4" />
                                </Button>
                            )}
                        </div>

                        <InputError message={errors[`match_values.${index}`]} />
                    </div>
                ))}

                <button
                    type="button"
                    onClick={addMatchValue}
                    className="text-xs text-muted-foreground underline underline-offset-4 hover:text-foreground"
                >
                    Add another
                </button>

                <InputError message={errors.match_values} />
            </div>

            {/*
             * All four optional. Left blank the rule matches on its text
             * alone, which is how every rule written before these existed
             * behaves. An exact amount and a range are refused together,
             * because a rule cannot sensibly be both.
             */}
            <div className="space-y-2">
                <Label htmlFor={fieldId('amount_min_minor')}>At least</Label>
                <Input
                    id={fieldId('amount_min_minor')}
                    type="number"
                    step="0.01"
                    placeholder="No lower bound"
                    value={values.amount_min}
                    onChange={(event) =>
                        setValue('amount_min', event.target.value)
                    }
                />
                <InputError message={errors.amount_min_minor} />
            </div>

            <div className="space-y-2">
                <Label htmlFor={fieldId('amount_max_minor')}>At most</Label>
                <Input
                    id={fieldId('amount_max_minor')}
                    type="number"
                    step="0.01"
                    placeholder="No upper bound"
                    value={values.amount_max}
                    onChange={(event) =>
                        setValue('amount_max', event.target.value)
                    }
                />
                <InputError message={errors.amount_max_minor} />
            </div>

            <div className="space-y-2">
                <Label htmlFor={fieldId('amount_minor')}>
                    Only this amount
                </Label>
                <Input
                    id={fieldId('amount_minor')}
                    type="number"
                    step="0.01"
                    placeholder="Any amount"
                    value={values.amount}
                    onChange={(event) => setValue('amount', event.target.value)}
                />
                <InputError message={errors.amount_minor} />
            </div>

            <div className="space-y-2">
                <Label htmlFor={fieldId('day_of_month')}>
                    Only this day of the month
                </Label>
                <Select
                    value={values.day_of_month}
                    onValueChange={(value) => setValue('day_of_month', value)}
                >
                    <SelectTrigger
                        id={fieldId('day_of_month')}
                        className="w-full"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ANY_DAY}>Any day</SelectItem>
                        {DAYS_OF_MONTH.map((day) => (
                            <SelectItem key={day} value={String(day)}>
                                {ordinal(day)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.day_of_month} />
            </div>

            <div className="space-y-2">
                <Label htmlFor={fieldId('priority')}>
                    Priority (higher runs first)
                </Label>
                <Input
                    id={fieldId('priority')}
                    type="number"
                    value={values.priority}
                    onChange={(event) =>
                        setValue('priority', event.target.value)
                    }
                />
                <InputError message={errors.priority} />
            </div>

            <div className="space-y-2">
                <Label htmlFor={fieldId('account_id')}>Limit to account</Label>
                <Select
                    value={values.account_id}
                    onValueChange={(value) => setValue('account_id', value)}
                >
                    <SelectTrigger
                        id={fieldId('account_id')}
                        className="w-full"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL_ACCOUNTS}>
                            All accounts
                        </SelectItem>
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

            <label className="flex items-center gap-2 text-sm sm:col-span-3">
                <Checkbox
                    checked={values.stops_processing}
                    onCheckedChange={(checked) =>
                        setValue('stops_processing', checked === true)
                    }
                />
                Stop checking further rules once this one matches
            </label>

            <Separator className="sm:col-span-3" />

            <h3 className="text-sm font-medium sm:col-span-3">Actions</h3>

            <div className="space-y-2">
                <Label htmlFor={fieldId('set_category')}>Set category</Label>
                <Select
                    value={values.set_category}
                    onValueChange={(value) => setValue('set_category', value)}
                >
                    <SelectTrigger
                        id={fieldId('set_category')}
                        className="w-full"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={NO_CATEGORY}>No category</SelectItem>
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
                <Label htmlFor={fieldId('set_name')}>Rename to</Label>
                <Input
                    id={fieldId('set_name')}
                    value={values.set_name}
                    onChange={(event) =>
                        setValue('set_name', event.target.value)
                    }
                />
                <InputError message={errors.set_name} />
            </div>

            {/*
             * The same field the transaction dialog uses, reading the same
             * list, so a rule cannot invent a second spelling of a tag that
             * already exists.
             */}
            <div className="space-y-2">
                <Label htmlFor={fieldId('set_tags')}>Add tags</Label>
                <TagInput
                    id={fieldId('set_tags')}
                    value={values.set_tags}
                    suggestions={tags}
                    onChange={(next) => setValue('set_tags', next)}
                />
                <InputError message={errors.set_tags} />
            </div>
        </div>
    );
}

export function ordinal(day: number): string {
    const suffix =
        day % 100 >= 11 && day % 100 <= 13
            ? 'th'
            : ({ 1: 'st', 2: 'nd', 3: 'rd' }[day % 10] ?? 'th');

    return `${day}${suffix}`;
}
