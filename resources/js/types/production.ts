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
        /** Null when the operator measures the output instead of deriving it. */
        ratio: string | null;
        allocation_percent: string;
    }[];
};

/* ---------------------------------------------------------------- husk chipping */

export type HuskChippingRow = {
    id: number;
    production_number: string;
    production_date: string | null;
    warehouse_code: string | null;
    status: string;
    status_label: string;
    input_quantity: string;
    output_quantity: string;
    waste_quantity: string | null;
    input_value: string | null;
    output_value: string | null;
};

export type HuskChippingOutputRow = {
    id: number;
    product_code: string | null;
    product_name: string | null;
    uom: string | null;
    lot_number: string | null;
    quantity: string;
    allocation_percent: string;
    /** Null until the batch is posted and the ledger prices it. */
    allocated_cost: string | null;
    unit_cost: string | null;
};

export type HuskChippingDetail = HuskChippingRow & {
    notes: string | null;
    waste_uom: string | null;
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
    outputs: HuskChippingOutputRow[];
};

export type HuskChippingRecipeInfo = {
    input_code: string;
    input_name: string;
    input_uom: string | null;
    outputs: {
        product_id: number;
        code: string | null;
        name: string | null;
        uom: string | null;
        /** Always null: there is no fixed husk-to-KG conversion. */
        ratio: string | null;
        allocation_percent: string;
    }[];
};

export type HuskChippingFormValues = {
    production_date: string | null;
    warehouse_id: number | null;
    lot_id: number | null;
    quantity: string | null;
    waste_quantity: string | null;
    waste_uom_id: number | null;
    notes: string | null;
    outputs: { product_id: number; quantity: string }[];
};

/* ------------------------------------------------------------------------ copra */

/**
 * One output of the copra recipe, as the server describes it.
 *
 * `ratio` decides how the screen treats the quantity: null means the operator weighs it,
 * a value means the server derives it from the coconuts processed and the field is
 * read-only. `requires_secondary_quantity` means a second measurement — the shell weight —
 * must also be entered.
 */
export type CopraOutputColumn = {
    product_id: number;
    code: string | null;
    name: string | null;
    uom: string | null;
    secondary_uom: string | null;
    ratio: string | null;
    requires_secondary_quantity: boolean;
    allocation_percent: string;
};

export type CopraOutputQuantities = {
    quantity: string;
    secondary_quantity: string | null;
};

export type CopraRow = {
    id: number;
    production_number: string;
    production_date: string | null;
    warehouse_code: string | null;
    status: string;
    status_label: string;
    input_quantity: string;
    /** Keyed by product id, so the index columns follow the recipe. */
    outputs_by_product: Record<string, CopraOutputQuantities>;
    input_value: string | null;
    output_value: string | null;
};

export type CopraOutputRow = {
    id: number;
    product_id: number;
    product_code: string | null;
    product_name: string | null;
    uom: string | null;
    secondary_uom: string | null;
    lot_number: string | null;
    quantity: string;
    secondary_quantity: string | null;
    allocation_percent: string;
    /** Null until the batch is posted and the ledger prices it. */
    allocated_cost: string | null;
    /** Inventory unit cost, always against the primary quantity. */
    unit_cost: string | null;
    /** Allocated cost per measured KG. Display only — never a weighted average. */
    informational_secondary_unit_cost: string | null;
};

export type CopraDetail = CopraRow & {
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
    outputs: CopraOutputRow[];
};

export type CopraRecipeInfo = {
    input_code: string;
    input_name: string;
    input_uom: string | null;
    outputs: CopraOutputColumn[];
};

export type CopraFormValues = {
    production_date: string | null;
    warehouse_id: number | null;
    lot_id: number | null;
    quantity: string | null;
    notes: string | null;
    outputs: {
        product_id: number;
        quantity: string | null;
        secondary_quantity: string | null;
    }[];
};
