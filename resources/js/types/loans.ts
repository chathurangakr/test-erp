export type LoanRow = {
    id: number;
    loan_number: string;
    lender: string;
    start_date: string;
    amount: string;
    outstanding: string;
    paid_off: boolean;
    has_interest: boolean;
    installment_amount: string | null;
    due_day: number | null;
    next_due_date: string | null;
    next_due_paid: boolean;
    days_to_due: number | null;
    status: string;
    status_label: string;
};

export type LoanPaymentRow = {
    id: number;
    payment_number: string;
    payment_date: string;
    amount: string;
    interest_amount: string;
    principal_amount: string;
    paid_from: string | null;
    payment_method_label: string;
    reference: string | null;
    is_reversed: boolean;
    status_label: string;
};

export type LoanDetail = LoanRow & {
    purpose: string | null;
    notes: string | null;
    funding_label: string;
    received_into: string | null;
    principal_paid: string;
    interest_paid: string;
    created_by: string | null;
    posted_at: string | null;
    reversed_by: string | null;
    reversed_at: string | null;
    can_reverse: boolean;
    journal: { id: number; journal_number: string; lines: { account_code: string | null; account_name: string | null; debit: string; credit: string }[] } | null;
    payments: LoanPaymentRow[];
};
