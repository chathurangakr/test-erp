export type JournalView = {
    id: number;
    journal_number: string;
    lines: { account_code: string | null; account_name: string | null; debit: string; credit: string }[];
};

export type EmployeeRow = {
    id: number;
    code: string;
    name: string;
    phone: string | null;
    daily_rate: string;
    is_active: boolean;
    advances_owed?: string;
};

export type EmployeeDetail = EmployeeRow & {
    nic: string | null;
    joined_on: string | null;
    notes: string | null;
};

export type AttendanceStatusValue = 'FULL_DAY' | 'HALF_DAY' | 'LEAVE';

export type AttendanceRegisterRow = {
    employee_id: number;
    code: string;
    name: string;
    is_active: boolean;
    daily_rate: string;
    status: AttendanceStatusValue | null;
    note: string | null;
};

export type EmployeeDay = {
    work_date: string;
    status: AttendanceStatusValue;
    status_label: string;
    daily_rate: string;
    pay: string;
    note: string | null;
    paid: boolean;
};

export type SalaryAdvanceRow = {
    id: number;
    advance_number: string;
    advance_date: string;
    employee_code: string | null;
    employee_name: string | null;
    amount: string;
    payment_account: string | null;
    status: string;
    status_label: string;
    is_reversed: boolean;
};

export type SalaryAdvanceDetail = SalaryAdvanceRow & {
    employee_id: number;
    payment_method_label: string;
    reference: string | null;
    note: string | null;
    created_by: string | null;
    posted_at: string | null;
    reversed_by: string | null;
    reversed_at: string | null;
    reversal_journal_number: string | null;
    can_reverse: boolean;
    journal: JournalView | null;
};

export type PayrollRow = {
    id: number;
    payroll_number: string;
    month: string;
    period_start: string;
    period_end: string;
    pay_date: string;
    employees: number;
    gross_pay: string;
    advances_deducted: string;
    net_pay: string;
    payment_account: string | null;
    status: string;
    status_label: string;
};

export type PayrollLineView = {
    employee_id: number;
    code: string | null;
    name: string | null;
    full_days: number;
    half_days: number;
    leave_days: number;
    gross_pay: string;
    advances_owed?: string;
    advances_deducted: string;
    net_pay: string;
};

export type PayrollDetail = PayrollRow & {
    payment_method_label: string;
    reference: string | null;
    created_by: string | null;
    posted_at: string | null;
    reversed_by: string | null;
    reversed_at: string | null;
    reversal_journal_number: string | null;
    can_reverse: boolean;
    lines: PayrollLineView[];
    journal: JournalView | null;
};
