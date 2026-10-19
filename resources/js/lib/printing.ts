/**
 * Printouts are always light: a dark screen theme would otherwise print white text on black.
 * The theme is switched off just for the print and put back afterwards. Applies to the browser's
 * own print command (Ctrl+P) as well as the Print buttons.
 */
export function initializePrinting(): void {
    if (typeof window === 'undefined') {
        return;
    }

    let wasDark = false;

    window.addEventListener('beforeprint', () => {
        wasDark = document.documentElement.classList.contains('dark');
        document.documentElement.classList.remove('dark');
    });

    window.addEventListener('afterprint', () => {
        if (wasDark) {
            document.documentElement.classList.add('dark');
        }
    });
}
