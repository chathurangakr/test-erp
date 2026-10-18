<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, store, update } from '@/routes/employees';
import type { EmployeeDetail } from '@/types';
import { useT } from '@/lib/i18n';

const props = defineProps<{ employee?: EmployeeDetail; nextCode?: string }>();

const form = useForm({
    name: props.employee?.name ?? '',
    nic: props.employee?.nic ?? '',
    phone: props.employee?.phone ?? '',
    daily_rate: props.employee?.daily_rate ?? '',
    joined_on: props.employee?.joined_on ?? '',
    is_active: props.employee?.is_active ?? true,
    notes: props.employee?.notes ?? '',
});

function submit(): void {
    form.submit(props.employee ? update(props.employee.id) : store());
}

const { t } = useT();
</script>

<template>
    <form class="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl space-y-6 rounded-xl border p-4 sm:p-6" @submit.prevent="submit">
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="code">{{ t('Code') }}</Label>
                <Input id="code" :model-value="employee?.code ?? nextCode ?? ''" disabled class="font-mono" />
                <p v-if="!employee" class="text-muted-foreground text-xs">{{ t('Given automatically when you save.') }}</p>
            </div>
            <div class="grid gap-2">
                <Label for="name">{{ t('Name') }}</Label>
                <Input id="name" v-model="form.name" required maxlength="150" />
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="daily_rate">{{ t('Daily salary rate') }}</Label>
                <Input id="daily_rate" v-model="form.daily_rate" required inputmode="decimal" placeholder="2000.00" />
                <p class="text-muted-foreground text-xs">
                    {{ t('Used for days recorded from now on. Days already recorded keep their own rate.') }}
                </p>
                <InputError :message="form.errors.daily_rate" />
            </div>
            <div class="grid gap-2">
                <Label for="joined_on">{{ t('Joined on') }}</Label>
                <Input id="joined_on" v-model="form.joined_on" type="date" />
                <InputError :message="form.errors.joined_on" />
            </div>
            <div class="grid gap-2">
                <Label for="nic">{{ t('NIC number') }}</Label>
                <Input id="nic" v-model="form.nic" maxlength="20" />
                <InputError :message="form.errors.nic" />
            </div>
            <div class="grid gap-2">
                <Label for="phone">{{ t('Phone') }}</Label>
                <Input id="phone" v-model="form.phone" maxlength="30" />
                <InputError :message="form.errors.phone" />
            </div>
            <div class="grid gap-2 sm:col-span-2">
                <Label for="notes">{{ t('Notes') }}</Label>
                <Input id="notes" v-model="form.notes" maxlength="500" />
                <InputError :message="form.errors.notes" />
            </div>
        </div>

        <label class="flex items-start gap-3">
            <input v-model="form.is_active" type="checkbox" class="mt-1 size-4" />
            <span>
                <span class="block text-sm font-medium">{{ t('Active') }}</span>
                <span class="text-muted-foreground block text-xs">
                    {{ t('An inactive employee is left off the attendance register. Their records stay.') }}
                </span>
            </span>
        </label>

        <div class="flex gap-2">
            <Button type="submit" :disabled="form.processing">{{ employee ? t('Save employee') : t('Add employee') }}</Button>
            <Button variant="outline" as-child><Link :href="index()">{{ t('Cancel') }}</Link></Button>
        </div>
    </form>
</template>
