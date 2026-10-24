<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Send, Unlink } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { destroy, store } from '@/routes/telegram-link';

defineProps<{
    telegram: { available: boolean; linked: boolean; username: string | null; linked_at: string | null };
}>();

const { t } = useT();
const page = usePage();
const error = computed(() => (page.props.errors as Record<string, string | undefined>).telegram);

function link(): void {
    router.post(store().url, {}, { preserveScroll: true });
}

function unlink(): void {
    router.delete(destroy().url, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-4">
        <Heading variant="small" title="Telegram" description="Link your Telegram to ask the ERP bot for stock, cash, today's figures and money owed." />

        <p v-if="!telegram.available && !telegram.linked" class="text-muted-foreground text-sm">
            {{ t('Telegram commands are not switched on. Ask an administrator.') }}
        </p>

        <div v-else-if="telegram.linked" class="space-y-3">
            <p class="text-sm">
                {{ telegram.username ? t('Linked to @:name', { name: telegram.username }) : t('Linked') }}
                <span v-if="telegram.linked_at" class="text-muted-foreground"> · {{ telegram.linked_at }}</span>
            </p>
            <p class="text-muted-foreground text-sm">{{ t('In Telegram, open the bot and type / to see the commands.') }}</p>
            <div class="flex flex-wrap gap-2">
                <Button v-if="telegram.available" variant="outline" @click="link"><Send class="size-4" />{{ t('Link a different Telegram') }}</Button>
                <Button variant="outline" @click="unlink"><Unlink class="size-4" />{{ t('Unlink') }}</Button>
            </div>
        </div>

        <div v-else class="space-y-3">
            <p class="text-muted-foreground text-sm">{{ t('Click the button, Telegram opens the bot, then press Start. The link works once, for 15 minutes.') }}</p>
            <Button @click="link"><Send class="size-4" />{{ t('Link my Telegram') }}</Button>
        </div>

        <InputError :message="error" />
    </div>
</template>
