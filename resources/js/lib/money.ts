/**
 * Money is held in minor units everywhere, right up to the point it is shown,
 * so no rounding can creep in on the way.
 */
export function formatMoney(
    minorUnits: number,
    currency = 'GBP',
    { signed = false }: { signed?: boolean } = {},
): string {
    const formatted = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
    }).format(Math.abs(minorUnits) / 100);

    if (!signed || minorUnits === 0) {
        return formatted;
    }

    return `${minorUnits < 0 ? '−' : '+'}${formatted}`;
}

export function formatDate(value: string): string {
    return new Date(value).toLocaleDateString(undefined, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

export function formatDateTime(value: string | null): string {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

/**
 * A decimal amount as typed, to the signed minor units everything else works
 * in. Blank or unreadable input is no amount at all rather than zero, so an
 * empty optional field does not become a condition of "exactly £0.00".
 */
export function parseMoneyToMinor(
    value: string | null | undefined,
): number | null {
    if (value === null || value === undefined) {
        return null;
    }

    const cleaned = value.trim().replace(/[£,\s]/g, '');

    if (!/^-?(\d+(\.\d*)?|\.\d+)$/.test(cleaned)) {
        return null;
    }

    return Math.round(Number(cleaned) * 100);
}

/**
 * Signed minor units back into the decimal string a money input expects, so a
 * stored amount can be loaded into the form it was typed in.
 */
export function formatMinorForInput(minorUnits: number | null): string {
    return minorUnits === null ? '' : (minorUnits / 100).toFixed(2);
}
