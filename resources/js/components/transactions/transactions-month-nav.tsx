import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/button';

export interface MonthNav {
    label: string;
    /** The month being shown, as "2026-08", for anything that posts back. */
    current: string;
    previous: string;
    /** Null on the current month, because there is no month after it. */
    next: string | null;
}

interface Props {
    month: MonthNav;
    onChange: (month: string) => void;
}

export function TransactionsMonthNav({ month, onChange }: Props) {
    return (
        <div className="flex items-center gap-2">
            <Button
                variant="outline"
                size="sm"
                onClick={() => onChange(month.previous)}
                aria-label="Previous month"
            >
                <ChevronLeft className="h-4 w-4" />
            </Button>

            <Button
                variant="outline"
                size="sm"
                disabled={month.next === null}
                onClick={() => month.next !== null && onChange(month.next)}
                aria-label="Next month"
            >
                <ChevronRight className="h-4 w-4" />
            </Button>

            <span className="text-sm font-medium">{month.label}</span>
        </div>
    );
}
