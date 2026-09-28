import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import si from '../../../lang/si.json';

/**
 * Screen translation for the full ERP.
 *
 * ONE Sinhala file serves both the server (Laravel's __()) and the screens: lang/si.json,
 * keyed by the English text itself — so anything not yet translated simply shows in English.
 * Placeholders use Laravel's ":name" style: t('Showing :count rows', { count: 5 }).
 *
 * Display only. Saved records are always written in English on the server (RecordText).
 */
const catalogues: Record<string, Record<string, string>> = { si };

export function translate(locale: string, key: string, replace: Record<string, string | number> = {}): string {
    let text = catalogues[locale]?.[key] ?? key;

    // Longest names first, so ":amount" is not partly replaced by ":am".
    for (const name of Object.keys(replace).sort((a, b) => b.length - a.length)) {
        text = text.replaceAll(`:${name}`, String(replace[name]));
    }

    return text;
}

export function useT() {
    const page = usePage();
    const locale = computed(() => (page.props.locale as string | undefined) ?? 'en');

    function t(key: string, replace: Record<string, string | number> = {}): string {
        return translate(locale.value, key, replace);
    }

    return { t, locale };
}
