<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Search, Send, X } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import { edit, findChats, test, update } from '@/routes/telegram-settings';

type Chat = { id: string; title: string };

const props = defineProps<{
    settings: { is_enabled: boolean; has_token: boolean; bot_username: string | null; chats: Chat[] };
    foundChats: Chat[] | null;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Telegram settings', href: edit() }] } });

const { t } = useT();

const form = useForm({
    is_enabled: props.settings.is_enabled,
    bot_token: '',
    chats: [...props.settings.chats] as Chat[],
});

const errors = computed(() => form.errors as Record<string, string | undefined>);
/** Find chats and Send test report their problems through the page, not the form. */
const page = usePage();
const actionErrors = computed(() => (page.props.errors ?? {}) as Record<string, string | undefined>);
const unchosen = computed(() => (props.foundChats ?? []).filter((found) => !form.chats.some((chat) => chat.id === found.id)));

function save(): void {
    form.transform((data) => ({ ...data, bot_token: data.bot_token || null })).submit(update(), { preserveScroll: true, onSuccess: () => form.reset('bot_token') });
}

function find(): void {
    router.post(findChats().url, {}, { preserveScroll: true, preserveState: true });
}

function sendTest(): void {
    router.post(test().url, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('Telegram settings')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="Telegram settings" description="Loan reminders can also go to Telegram — to each administrator, or to a group with all of you in it." />

        <ol class="bg-muted/40 max-w-3xl list-decimal space-y-1 rounded-xl p-4 pl-8 text-sm">
            <li>{{ t('In Telegram, open @BotFather, send /newbot and follow its questions. It gives you a token.') }}</li>
            <li>{{ t('Paste the token below and click Save.') }}</li>
            <li>{{ t('Each administrator sends any message (like "hi") to the new bot — or add the bot to a group and send a message there.') }}</li>
            <li>{{ t('Click Find chats, add the chats that should get reminders, switch Telegram on and click Save.') }}</li>
            <li>{{ t('Click Send test message to check it arrives.') }}</li>
        </ol>

        <form class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl space-y-6 rounded-xl border p-4 sm:p-6" @submit.prevent="save">
            <div class="grid gap-2">
                <Label for="bot_token">{{ t('Bot token') }}</Label>
                <PasswordInput id="bot_token" v-model="form.bot_token" autocomplete="off" :placeholder="settings.has_token ? t('Saved — leave blank to keep it') : '123456789:ABC…'" />
                <p v-if="settings.bot_username" class="text-muted-foreground text-xs">{{ t('Connected to the bot @:name', { name: settings.bot_username }) }}</p>
                <InputError :message="errors.bot_token" />
            </div>

            <div class="space-y-2">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <Label>{{ t('Chats that get reminders') }}</Label>
                    <Button type="button" variant="outline" size="sm" :disabled="!settings.has_token" @click="find"><Search class="size-4" />{{ t('Find chats') }}</Button>
                </div>
                <InputError :message="actionErrors.find ?? actionErrors.bot_token" />
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

            <label class="flex items-center gap-3 text-sm">
                <input v-model="form.is_enabled" type="checkbox" class="border-input size-4 rounded" />
                <span class="font-medium">{{ t('Send loan reminders to Telegram too') }}</span>
            </label>
            <InputError :message="errors.is_enabled" />

            <div class="flex flex-wrap gap-2">
                <Button type="submit" :disabled="form.processing">{{ t('Save') }}</Button>
                <Button type="button" variant="outline" :disabled="!settings.has_token || settings.chats.length === 0" @click="sendTest"><Send class="size-4" />{{ t('Send test message') }}</Button>
            </div>
            <InputError :message="actionErrors.test" />
        </form>
    </div>
</template>
