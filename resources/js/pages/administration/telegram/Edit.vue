<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { BotSettings } from '@/components/administration/TelegramBotCard.vue';
import TelegramBotCard from '@/components/administration/TelegramBotCard.vue';
import Heading from '@/components/Heading.vue';
import { useT } from '@/lib/i18n';
import { edit } from '@/routes/telegram-settings';

type Chat = { id: string; title: string };

const props = defineProps<{
    bots: { reminders: BotSettings; transactions: BotSettings };
    alertTypes: { value: string; label: string }[];
    alertQueue: { pending: number; failed: number };
    foundChats: { bot: string; chats: Chat[] } | null;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Telegram settings', href: edit() }] } });

const { t } = useT();

function found(bot: string): Chat[] {
    return props.foundChats?.bot === bot ? props.foundChats.chats : [];
}
</script>

<template>
    <Head :title="t('Telegram settings')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="Telegram settings" description="Two bots: one for loan reminders, one that reports every transaction. Each can also get the monthly report." />

        <ol class="bg-muted/40 max-w-3xl list-decimal space-y-1 rounded-xl p-4 pl-8 text-sm">
            <li>{{ t('In Telegram, open @BotFather, send /newbot and follow its questions. It gives you a token.') }}</li>
            <li>{{ t('Paste the token below and click Save.') }}</li>
            <li>{{ t('Each administrator sends any message (like "hi") to the new bot — or add the bot to a group and send a message there.') }}</li>
            <li>{{ t('Click Find chats, add the chats that should get the messages, switch the bot on and click Save.') }}</li>
            <li>{{ t('Click Send test message to check it arrives.') }}</li>
        </ol>

        <TelegramBotCard
            bot="reminders"
            title="Reminders bot"
            description="Loan installment reminders, 7 days and 2 days before and on the due day."
            enable-label="Send loan reminders to Telegram too"
            :settings="bots.reminders"
            :found-chats="found('reminders')"
        />

        <TelegramBotCard
            bot="transactions"
            title="Transactions bot"
            description="A message with the details of every transaction as it is posted or reversed. Make a separate bot for this with @BotFather."
            enable-label="Send a message for every transaction"
            :settings="bots.transactions"
            :found-chats="found('transactions')"
            :alert-types="alertTypes"
        />

        <p v-if="alertQueue.pending > 0 || alertQueue.failed > 0" class="text-muted-foreground max-w-3xl text-sm">
            {{ t(':pending messages waiting to be sent, :failed could not be sent.', { pending: alertQueue.pending, failed: alertQueue.failed }) }}
        </p>
    </div>
</template>
