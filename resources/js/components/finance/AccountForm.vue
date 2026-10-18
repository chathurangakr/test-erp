<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { useT } from '@/lib/i18n';
import type { AccountFormValues, AccountOption, StatusOption } from '@/types';

const props = defineProps<{
    method: 'post' | 'put';
    action: string;
    submitLabel: string;
    cancelHref: string;
    types: StatusOption[];
    normalBalances: StatusOption[];
    parents: AccountOption[];
    defaultNormalBalances: Record<string, string>;
    initial?: AccountFormValues | null;
}>();

const form = useForm({
    code: props.initial?.code ?? '',
    name: props.initial?.name ?? '',
    account_type: props.initial?.account_type ?? 'ASSET',
    normal_balance: props.initial?.normal_balance ?? 'DEBIT',
    parent_id: props.initial?.parent_id ? String(props.initial.parent_id) : '',
    is_control_account: props.initial?.is_control_account ?? false,
    allow_manual_posting: props.initial?.allow_manual_posting ?? true,
    is_active: props.initial?.is_active ?? true,
    description: props.initial?.description ?? '',
});

// Choosing a type fills in the conventional side, which is right nearly always and still
// explicit in the stored record.
watch(
    () => form.account_type,
    (type) => {
        form.normal_balance = props.defaultNormalBalances[type] ?? form.normal_balance;
    },
);

function submit(): void {
    form
        .transform((data) => ({
            ...data,
            parent_id: data.parent_id === '' ? null : Number(data.parent_id),
        }))
        .submit(props.method, props.action, { preserveScroll: true });
}


const { t } = useT();
</script>

<template>
    <form class="space-y-8" @submit.prevent="submit">
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="code">{{ t('Code') }} <span class="text-destructive">*</span></Label>
                <Input id="code" v-model="form.code" required maxlength="20" />
                <InputError :message="form.errors.code" />
            </div>

            <div class="grid gap-2">
                <Label for="name">{{ t('Name') }} <span class="text-destructive">*</span></Label>
                <Input id="name" v-model="form.name" required maxlength="255" />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="account_type">{{ t('Type') }} <span class="text-destructive">*</span></Label>
                <SearchableSelect
                    id="account_type"
                    v-model="form.account_type"
                    required
                    :options="[...(types).map((option) => ({ value: option.value, label: `${(option.label) ?? ''}` }))]"
                />
                <InputError :message="form.errors.account_type" />
            </div>

            <div class="grid gap-2">
                <Label for="normal_balance">{{ t('Normal balance') }} <span class="text-destructive">*</span></Label>
                <SearchableSelect
                    id="normal_balance"
                    v-model="form.normal_balance"
                    required
                    :options="[...(normalBalances).map((b) => ({ value: b.value, label: `${(b.label) ?? ''}` }))]"
                />
                <InputError :message="form.errors.normal_balance" />
            </div>

            <div class="grid gap-2 sm:col-span-2">
                <Label for="parent_id">{{ t('Parent account') }}</Label>
                <SearchableSelect
                    id="parent_id"
                    v-model="form.parent_id"
                    :options="[{ value: '', label: t('None — this is a top-level account') }, ...(parents).map((p) => ({ value: String(p.id), label: `${p.code} — ${p.name}` }))]"
                />
                <InputError :message="form.errors.parent_id" />
            </div>

            <div class="grid gap-2 sm:col-span-2">
                <Label for="description">{{ t('Description') }}</Label>
                <textarea
                    id="description"
                    v-model="form.description"
                    rows="3"
                    class="border-input bg-background focus-visible:ring-ring dark:bg-input/30 flex w-full rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                />
                <InputError :message="form.errors.description" />
            </div>
        </div>

        <div class="space-y-3">
            <label class="flex items-start gap-3 text-sm">
                <input v-model="form.is_control_account" type="checkbox" class="mt-1" />
                <span>
                    <span class="font-medium">{{ t('Control account') }}</span>
                    <span class="text-muted-foreground block text-xs">
                        {{ t('Reconciled from a subsidiary ledger. Manual journal lines are blocked; only the system action that owns that ledger may post here.') }}
                    </span>
                </span>
            </label>

            <label class="flex items-start gap-3 text-sm">
                <input v-model="form.allow_manual_posting" type="checkbox" class="mt-1" />
                <span>
                    <span class="font-medium">{{ t('Allow manual posting') }}</span>
                    <span class="text-muted-foreground block text-xs">
                        {{ t('Turn this off for group headers, so a total is never posted to alongside its own children.') }}
                    </span>
                </span>
            </label>

            <label class="flex items-start gap-3 text-sm">
                <input v-model="form.is_active" type="checkbox" class="mt-1" />
                <span>
                    <span class="font-medium">{{ t('Active') }}</span>
                    <span class="text-muted-foreground block text-xs">
                        {{ t('An account with history is deactivated, never deleted.') }}
                    </span>
                </span>
            </label>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <Button type="submit" :disabled="form.processing">{{ t(submitLabel) }}</Button>
            <Button variant="outline" as-child><a :href="cancelHref">{{ t('Cancel') }}</a></Button>
        </div>
    </form>
</template>
