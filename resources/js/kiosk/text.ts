import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Every word on the simple-mode (kiosk) screens, in English and Sinhala.
 *
 * ONE file on purpose, so someone at the factory can read and correct the Sinhala without
 * touching any screen. Keys must exist in both languages — a test checks it. The language is
 * decided by the server (a per-device cookie) and arrives as `kiosk.locale`.
 */
const en = {
    // layout
    'app.title': 'Factory',
    'app.home': 'Home',
    'app.signOut': 'Sign out',
    'app.fullErp': 'Full ERP',
    'app.language': 'සිංහල',
    'app.idle': 'You will be signed out soon because nothing was touched.',
    'app.stayHere': 'I am still here',

    // home
    'home.hello': 'Hello',
    'home.choose': 'What are you doing?',
    'home.none': 'Your account has no simple-mode jobs yet. Please ask the office.',
    'task.receive': 'Receive goods',
    'task.receive.hint': 'Goods arriving from a supplier',
    'task.dispatch': 'Dispatch',
    'task.dispatch.hint': 'Goods leaving to a customer',
    'task.dehusking': 'Dehusking',
    'task.dehusking.hint': 'Remove husk from coconuts',
    'task.husk-chipping': 'Husk chipping',
    'task.husk-chipping.hint': 'Cut husk into chips',
    'task.copra': 'Copra',
    'task.copra.hint': 'Make copra from coconuts',
    'task.stock': 'Check stock',
    'task.stock.hint': 'How much is in the stores',

    // common wizard
    'step.of': 'Step {n} of {total}',
    'common.back': 'Back',
    'common.next': 'Next',
    'common.done': 'Done',
    'common.saving': 'Saving…',
    'common.search': 'Search…',
    'common.none': 'Nothing to choose',
    'common.optional': 'optional — you can skip',
    'common.skip': 'Skip',
    'common.clear': 'Clear',
    'common.change': 'Change',
    'common.store': 'Store',
    'common.today': 'Today',
    'common.check': 'Check everything, then press Done',
    'common.error': 'This could not be saved. Please check:',
    'common.addAnother': 'Add another product',
    'common.remove': 'Remove',
    'common.each': 'each',
    'common.total': 'Total',
    'common.available': 'Available',

    // pickers
    'pick.supplier': 'Who brought the goods?',
    'pick.customer': 'Who is it going to?',
    'pick.product': 'Which product?',
    'pick.store': 'Which store?',
    'pick.lot': 'Which batch?',
    'pick.lot.hint': 'Choose the batch you are using. Newest first.',
    'lot.received': 'From',
    'lot.left': 'left',

    // amounts
    'enter.count': 'How many? ({uom})',
    'enter.weight': 'Weight ({uom})',
    'enter.price': 'Price for one {uom} (Rs.)',
    'enter.priceKg': 'Price for one KG (Rs.)',
    'enter.transport': 'Transport cost (Rs.)',
    'enter.loading': 'Loading cost (Rs.)',
    'enter.vehicle': 'Vehicle number',
    'enter.waste': 'Waste weight',
    'price.by': 'How is it priced?',
    'price.byCount': 'By count',
    'price.byWeight': 'By weight (KG)',

    // production
    'prod.input': 'How many to process?',
    'prod.outputs': 'Weigh what came out',
    'prod.outputs.hint': 'Enter the weight you measured. Use 0 for a grade that was not made.',
    'prod.copraKg': 'Copra weight (KG)',
    'prod.shellKg': 'Shell weight (KG)',
    'prod.result': 'What was produced',
    'prod.auto': 'The system calculates this',

    // stock
    'stock.title': 'Stock in the stores',
    'stock.empty': 'No stock yet.',
    'stock.inStore': 'In {store}',

    // done
    'done.title': 'Saved!',
    'done.number': 'Number',
    'done.another': 'Do another',
    'done.home': 'Back to home',
} as const;

export type KioskTextKey = keyof typeof en;

const si: Record<KioskTextKey, string> = {
    'app.title': 'කර්මාන්ත ශාලාව',
    'app.home': 'මුල් පිටුව',
    'app.signOut': 'ඉවත් වන්න',
    'app.fullErp': 'සම්පූර්ණ පද්ධතිය',
    'app.language': 'English',
    'app.idle': 'කිසිවක් ස්පර්ශ නොකළ නිසා ඉක්මනින් ඔබ ඉවත් කෙරේ.',
    'app.stayHere': 'මම තවම මෙහි සිටිමි',

    'home.hello': 'ආයුබෝවන්',
    'home.choose': 'ඔබ කරන්නේ කුමක්ද?',
    'home.none': 'ඔබගේ ගිණුමට තවම කාර්යයන් නැත. කරුණාකර කාර්යාලයෙන් විමසන්න.',
    'task.receive': 'භාණ්ඩ ලැබීම',
    'task.receive.hint': 'සැපයුම්කරුවෙකුගෙන් එන භාණ්ඩ',
    'task.dispatch': 'භාණ්ඩ යැවීම',
    'task.dispatch.hint': 'පාරිභෝගිකයෙකුට යවන භාණ්ඩ',
    'task.dehusking': 'ලෙලි ගැසීම',
    'task.dehusking.hint': 'පොල් වල ලෙලි ඉවත් කිරීම',
    'task.husk-chipping': 'කොහු කැපීම',
    'task.husk-chipping.hint': 'ලෙලි කෑලි කිරීම',
    'task.copra': 'කොප්පරා',
    'task.copra.hint': 'පොල් වලින් කොප්පරා සෑදීම',
    'task.stock': 'තොගය බලන්න',
    'task.stock.hint': 'ගබඩාවල ඇති ප්‍රමාණය',

    'step.of': 'පියවර {n} / {total}',
    'common.back': 'ආපසු',
    'common.next': 'ඊළඟ',
    'common.done': 'අවසන්',
    'common.saving': 'සුරකිමින්…',
    'common.search': 'සොයන්න…',
    'common.none': 'තෝරාගත හැකි කිසිවක් නැත',
    'common.optional': 'අවශ්‍ය නම් පමණක් — මඟ හැරිය හැක',
    'common.skip': 'මඟ හරින්න',
    'common.clear': 'මකන්න',
    'common.change': 'වෙනස් කරන්න',
    'common.store': 'ගබඩාව',
    'common.today': 'අද',
    'common.check': 'සියල්ල පරීක්ෂා කර "අවසන්" ඔබන්න',
    'common.error': 'මෙය සුරැකිය නොහැකි විය. කරුණාකර පරීක්ෂා කරන්න:',
    'common.addAnother': 'තවත් භාණ්ඩයක් එකතු කරන්න',
    'common.remove': 'ඉවත් කරන්න',
    'common.each': 'එකක්',
    'common.total': 'එකතුව',
    'common.available': 'ඇත',

    'pick.supplier': 'භාණ්ඩ ගෙනාවේ කවුද?',
    'pick.customer': 'යවන්නේ කාටද?',
    'pick.product': 'කුමන භාණ්ඩයද?',
    'pick.store': 'කුමන ගබඩාවද?',
    'pick.lot': 'කුමන කාණ්ඩයද?',
    'pick.lot.hint': 'භාවිතා කරන කාණ්ඩය තෝරන්න. අලුත්ම ඒවා මුලින්.',
    'lot.received': 'දිනය',
    'lot.left': 'ඉතිරි',

    'enter.count': 'ගණන කීයද? ({uom})',
    'enter.weight': 'බර ({uom})',
    'enter.price': '{uom} එකක මිල (රු.)',
    'enter.priceKg': 'KG එකක මිල (රු.)',
    'enter.transport': 'ප්‍රවාහන වියදම (රු.)',
    'enter.loading': 'පැටවීමේ වියදම (රු.)',
    'enter.vehicle': 'වාහන අංකය',
    'enter.waste': 'අපද්‍රව්‍ය බර',
    'price.by': 'මිල කරන්නේ කෙසේද?',
    'price.byCount': 'ගණන අනුව',
    'price.byWeight': 'බර අනුව (KG)',

    'prod.input': 'සකසන්නේ කීයද?',
    'prod.outputs': 'නිපදවූ දේ කිරන්න',
    'prod.outputs.hint': 'කිරූ බර ඇතුළත් කරන්න. නිපදවූයේ නැති ශ්‍රේණියකට 0 යොදන්න.',
    'prod.copraKg': 'කොප්පරා බර (KG)',
    'prod.shellKg': 'පොල්කටු බර (KG)',
    'prod.result': 'නිපදවූ දේ',
    'prod.auto': 'පද්ධතිය මෙය ගණනය කරයි',

    'stock.title': 'ගබඩාවල තොගය',
    'stock.empty': 'තවම තොගයක් නැත.',
    'stock.inStore': '{store} හි',

    'done.title': 'සුරැකුණා!',
    'done.number': 'අංකය',
    'done.another': 'තවත් එකක් කරන්න',
    'done.home': 'මුල් පිටුවට',
};

export const kioskText: Record<'en' | 'si', Record<KioskTextKey, string>> = { en, si };

/**
 * `t('enter.count', { uom: 'NOS' })` in the device's chosen language.
 */
export function useKioskText() {
    const page = usePage();
    const locale = computed(() => ((page.props.kiosk as { locale?: string } | undefined)?.locale === 'en' ? 'en' : 'si'));

    function t(key: KioskTextKey, values: Record<string, string | number> = {}): string {
        let text = kioskText[locale.value][key] ?? kioskText.en[key];

        for (const [name, value] of Object.entries(values)) {
            text = text.replaceAll(`{${name}}`, String(value));
        }

        return text;
    }

    return { t, locale };
}

/**
 * Show a decimal string without trailing zeros: "10000.000" → "10,000", "208.750" → "208.75".
 * Display only — values are always sent to the server exactly as typed.
 */
export function plainNumber(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const [whole, fraction = ''] = value.split('.');
    const trimmed = fraction.replace(/0+$/, '');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    return trimmed ? `${grouped}.${trimmed}` : grouped;
}
