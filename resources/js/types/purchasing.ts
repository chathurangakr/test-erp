export type PurchaseOrderRow = {
    id: number;
    po_number: string;
    supplier_code: string | null;
    supplier_name: string | null;
    warehouse_code: string | null;
    order_date: string | null;
    expected_date: string | null;
    status: string;
    status_label: string;
    material_total: string;
    ordered_quantity: string;
    received_quantity: string;
    remaining_quantity: string;
};

export type PurchaseOrderLineRow = {
    id: number;
    product_code: string | null;
    product_name: string | null;
    uom: string | null;
    quantity: string;
    unit_price: string;
    line_total: string;
    received_quantity: string;
    outstanding_quantity: string;
};

export type PurchaseOrderDetail = PurchaseOrderRow & {
    supplier_id: number;
    supplier_reference: string | null;
    notes: string | null;
    approved_by: string | null;
    approved_at: string | null;
    created_by: string | null;
    can_approve: boolean;
    can_cancel: boolean;
    can_edit: boolean;
    lines: PurchaseOrderLineRow[];
    goods_receipts: {
        id: number;
        grn_number: string;
        receipt_date: string | null;
        status: string;
        status_label: string;
    }[];
};

export type GoodsReceiptRow = {
    id: number;
    grn_number: string;
    supplier_code: string | null;
    supplier_name: string | null;
    warehouse_code: string | null;
    po_number: string | null;
    receipt_date: string | null;
    status: string;
    status_label: string;
    total_quantity: string;
    material_total: string;
    landed_total: string;
    grand_total: string;
    is_reversed: boolean;
};

export type GoodsReceiptLineRow = {
    id: number;
    product_code: string | null;
    product_name: string | null;
    uom: string | null;
    secondary_uom: string | null;
    quantity: string;
    secondary_quantity: string | null;
    unit_price: string;
    material_cost: string | null;
    allocated_landed_cost: string | null;
    final_unit_cost: string | null;
    lot_number: string | null;
    supplier_lot_number: string | null;
};

export type GoodsReceiptDetail = GoodsReceiptRow & {
    supplier_delivery_reference: string | null;
    transport_cost: string;
    loading_cost: string;
    other_cost: string;
    notes: string | null;
    posted_by: string | null;
    posted_at: string | null;
    created_by: string | null;
    reversed_at: string | null;
    inventory_reference: string | null;
    reversal_reference: string | null;
    can_post: boolean;
    can_reverse: boolean;
    can_edit: boolean;
    can_cancel: boolean;
    lines: GoodsReceiptLineRow[];
};

export type ProductOption = {
    id: number;
    code: string;
    name: string;
    uom: string | null;
    secondary_uom?: string | null;
    /** False for pooled stock: no lot is created, so no lot details are asked for. */
    tracks_lots?: boolean;
};

export type PurchaseOrderOption = {
    id: number;
    po_number: string;
    supplier_id: number;
    warehouse_id: number;
    lines: {
        id: number;
        product_id: number;
        product_code: string | null;
        uom: string | null;
        unit_price: string;
        outstanding_quantity: string;
    }[];
};

export type StatusOption = { value: string; label: string };
