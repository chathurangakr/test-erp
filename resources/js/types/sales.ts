export type SalesOrderRow = {
    id: number;
    so_number: string;
    customer_code: string | null;
    customer_name: string | null;
    warehouse_code: string | null;
    order_date: string | null;
    expected_delivery_date: string | null;
    status: string;
    status_label: string;
    sales_total: string;
    ordered_quantity: string;
    delivered_quantity: string;
    remaining_quantity: string;
};

export type SalesOrderLineRow = {
    id: number;
    product_code: string | null;
    product_name: string | null;
    uom: string | null;
    secondary_uom: string | null;
    quantity: string;
    secondary_quantity: string | null;
    pricing_basis: string;
    unit_price: string;
    line_total: string;
    delivered_quantity: string;
    outstanding_quantity: string;
};

export type SalesOrderDetail = SalesOrderRow & {
    customer_id: number;
    customer_reference: string | null;
    notes: string | null;
    approved_by: string | null;
    approved_at: string | null;
    created_by: string | null;
    can_approve: boolean;
    can_cancel: boolean;
    can_edit: boolean;
    lines: SalesOrderLineRow[];
    deliveries: {
        id: number;
        delivery_number: string;
        delivery_date: string | null;
        status: string;
        status_label: string;
    }[];
};

export type DeliveryRow = {
    id: number;
    delivery_number: string;
    customer_code: string | null;
    customer_name: string | null;
    warehouse_code: string | null;
    so_number: string | null;
    delivery_date: string | null;
    status: string;
    status_label: string;
    total_quantity: string;
    sales_total: string;
    cogs_total: string | null;
    gross_profit: string | null;
    gross_margin: string | null;
};

export type DeliveryLineRow = {
    id: number;
    product_code: string | null;
    product_name: string | null;
    uom: string | null;
    secondary_uom: string | null;
    lot_number: string | null;
    quantity: string;
    secondary_quantity: string | null;
    pricing_basis: string;
    unit_price: string;
    sales_value: string;
    inventory_unit_cost: string | null;
    /** Includes backflush_cost. */
    cost_of_goods_sold: string | null;
    backflush_quantity: string | null;
    backflush_cost: string | null;
    backflush_code: string | null;
    backflush_uom: string | null;
    gross_profit: string | null;
    gross_margin: string | null;
};

export type DeliveryDetail = DeliveryRow & {
    customer_reference: string | null;
    vehicle_number: string | null;
    driver_name: string | null;
    notes: string | null;
    posted_by: string | null;
    posted_at: string | null;
    created_by: string | null;
    reversed_at: string | null;
    inventory_reference: string | null;
    reversal_reference: string | null;
    backflush_reference: string | null;
    backflush_reversal_reference: string | null;
    can_post: boolean;
    can_reverse: boolean;
    can_edit: boolean;
    can_cancel: boolean;
    lines: DeliveryLineRow[];
};

export type SalesProductOption = {
    id: number;
    code: string;
    name: string;
    uom: string | null;
    secondary_uom: string | null;
    /** False for pooled stock: no lot is chosen. */
    tracks_lots?: boolean;
    /** The raw material a delivery of this product deducts (husk chipping outputs). */
    backflush?: { code: string; name: string; uom: string | null } | null;
    is_active?: boolean;
};

export type LotOption = {
    id: number;
    lot_number: string;
    product_id: number;
    warehouses: { warehouse_id: number; quantity: string }[];
};

export type SalesOrderOption = {
    id: number;
    so_number: string;
    customer_id: number;
    warehouse_id: number;
    lines: {
        id: number;
        product_id: number;
        product_code: string | null;
        uom: string | null;
        pricing_basis: string;
        unit_price: string;
        outstanding_quantity: string;
    }[];
};
