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
    /** The part of allocated_cost that is dehusking labour. */
    labour_cost: string | null;
    unit_cost: string;
};

export type DehuskingDetail = DehuskingRow & {
    notes: string | null;
    posted_by: string | null;
    posted_at: string | null;
    created_by: string | null;
    reversed_at: string | null;
    labour_rate: string | null;
    labour_cost: string | null;
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

/** One dehusking recipe per coconut size, with what the form needs for that size. */
export type ProductionRecipeOption = ProductionRecipeInfo & {
    input_product_id: number;
    input_stock: string;
    lots: ProductionLotOption[];
};

export type ProductionRecipeInfo = {
    input_code: string;
    input_name: string;
    input_uom: string | null;
    /** False for pooled stock: no lot is chosen. */
    input_tracks_lots?: boolean;
    /** Dehusking: pre-fills the labour rate per coconut; null until configured. */
    default_labour_rate?: string | null;
    outputs: {
        code: string | null;
        name: string | null;
        uom: string | null;
        /** Null when the operator measures the output instead of deriving it. */
        ratio: string | null;
        allocation_percent: string;
        /** Carries the run's labour as its cost (dehusking raw husk). */
        absorbs_labour?: boolean;
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
    /** Null for a run that took no raw husk (it is backflushed at delivery). */
    input_quantity: string | null;
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
    /** True when the run took no raw husk: its material cost arrives at delivery. */
    pending_backflush: boolean;
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
        decimals: number;
    }[];
};

export type HuskChippingFormValues = {
    production_date: string | null;
    warehouse_id: number | null;
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
    /** A weight can be entered — required when `requires_secondary_quantity`, otherwise optional. */
    records_weight: boolean;
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
    /** False for pooled dehusked coconut: no lot is chosen. */
    input_tracks_lots?: boolean;
    outputs: CopraOutputColumn[];
};

/** One copra recipe per dehusked coconut size, with what the form needs for that size. */
export type CopraRecipeOption = CopraRecipeInfo & {
    input_product_id: number;
    input_stock: string;
    input_unit_cost: string;
    lots: ProductionLotOption[];
};

export type CopraFormValues = {
    production_date: string | null;
    warehouse_id: number | null;
    input_product_id?: number | null;
    lot_id: number | null;
    quantity: string | null;
    notes: string | null;
    outputs: {
        product_id: number;
        quantity: string | null;
        secondary_quantity: string | null;
    }[];
};
