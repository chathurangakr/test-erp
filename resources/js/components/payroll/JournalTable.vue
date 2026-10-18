<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { usePermissions } from '@/composables/usePermissions';
import { index as journalsIndex } from '@/routes/journals';
import type { JournalView } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

defineProps<{ journal: JournalView }>();

const { t } = useT();
const { can } = usePermissions();
</script>

<template>
    <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
        <div class="border-sidebar-border/70 dark:border-sidebar-border border-b px-4 py-3">
            <h3 class="text-sm font-medium">
                {{ t('Journal') }}
                <Link v-if="can('finance.view')" :href="`${journalsIndex().url}/${journal.id}`" class="font-mono text-xs hover:underline">
                    {{ journal.journal_number }}
                </Link>
                <span v-else class="font-mono text-xs">{{ journal.journal_number }}</span>
            </h3>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-muted/50 text-left">
                <tr>
                    <th class="px-4 py-3 font-medium">{{ t('Account') }}</th>
                    <th class="px-4 py-3 text-right font-medium">{{ t('Debit') }}</th>
                    <th class="px-4 py-3 text-right font-medium">{{ t('Credit') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(line, i) in journal.lines" :key="i" class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                    <td class="px-4 py-3">
                        <span class="font-mono text-xs">{{ line.account_code }}</span>
                        <span class="text-muted-foreground block text-xs">{{ line.account_name }}</span>
                    </td>
                    <td class="px-4 py-3 text-right font-mono">{{ Number(line.debit) > 0 ? money(line.debit) : '—' }}</td>
                    <td class="px-4 py-3 text-right font-mono">{{ Number(line.credit) > 0 ? money(line.credit) : '—' }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
