/**
 * Money on screen: rupees and cents, e.g. "1,890.00".
 *
 * The server keeps every amount at six decimals (exact accounting); screens show it to two,
 * rounded half up — display only, never fed back into arithmetic. The rounding is done on the
 * decimal string itself, so no floating-point error can creep in. Null, undefined and empty
 * values show as the given placeholder ("—" by default).
 */
export const MONEY_DECIMALS = 2;

export function money(value: string | number | null | undefined, placeholder = '—'): string {
    if (value === null || value === undefined || value === '') {
        return placeholder;
    }

    const text = typeof value === 'number' ? value.toFixed(6) : String(value).trim();
    const match = /^(-?)(\d*)(?:\.(\d*))?$/.exec(text);

    if (!match) {
        return text;
    }

    const [, sign, whole = '0', fraction = ''] = match;
    const padded = (fraction + '0'.repeat(MONEY_DECIMALS + 1)).slice(0, MONEY_DECIMALS + 1);

    // Integer cents, plus one if the next digit rounds the cents up.
    let cents = BigInt((whole || '0') + padded.slice(0, MONEY_DECIMALS));

    if (Number(padded[MONEY_DECIMALS]) >= 5) {
        cents += 1n;
    }

    const digits = cents.toString().padStart(MONEY_DECIMALS + 1, '0');
    const rupees = digits.slice(0, -MONEY_DECIMALS).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const isNegative = sign === '-' && cents !== 0n;

    return `${isNegative ? '-' : ''}${rupees}.${digits.slice(-MONEY_DECIMALS)}`;
}

/** A money value for an input box: two decimals, no thousands separators ("4.00"). */
export function moneyInput(value: string | number | null | undefined): string {
    return value === null || value === undefined || value === '' ? '' : money(value, '').replace(/,/g, '');
}
