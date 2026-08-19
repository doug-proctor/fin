import { Check, ChevronDown } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

export interface FacetOption<T extends string | number> {
    value: T;
    label: string;
}

interface Props<T extends string | number> {
    label: string;
    options: FacetOption<T>[];
    selected: T[];
    onToggle: (value: T) => void;
    onClear: () => void;
}

/**
 * A multi-select built from the dropdown and button primitives already in the
 * project, so the filter bar needs no extra dependency.
 */
export function FacetedFilter<T extends string | number>({
    label,
    options,
    selected,
    onToggle,
    onClear,
}: Props<T>) {
    if (options.length === 0) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm">
                    {label}
                    {selected.length > 0 && (
                        <Badge
                            variant="secondary"
                            className="ml-1 rounded-sm px-1 font-normal"
                        >
                            {selected.length}
                        </Badge>
                    )}
                    <ChevronDown className="ml-1 h-3.5 w-3.5 opacity-50" />
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent
                align="start"
                className="max-h-80 w-56 overflow-y-auto"
            >
                <DropdownMenuLabel>{label}</DropdownMenuLabel>
                <DropdownMenuSeparator />

                {options.map((option) => {
                    const isSelected = selected.includes(option.value);

                    return (
                        <DropdownMenuItem
                            key={String(option.value)}
                            onSelect={(event) => {
                                event.preventDefault();
                                onToggle(option.value);
                            }}
                            className="gap-2"
                        >
                            <span
                                className={cn(
                                    'flex h-4 w-4 items-center justify-center rounded-sm border',
                                    isSelected
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'border-input',
                                )}
                            >
                                {isSelected && <Check className="h-3 w-3" />}
                            </span>
                            <span className="truncate">{option.label}</span>
                        </DropdownMenuItem>
                    );
                })}

                {selected.length > 0 && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            onSelect={(event) => {
                                event.preventDefault();
                                onClear();
                            }}
                            className="justify-center text-sm"
                        >
                            Clear
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
