<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { usePermissions } from '@/composables/usePermissions';
import { index, update } from '@/routes/attendance';
import { show as payrollShow } from '@/routes/payrolls';
import type { AttendanceRegisterRow, AttendanceStatusValue } from '@/types';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

const props = defineProps<{
    date: string;
    today: string;
    paidPayroll: { id: number; payroll_number: string } | null;
    statuses: { value: AttendanceStatusValue; label: string }[];
    rows: AttendanceRegisterRow[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Attendance', href: index() }] } });

const { t } = useT();
const { can } = usePermissions();

const form = useForm({
    work_date: props.date,
    entries: props.rows.map((row) => ({ employee_id: row.employee_id, status: row.status, note: row.note ?? '' })),
});

const locked = computed(() => props.paidPayroll !== null || !can('payroll.attendance_record'));

const day = ref(props.date);
watch(day, (value) => {
    if (value) {
        router.get(index().url, { date: value }, { preserveState: false });
    }
});

function shift(days: number): void {
    const next = new Date(`${props.date}T00:00:00`);
    next.setDate(next.getDate() + days);
    day.value = `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}-${String(next.getDate()).padStart(2, '0')}`;
}

function markEveryone(status: AttendanceStatusValue): void {
    form.entries.forEach((entry) => {
        if (entry.status === null) {
            entry.status = status;
        }
    });
}

function pay(row: AttendanceRegisterRow, status: AttendanceStatusValue | null): number {
    if (status === 'FULL_DAY') return Number(row.daily_rate);
    if (status === 'HALF_DAY') return Math.round(Number(row.daily_rate) * 50) / 100;
    return 0;
}

const summary = computed(() => ({
    full: form.entries.filter((e) => e.status === 'FULL_DAY').length,
    half: form.entries.filter((e) => e.status === 'HALF_DAY').length,
    leave: form.entries.filter((e) => e.status === 'LEAVE').length,
    blank: form.entries.filter((e) => e.status === null).length,
    pay: props.rows.reduce((sum, row, i) => sum + pay(row, form.entries[i].status), 0),
}));

function submit(): void {
    form.submit(update(), { preserveScroll: true });
}

const tone: Record<AttendanceStatusValue, string> = {
    FULL_DAY: 'bg-green-600 text-white border-green-600',
    HALF_DAY: 'bg-amber-500 text-white border-amber-500',
    LEAVE: 'bg-red-600 text-white border-red-600',
};
</script>

<template>
    <Head :title="t('Attendance')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading title="Attendance" description="Mark each employee for the day. Full day pays the day's rate, half day half of it, leave nothing." />

        <div class="flex flex-wrap items-center gap-2">
            <Button variant="outline" size="icon" :aria-label="t('Previous day')" @click="shift(-1)"><ChevronLeft class="size-4" /></Button>
            <Input v-model="day" type="date" :max="today" class="w-44" />
            <Button variant="outline" size="icon" :aria-label="t('Next day')" :disabled="date >= today" @click="shift(1)"><ChevronRight class="size-4" /></Button>
            <Button v-if="date !== today" variant="ghost" @click="day = today">{{ t('Today') }}</Button>
        </div>

        <div v-if="paidPayroll" class="rounded-xl border border-dashed p-4 text-sm">
            {{ t('Salaries for this month are already paid, so this day cannot be changed.') }}
            <Link :href="payrollShow(paidPayroll.id)" class="font-mono hover:underline">{{ paidPayroll.payroll_number }}</Link>
        </div>

        <div class="grid gap-3 sm:grid-cols-5">
            <div class="rounded-xl border p-3"><p class="text-muted-foreground text-xs">{{ t('Full day') }}</p><p class="text-lg font-medium">{{ summary.full }}</p></div>
            <div class="rounded-xl border p-3"><p class="text-muted-foreground text-xs">{{ t('Half day') }}</p><p class="text-lg font-medium">{{ summary.half }}</p></div>
            <div class="rounded-xl border p-3"><p class="text-muted-foreground text-xs">{{ t('Leave') }}</p><p class="text-lg font-medium">{{ summary.leave }}</p></div>
            <div class="rounded-xl border p-3"><p class="text-muted-foreground text-xs">{{ t('Not marked') }}</p><p class="text-lg font-medium">{{ summary.blank }}</p></div>
            <div class="rounded-xl border p-3"><p class="text-muted-foreground text-xs">{{ t("Day's wages") }}</p><p class="font-mono text-lg font-medium">{{ money(String(summary.pay)) }}</p></div>
        </div>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div v-if="!locked && rows.length > 0" class="flex flex-wrap gap-2">
                <Button type="button" variant="outline" @click="markEveryone('FULL_DAY')">{{ t('Mark everyone not marked as full day') }}</Button>
            </div>

            <div class="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ t('Employee') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Day') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('Note') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('Pay') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, i) in rows" :key="row.employee_id" class="border-sidebar-border/70 dark:border-sidebar-border border-t">
                            <td class="px-4 py-3">
                                <span class="font-medium">{{ row.name }}</span>
                                <span class="text-muted-foreground block font-mono text-xs">
                                    {{ row.code }} · {{ money(row.daily_rate) }}
                                    <span v-if="!row.is_active"> · {{ t('Inactive') }}</span>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <button
                                        v-for="status in statuses"
                                        :key="status.value"
                                        type="button"
                                        :disabled="locked"
                                        class="rounded-md border px-3 py-1.5 text-sm disabled:cursor-not-allowed disabled:opacity-60"
                                        :class="form.entries[i].status === status.value ? tone[status.value] : 'hover:bg-muted'"
                                        @click="form.entries[i].status = form.entries[i].status === status.value ? null : status.value"
                                    >
                                        {{ status.label }}
                                    </button>
                                </div>
                                <InputError :message="(form.errors as Record<string, string | undefined>)[`entries.${i}.employee_id`]" />
                            </td>
                            <td class="px-4 py-3">
                                <Input v-model="form.entries[i].note" :disabled="locked" maxlength="255" class="min-w-32" />
                            </td>
                            <td class="px-4 py-3 text-right font-mono">{{ money(String(pay(row, form.entries[i].status))) }}</td>
                        </tr>
                        <tr v-if="rows.length === 0">
                            <td colspan="4" class="text-muted-foreground px-4 py-8 text-center">{{ t('No active employees. Add employees first.') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <InputError :message="form.errors.work_date" />

            <div v-if="!locked && rows.length > 0" class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">{{ t('Save attendance') }}</Button>
                <span class="text-muted-foreground text-xs">{{ t('Tap a selected button again to clear it. A day left blank is not paid.') }}</span>
            </div>
        </form>
    </div>
</template>
