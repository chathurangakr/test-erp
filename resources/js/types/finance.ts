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

/* ---------------------------------------------------------- accounts payable */

export type SupplierInvoiceRow = {
    id: number;
    internal_invoice_number: string;
    supplier_invoice_number: string;
    supplier_code: string | null;
    supplier_name: string | null;
    invoice_date: string | null;
    due_date: string | null;
    status: string;
    status_label: string;
    total: string;
    paid: string;
    outstanding: string;
    is_overdue: boolean;
};

export type SupplierInvoiceLineRow = {
    id: number;
    line_type: string;
    line_type_label: string;
    grn_number: string | null;
    goods_receipt_id: number | null;
    account_code: string | null;
    account_name: string | null;
    description: string | null;
    amount: string;
};

export type JournalPreview = {
    id: number;
    journal_number: string;
    lines: {
        account_code: string | null;
        account_name: string | null;
        description?: string | null;
        debit: string;
        credit: string;
    }[];
} | null;

export type SupplierInvoiceDetail = SupplierInvoiceRow & {
    description: string | null;
    created_by: string | null;
    posted_by: string | null;
    posted_at: string | null;
    reversed_by: string | null;
    reversed_at: string | null;
    can_post: boolean;
    can_edit: boolean;
    can_cancel: boolean;
    can_reverse: boolean;
    journal: JournalPreview;
    reversal_journal_number: string | null;
    lines: SupplierInvoiceLineRow[];
    payments: {
        id: number;
        payment_id: number | null;
        payment_number: string | null;
        payment_date: string | null;
        status: string | null;
        status_label: string | null;
        amount: string;
    }[];
};

export type MatchableGoodsReceipt = {
    id: number;
    grn_number: string;
    receipt_date: string | null;
    supplier_code: string | null;
    original_accrual: string;
    matched_amount: string;
    remaining_amount: string;
};

export type SupplierInvoiceFormValues = {
    id?: number;
    internal_invoice_number?: string;
    supplier_id: number | null;
    supplier_invoice_number: string;
    invoice_date: string | null;
    due_date: string | null;
    description: string | null;
    lines: {
        line_type: string;
        goods_receipt_id: number | null;
        debit_account_id: number | null;
        description: string | null;
        amount: string;
    }[];
};

export type SupplierPaymentRow = {
    id: number;
    payment_number: string;
    supplier_code: string | null;
    supplier_name: string | null;
    payment_date: string | null;
    payment_account: string | null;
    reference: string | null;
    amount: string;
    allocated: string;
    status: string;
    status_label: string;
};

export type SupplierPaymentDetail = SupplierPaymentRow & {
    notes: string | null;
    created_by: string | null;
    posted_by: string | null;
    posted_at: string | null;
    reversed_by: string | null;
    reversed_at: string | null;
    can_post: boolean;
    can_cancel: boolean;
    can_reverse: boolean;
    journal: JournalPreview;
    reversal_journal_number: string | null;
    allocations: {
        id: number;
        invoice_id: number | null;
        internal_invoice_number: string | null;
        supplier_invoice_number: string | null;
        due_date: string | null;
        invoice_status: string | null;
        amount: string;
    }[];
};

export type OutstandingInvoice = {
    id: number;
    internal_invoice_number: string;
    supplier_invoice_number: string;
    invoice_date: string | null;
    due_date: string | null;
    total: string;
    paid: string;
    outstanding: string;
};

export type ApAgingReport = {
    rows: {
        supplier_id: number;
        supplier_code: string | null;
        supplier_name: string | null;
        current: string;
        days_1_30: string;
        days_31_60: string;
        days_61_90: string;
        days_90_plus: string;
        total: string;
    }[];
    totals: Record<string, string>;
    as_of: string;
};

export type GrniReconciliationReport = {
    rows: {
        goods_receipt_id: number;
        grn_number: string;
        receipt_date: string | null;
        supplier_code: string | null;
        supplier_name: string | null;
        original_accrual: string;
        matched_amount: string;
        remaining_amount: string;
        status: string;
    }[];
    original_total: string;
    matched_total: string;
    remaining_total: string;
};

export type ControlAccountCheck = {
    code: string;
    name: string;
    balance: string | null;
    /** The report total and the control account must agree exactly. */
    reconciles: boolean;
} | null;

export type SupplierPayablesSummary = {
    outstanding: string;
    overdue: string;
    currency: string;
    invoices: {
        id: number;
        internal_invoice_number: string;
        supplier_invoice_number: string;
        invoice_date: string | null;
        due_date: string | null;
        status_label: string;
        total: string;
        outstanding: string;
    }[];
    payments: {
        id: number;
        payment_number: string;
        payment_date: string | null;
        status_label: string;
        amount: string;
    }[];
};
