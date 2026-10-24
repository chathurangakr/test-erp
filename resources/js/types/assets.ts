type JournalView = { id: number; journal_number: string; lines: { account_code: string | null; account_name: string | null; debit: string; credit: string }[] };

export type FixedAssetRow = {
    id: number;
    asset_number: string;
    name: string;
    category: string;
    category_label: string;
    purchase_date: string;
    purchase_price: string;
    funding: 'CASH_BANK' | 'LOAN' | 'EXISTING';
    funding_label: string;
    status: 'IN_USE' | 'SOLD' | 'REVERSED';
    status_label: string;
    maintenance_cost?: string;
};

export type AssetMaintenanceRow = {
    id: number;
    maintenance_number: string;
    maintenance_date: string;
    work_done: string;
    done_by: string | null;
    cost: string;
    paid_from: string | null;
    is_reversed: boolean;
    status_label: string;
};

export type FixedAssetDetail = FixedAssetRow & {
    description: string | null;
    down_payment: string;
    paid_from: string | null;
    loan: { id: number; loan_number: string; lender: string; amount: string; outstanding: string } | null;
    sale: { sold_date: string; sale_price: string; buyer: string | null; paid_into: string | null; gain: string; journal: JournalView | null } | null;
    maintenance_cost: string;
    maintenances: AssetMaintenanceRow[];
    journal: JournalView | null;
    created_by: string | null;
    posted_at: string | null;
    reversed_by: string | null;
    reversed_at: string | null;
};
