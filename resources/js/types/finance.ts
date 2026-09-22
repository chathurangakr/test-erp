export type AccountRow = {
    id: number;
    code: string;
    name: string;
    account_type: string;
    account_type_label: string;
    normal_balance: string;
    normal_balance_label: string;
    parent_code: string | null;
    parent_name: string | null;
    is_control_account: boolean;
    allow_manual_posting: boolean;
    accepts_manual_posting: boolean;
    is_active: boolean;
};

export type AccountDetail = AccountRow & {
    description: string | null;
    children: { id: number; code: string; name: string; is_active: boolean }[];
    debit_total: string;
    credit_total: string;
    balance: string;
    has_history: boolean;
};

export type AccountFormValues = {
    id?: number;
    code: string;
    name: string;
    account_type: string;
    normal_balance: string;
    parent_id: number | null;
    is_control_account: boolean;
    allow_manual_posting: boolean;
    is_active: boolean;
    description: string | null;
};

export type AccountOption = {
    id: number;
    code: string;
    name: string;
    account_type_label?: string;
};

export type JournalLineRow = {
    id: number;
    account_id: number;
    account_code: string | null;
    account_name: string | null;
    description: string | null;
    debit: string;
    credit: string;
};

export type JournalRow = {
    id: number;
    journal_number: string;
    journal_date: string | null;
    description: string;
    status: string;
    status_label: string;
    fiscal_period: string | null;
    debit_total: string;
    credit_total: string;
    difference: string;
    is_balanced: boolean;
    source: string;
};

export type JournalDetail = JournalRow & {
    created_by: string | null;
    posted_by: string | null;
    posted_at: string | null;
    reversed_by_user: string | null;
    reversed_at: string | null;
    reversal_of: { id: number; journal_number: string } | null;
    reversed_by: { id: number; journal_number: string } | null;
    period_status: string | null;
    can_post: boolean;
    can_edit: boolean;
    can_cancel: boolean;
    can_reverse: boolean;
    lines: JournalLineRow[];
};

export type JournalFormValues = {
    id?: number;
    journal_number?: string;
    journal_date: string | null;
    description: string;
    lines: {
        account_id: number | null;
        description: string | null;
        debit: string;
        credit: string;
    }[];
};

export type FiscalPeriodRow = {
    id: number;
    name: string;
    start_date: string;
    end_date: string;
    status: string;
    status_label: string;
    closed_at: string | null;
    closed_by: string | null;
    entries_count: number;
    draft_entries_count: number;
    can_close: boolean;
};

export type GeneralLedgerRow = {
    id: number;
    journal_entry_id: number;
    journal_number: string | null;
    journal_date: string | null;
    description: string | null;
    source: string;
    debit: string;
    credit: string;
    /** Calculated per request; never stored. */
    running_balance: string;
};

export type GeneralLedgerReport = {
    opening_balance: string;
    rows: GeneralLedgerRow[];
    debit_total: string;
    credit_total: string;
    closing_balance: string;
};

export type TrialBalanceRow = {
    account_id: number;
    code: string;
    name: string;
    type: string;
    type_label: string;
    debit: string;
    credit: string;
};

export type TrialBalanceReport = {
    rows: TrialBalanceRow[];
    debit_total: string;
    credit_total: string;
    /** Must always be true: every posted entry balances. */
    balanced: boolean;
};

/* ------------------------------------------------------- accounting setup */

export type AccountRef = { id: number; code: string; name: string } | null;

export type AccountingSettingsView = {
    base_currency: string;
    /** Null until an accountant switches operational accounting on. */
    operational_accounting_start_date: string | null;
    grni_account: AccountRef;
    /** True once operational journals exist: the cutover can no longer move. */
    cutover_locked: boolean;
    operational_journal_count: number;
};

export type ProductMappingRow = {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
    inventory_account: AccountRef;
    cogs_account: AccountRef;
    revenue_account: AccountRef;
    is_mapped: boolean;
};
