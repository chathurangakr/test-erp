import { router } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { onMounted, ref } from 'vue';
import type { FlashToast } from '@/types/ui';

/**
 * Beauty mode: an optional, per-device look with colour, gradients and animation.
 *
 * It is purely visual. Switching it on only adds the `beauty` class (and the chosen style as
 * `data-beauty-theme`) to <html>; every rule it enables lives in resources/css/beauty.css under
 * that class, for the screen only, so the ordinary screens and every printout stay exactly as
 * they are when it is off.
 */
const STORAGE_KEY = 'beauty-mode';
const THEME_KEY = 'beauty-theme';

export const BEAUTY_THEMES = [
    {
        value: 'coconut',
        label: 'Coconut Grove',
        swatches: ['#10b981', '#06b6d4', '#8b5cf6', '#f59e0b', '#ec4899'],
    },
    {
        value: 'mulberry',
        label: 'Mulberry Dusk',
        swatches: ['#A67CA4', '#6A8CA9', '#857A9E', '#C9A9B8', '#CBD3D8'],
    },
    {
        value: 'cerulean',
        label: 'Cerulean Sky',
        swatches: ['#8EB1D1', '#A7C7E7', '#1C2B48', '#E8ECEF', '#C4D8E5'],
    },
    {
        value: 'petal',
        label: 'Petal Pastel',
        swatches: ['#F1C6D9', '#EEB3C9', '#DAC2E2', '#C7D7E8', '#9CB6CF'],
    },
] as const;

export type BeautyTheme = (typeof BEAUTY_THEMES)[number]['value'];

const enabled = ref(false);
const theme = ref<BeautyTheme>('coconut');

function read(key: string): string | null {
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
}

function write(key: string, value: string): void {
    try {
        localStorage.setItem(key, value);
    } catch {
        // Storage blocked: the choice still applies until the page is reloaded.
    }
}

function readTheme(): BeautyTheme {
    const stored = read(THEME_KEY);

    return BEAUTY_THEMES.some((option) => option.value === stored)
        ? (stored as BeautyTheme)
        : 'coconut';
}

function apply(isOn: boolean, chosen: BeautyTheme): void {
    document.documentElement.classList.toggle('beauty', isOn);
    document.documentElement.dataset.beautyTheme = chosen;
}

function prefersReducedMotion(): boolean {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * Changes the look the way an iPhone opens an app: the new colours spread out in a circle from
 * where the person tapped, then the screen settles in with a soft spring.
 */
function transition(
    change: () => void,
    origin?: { x: number; y: number },
): void {
    const root = document.documentElement;

    if (
        prefersReducedMotion() ||
        typeof document.startViewTransition !== 'function'
    ) {
        change();

        return;
    }

    const x = origin?.x ?? window.innerWidth / 2;
    const y = origin?.y ?? window.innerHeight / 2;
    const radius = Math.hypot(
        Math.max(x, window.innerWidth - x),
        Math.max(y, window.innerHeight - y),
    );

    root.classList.add('beauty-switching');

    const view = document.startViewTransition(change);

    void view.ready.then(() => {
        root.animate(
            {
                clipPath: [
                    `circle(0px at ${x}px ${y}px)`,
                    `circle(${radius}px at ${x}px ${y}px)`,
                ],
                filter: ['blur(6px) saturate(1.6)', 'blur(0) saturate(1)'],
            },
            {
                duration: 750,
                easing: 'cubic-bezier(0.32, 0.72, 0, 1)',
                pseudoElement: '::view-transition-new(root)',
            },
        );
    });

    void view.finished.finally(() => {
        root.classList.remove('beauty-switching');

        if (root.classList.contains('beauty')) {
            root.classList.add('beauty-launch');
            window.setTimeout(
                () => root.classList.remove('beauty-launch'),
                900,
            );
        }
    });
}

/**
 * A short burst of confetti in the current style's colours, for a successful save.
 */
function celebrate(): void {
    if (prefersReducedMotion()) {
        return;
    }

    const style = getComputedStyle(document.documentElement);
    const colours = [1, 2, 3, 4, 5].map(
        (n) => style.getPropertyValue(`--beauty-p${n}`).trim() || '#10b981',
    );
    const layer = document.createElement('div');
    layer.className = 'beauty-confetti';
    layer.setAttribute('aria-hidden', 'true');

    for (let i = 0; i < 48; i++) {
        const piece = document.createElement('span');
        piece.style.left = `${Math.random() * 100}%`;
        piece.style.background = colours[i % colours.length];
        piece.style.animationDelay = `${Math.random() * 0.4}s`;
        piece.style.animationDuration = `${1.6 + Math.random() * 1.2}s`;
        piece.style.setProperty(
            '--drift',
            `${Math.round(Math.random() * 160 - 80)}px`,
        );
        piece.style.setProperty(
            '--spin',
            `${Math.round(Math.random() * 720 - 360)}deg`,
        );
        layer.appendChild(piece);
    }

    document.body.appendChild(layer);
    window.setTimeout(() => layer.remove(), 3500);
}

export function initializeBeautyMode(): void {
    if (typeof window === 'undefined') {
        return;
    }

    enabled.value = read(STORAGE_KEY) === 'on';
    theme.value = readTheme();
    apply(enabled.value, theme.value);

    router.on('flash', (event) => {
        const data = (event as CustomEvent).detail?.flash?.toast as
            FlashToast | undefined;

        if (
            data?.type === 'success' &&
            document.documentElement.classList.contains('beauty')
        ) {
            celebrate();
        }
    });
}

export function useBeautyMode(): {
    beautyMode: Ref<boolean>;
    beautyTheme: Ref<BeautyTheme>;
    updateBeautyMode: (value: boolean, event?: MouseEvent) => void;
    updateBeautyTheme: (value: BeautyTheme, event?: MouseEvent) => void;
} {
    onMounted(() => {
        enabled.value = read(STORAGE_KEY) === 'on';
        theme.value = readTheme();
    });

    function origin(event?: MouseEvent): { x: number; y: number } | undefined {
        return event && event.clientX + event.clientY > 0
            ? { x: event.clientX, y: event.clientY }
            : undefined;
    }

    function updateBeautyMode(value: boolean, event?: MouseEvent): void {
        enabled.value = value;
        write(STORAGE_KEY, value ? 'on' : 'off');
        transition(() => apply(value, theme.value), origin(event));
    }

    function updateBeautyTheme(value: BeautyTheme, event?: MouseEvent): void {
        theme.value = value;
        write(THEME_KEY, value);

        if (!enabled.value) {
            enabled.value = true;
            write(STORAGE_KEY, 'on');
        }

        transition(() => apply(true, value), origin(event));
    }

    return {
        beautyMode: enabled,
        beautyTheme: theme,
        updateBeautyMode,
        updateBeautyTheme,
    };
}
