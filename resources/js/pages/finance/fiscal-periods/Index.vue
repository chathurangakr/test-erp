<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref } from 'vue';
import FiscalPeriodController from '@/actions/App/Http/Controllers/Accounting/FiscalPeriodController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/composables/usePermissions';
import { index } from '@/routes/fiscal-periods';
import type { FiscalPeriodRow } from '@/types';

defineProps<{
    periods: FiscalPeriodRow[];
    filters: { search: string };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Fiscal periods', href: index() }] } });

const { can } = usePermissions();

const showForm = ref(false);
</script>

<template>
    <Head title="Fiscal periods" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <Heading
                title="Fiscal periods"
                description="Journals may only be posted into an open period that contains their date."
            />
            <Button v-if="can('finance.period_manage')" class="w-full sm:w-auto" @click="showForm = !showForm">
                <Plus class="size-4" />
                {{ showForm ? 'Close' : 'Open a period' }}
            </Button>
        </div>

        <div
            v-if="showForm && can('finance.period_manage')"
            class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl rounded-xl border p-4 sm:p-6"
        >
            <Form v-bind="FiscalPeriodController.store.form()" v-slot="{ errors, processing }" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="name">Name <span class="text-destructive">*</span></Label>
                        <Input id="name" name="name" required maxlength="100" placeholder="September 2026" />
                        <InputError :message="errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="start_date">Start date <span class="text-destructive">*</span></Label>
                        <Input id="start_date" name="start_date" type="date" required />
                        <InputError :message="errors.start_date" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="end_date">End date <span class="text-destructive">*</span></Label>
                        <Input id="end_date" name="end_date" type="date" required />
                        <InputError :message="errors.end_date" />
                    </div>
                </div>

                <p class="text-muted-foreground text-xs">
                    Periods must not overlap: a journal date inside two of them would have no single
                    answer to which period it belongs to. Nothing here assumes a calendar year.
                </p>

                <Button type="submit" :disabled="processing">Open period</Button>
            </Form>
        </div>

        <div
            v-if="periods.length === 0"
            class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="font-medium">No fiscal periods yet</p>
            <p class="text-muted-foreground text-sm">
                Open one before posting any journal entry — a journal must belong to a period.
            </p>
        </div>

        <div v-else class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">Period</th>
                        <th class="px-4 py-3 font-medium">From</th>
                        <th class="px-4 py-3 font-medium">To</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 text-right font-medium">Entries</th>
                        <th class="px-4 py-3 text-right font-medium">Drafts</th>
                        <th class="px-4 py-3 font-medium">Closed</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="period in periods"
                        :key="period.id"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-t"
                    >
                        <td class="px-4 py-3 font-medium">{{ period.name }}</td>
                        <td class="px-4 py-3">{{ period.start_date }}</td>
                        <td class="px-4 py-3">{{ period.end_date }}</td>
                        <td class="px-4 py-3">
                            <Badge :variant="period.status === 'OPEN' ? 'default' : 'secondary'">
                                {{ period.status_label }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ period.entries_count }}</td>
                        <td class="px-4 py-3 text-right font-mono">
                            <span :class="period.draft_entries_count > 0 ? 'text-destructive' : ''">
                                {{ period.draft_entries_count }}
                            </span>
                        </td>
                        <td class="text-muted-foreground px-4 py-3 text-xs">
                            {{ period.closed_at ? `${period.closed_at} by ${period.closed_by ?? '—'}` : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Form
                                v-if="period.status === 'OPEN' && can('finance.period_manage')"
                                v-bind="FiscalPeriodController.close.form(period.id)"
                                v-slot="{ processing }"
                            >
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="sm"
                                    :disabled="processing || !period.can_close"
                                    :title="period.can_close ? 'Close this period' : 'Post or cancel the drafts first'"
                                >
                                    Close
                                </Button>
                            </Form>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-muted-foreground max-w-3xl text-xs">
            A period cannot be closed while it still holds a draft — the draft would become
            unpostable and invisible to every report. Closing is final: reopening is deliberately
            not offered, because a closed period is the record of something already reported on.
            A reversal of an entry in a closed period posts into the open period containing the
            reversal's own date.
        </p>
    </div>
</template>
