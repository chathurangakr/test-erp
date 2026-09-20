export type DehuskingRow = {
    id: number;
    production_number: string;
    production_date: string | null;
    warehouse_code: string | null;
    status: string;
    status_label: string;
    input_quantity: string;
    input_value: string | null;
    output_value: string | null;
};

export type DehuskingInputRow = {
    product_code: string | null;
    product_name: string | null;
    uom: string | null;
    lot_number: string | null;
    quantity: string;
    unit_cost: string | null;
    total_cost: string | null;
};

export type DehuskingOutputRow = {
    id: number;
    product_code: string | null;
    product_name: string | null;
    uom: string | null;
    lot_number: string | null;
    quantity: string;
    allocation_percent: string;
    allocated_cost: string;
    unit_cost: string;
};

export type DehuskingDetail = DehuskingRow & {
    notes: string | null;
    posted_by: string | null;
    posted_at: string | null;
    created_by: string | null;
    reversed_at: string | null;
    consumption_reference: string | null;
    output_reference: string | null;
    can_post: boolean;
    can_reverse: boolean;
    can_edit: boolean;
    can_cancel: boolean;
    input: DehuskingInputRow | null;
    outputs: DehuskingOutputRow[];
};

export type ProductionLotOption = {
    id: number;
    lot_number: string;
    warehouses: { warehouse_id: number; quantity: string }[];
};

export type ProductionRecipeInfo = {
    input_code: string;
    input_name: string;
    input_uom: string | null;
    outputs: {
        code: string | null;
        name: string | null;
        uom: string | null;
        ratio: string;
        allocation_percent: string;
    }[];
};
