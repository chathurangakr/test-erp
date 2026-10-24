<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { CalendarDays, Search, Send, Terminal, X } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import { commands, findChats, monthly, test, update } from '@/routes/telegram-settings';

type Chat = { id: string; title: string };
export type BotSettings = {
    is_enabled: boolean;
    has_token: boolean;
    bot_username: string | null;
    chats: Chat[];
    alert_types: string[];
    sends_monthly_report: boolean;
    commands_enabled: boolean;
};

const props = defineProps<{
    bot: 'reminders' | 'transactions';
    title: string;
    description: string;
    enableLabel: string;
    settings: BotSettings;
    foundChats: Chat[];
    /** The kinds of transaction that can be reported — the transactions bot only. */
    alertTypes?: { value: string; label: string }[];
}>();

const { t } = useT();

const form = useForm({
    bot: props.bot,
    is_enabled: props.settings.is_enabled,
    bot_token: '',
    chats: [...props.settings.chats] as Chat[],
    alert_types: [...props.settings.alert_types],
    sends_monthly_report: props.settings.sends_monthly_report,
});

const errors = computed(() => form.errors as Record<string, string | undefined>);
/** Find chats, Send test and the monthly report report their problems through the page. */
const page = usePage();
const actionErrors = computed(() => (page.props.errors ?? {}) as Record<string, string | undefined>);
const unchosen = computed(() => props.foundChats.filter((found) => !form.chats.some((chat) => chat.id === found.id)));
const ready = computed(() => props.settings.has_token && props.settings.chats.length > 0);
const allTypes = computed(() => (props.alertTypes ?? []).every((type) => form.alert_types.includes(type.value)));

function save(): void {
    form.transform((data) => ({ ...data, bot_token: data.bot_token || null })).submit(update(), { preserveScroll: true, onSuccess: () => form.reset('bot_token') });
}

function toggleAll(): void {
    form.alert_types = allTypes.value ? [] : (props.alertTypes ?? []).map((type) => type.value);
}

function post(url: string): void {
    router.post(url, { bot: props.bot }, { preserveScroll: true, preserveState: true });
}

function switchCommands(enabled: boolean): void {
    router.post(commands().url, { enabled }, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <form class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl space-y-6 rounded-xl border p-4 sm:p-6" @submit.prevent="save">
        <div>
            <h2 class="text-lg font-semibold">{{ t(title) }}</h2>
            <p class="text-muted-foreground text-sm">{{ t(description) }}</p>
        </div>

        <div class="grid gap-2">
            <Label :for="`${bot}_token`">{{ t('Bot token') }}</Label>
            <PasswordInput :id="`${bot}_token`" v-model="form.bot_token" autocomplete="off" :placeholder="settings.has_token ? t('Saved — leave blank to keep it') : '123456789:ABC…'" />
            <p v-if="settings.bot_username" class="text-muted-foreground text-xs">{{ t('Connected to the bot @:name', { name: settings.bot_username }) }}</p>
            <InputError :message="errors.bot_token" />
        </div>

        <div class="space-y-2">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <Label>{{ t('Chats that get the messages') }}</Label>
                <Button type="button" variant="outline" size="sm" :disabled="!settings.has_token" @click="post(findChats().url)"><Search class="size-4" />{{ t('Find chats') }}</Button>
            </div>
            <InputError :message="actionErrors[`${bot}_find`]" />
            <ul class="divide-y rounded-lg border">
                <li v-for="(chat, i) in form.chats" :key="chat.id" class="flex items-center justify-between px-3 py-2 text-sm">
                    <span>{{ chat.title }}</span>
                    <Button type="button" variant="ghost" size="icon" :aria-label="t('Remove')" @click="form.chats.splice(i, 1)"><X class="size-4" /></Button>
                </li>
                <li v-if="form.chats.length === 0" class="text-muted-foreground px-3 py-3 text-sm">{{ t('No chats chosen yet.') }}</li>
            </ul>
            <div v-if="unchosen.length > 0" class="space-y-1">
                <p class="text-muted-foreground text-xs">{{ t('Found — click to add:') }}</p>
                <div class="flex flex-wrap gap-2">
                    <Button v-for="chat in unchosen" :key="chat.id" type="button" variant="secondary" size="sm" @click="form.chats.push(chat)">+ {{ chat.title }}</Button>
                </div>
            </div>
        </div>

        <div v-if="alertTypes" class="space-y-2">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <Label>{{ t('Send a message for') }}</Label>
                <Button type="button" variant="ghost" size="sm" @click="toggleAll">{{ allTypes ? t('Untick all') : t('Tick all') }}</Button>
            </div>
            <div class="grid gap-2 rounded-lg border p-3 sm:grid-cols-2">
                <label v-for="type in alertTypes" :key="type.value" class="flex items-center gap-2 text-sm">
                    <input v-model="form.alert_types" type="checkbox" :value="type.value" class="border-input size-4 rounded" />
                    <span>{{ t(type.label) }}</span>
                </label>
            </div>
            <p class="text-muted-foreground text-xs">{{ t('Each one is sent when it is posted and again if it is reversed, within a minute.') }}</p>
            <InputError :message="errors.alert_types" />
        </div>

        <div class="space-y-3">
            <label class="flex items-center gap-3 text-sm">
                <input v-model="form.is_enabled" type="checkbox" class="border-input size-4 rounded" />
                <span class="font-medium">{{ t(enableLabel) }}</span>
            </label>
            <InputError :message="errors.is_enabled" />
            <label class="flex items-center gap-3 text-sm">
                <input v-model="form.sends_monthly_report" type="checkbox" class="border-input size-4 rounded" />
                <span>{{ t('Also send the monthly report here on the 1st of each month') }}</span>
            </label>
        </div>

        <div v-if="alertTypes" class="bg-muted/40 space-y-2 rounded-lg p-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p class="text-sm font-medium">{{ t('Commands: /stock, /cash, /today, /owed') }}</p>
                    <p class="text-muted-foreground text-xs">
                        {{ settings.commands_enabled ? t('On. People who link their Telegram (Settings → Profile) can ask the bot, and see only what their ERP permissions allow.') : t('Off. Switch on (from the cloud server — Telegram must be able to reach it) to let people ask the bot for figures in a private chat.') }}
                    </p>
                </div>
                <Button v-if="settings.commands_enabled" type="button" variant="outline" size="sm" @click="switchCommands(false)"><Terminal class="size-4" />{{ t('Switch commands off') }}</Button>
                <Button v-else type="button" variant="outline" size="sm" :disabled="!settings.has_token" @click="switchCommands(true)"><Terminal class="size-4" />{{ t('Switch commands on') }}</Button>
            </div>
            <InputError :message="actionErrors.transactions_commands" />
        </div>

        <div class="flex flex-wrap gap-2">
            <Button type="submit" :disabled="form.processing">{{ t('Save') }}</Button>
            <Button type="button" variant="outline" :disabled="!ready" @click="post(test().url)"><Send class="size-4" />{{ t('Send test message') }}</Button>
            <Button type="button" variant="outline" :disabled="!ready" @click="post(monthly().url)"><CalendarDays class="size-4" />{{ t("Send last month's report now") }}</Button>
        </div>
        <InputError :message="actionErrors[`${bot}_test`] ?? actionErrors[`${bot}_monthly`]" />
    </form>
</template>
