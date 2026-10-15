export type StockRow = {
    product_id: number;
    product_code: string;
    product_name: string;
    product_is_active: boolean;
    warehouse_id: number;
    warehouse_code: string | null;
    warehouse_name: string | null;
    quantity: string;
    primary_uom: string | null;
    secondary_quantity: string | null;
    secondary_uom: string | null;
    unit_cost: string;
    value: string;
};

export type LedgerRow = {
    id: number;
    date: string | null;
    reference: string | null;
    type: string | null;
    type_label: string | null;
    is_reversal: boolean;
    product_code: string | null;
    product_name: string | null;
    warehouse_code: string | null;
    lot_number: string | null;
    direction: string;
    in_quantity: string | null;
    out_quantity: string | null;
    primary_uom: string | null;
    secondary_quantity: string | null;
    secondary_uom: string | null;
    unit_cost: string;
    total_cost: string;
    user: string | null;
};

export type LotRow = {
    id: number;
    lot_number: string;
    supplier_lot_number: string | null;
    product_code: string | null;
    product_name: string | null;
    primary_uom: string | null;
    source_type: string | null;
    /** Display name of the document that created the lot, e.g. "Production". */
    source: string | null;
    manufactured_at: string | null;
    received_at: string | null;
    quantity: string;
    warehouses: { code: string | null; quantity: string }[];
    is_active: boolean;
};

export type OptionRef = { id: number; code: string; name?: string };

export type TransactionTypeOption = { value: string; label: string };
