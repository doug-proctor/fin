import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { index as transactionsIndex } from '@/routes/transactions';
import type { TransactionFilterState } from '@/types/transactions';

/**
 * Owns the transactions screen's state by keeping it in the URL. Every control
 * writes to the query string and the server does the work, which means a view
 * can be bookmarked, shared and reloaded without any client state to restore.
 */
export function useTransactionFilters(current: TransactionFilterState) {
    const [pending, setPending] = useState(false);

    const apply = useCallback(
        (changes: TransactionFilterState, { replace = true } = {}) => {
            const next: TransactionFilterState = { ...current, ...changes };

            const cleaned = Object.fromEntries(
                Object.entries(next).filter(
                    ([, value]) =>
                        value !== undefined &&
                        value !== null &&
                        value !== '' &&
                        !(Array.isArray(value) && value.length === 0) &&
                        value !== false,
                ),
            );

            router.get(transactionsIndex.url(), cleaned, {
                preserveState: true,
                preserveScroll: true,
                replace,
                onStart: () => setPending(true),
                onFinish: () => setPending(false),
            });
        },
        [current],
    );

    const toggleInArray = useCallback(
        <T extends string | number>(
            key: keyof TransactionFilterState,
            value: T,
        ) => {
            const existing = (current[key] as T[] | undefined) ?? [];
            const next = existing.includes(value)
                ? existing.filter((item) => item !== value)
                : [...existing, value];

            apply({ [key]: next } as TransactionFilterState);
        },
        [apply, current],
    );

    const reset = useCallback(() => {
        router.get(
            transactionsIndex.url(),
            {},
            { preserveScroll: true, replace: true },
        );
    }, []);

    return { apply, toggleInArray, reset, pending };
}

/**
 * Debounces free-text input so typing a merchant name does not fire a request
 * per keystroke.
 */
export function useDebouncedValue<T>(value: T, delay = 350): T {
    const [debounced, setDebounced] = useState(value);
    const isFirst = useRef(true);

    useEffect(() => {
        if (isFirst.current) {
            isFirst.current = false;

            return;
        }

        const timeout = setTimeout(() => setDebounced(value), delay);

        return () => clearTimeout(timeout);
    }, [value, delay]);

    return debounced;
}
