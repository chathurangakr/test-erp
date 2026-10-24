/** Shapes of the choices the server sends to simple-mode screens (App\Services\Kiosk\KioskOptions). */

export type KioskParty = { id: number; code: string; name: string };

export type KioskStore = { id: number; code: string; name: string; is_default: boolean };

export type KioskProduct = {
    id: number;
    code: string;
    name: string;
    uom: string | null;
    decimals: number;
    secondary_uom: string | null;
    secondary_decimals: number;
};

export type KioskStockRow = { warehouse_id: number; quantity: string };

export type KioskLot = {
    id: number;
    lot_number: string;
    supplier_lot_number: string | null;
    received_at: string | null;
    stock: KioskStockRow[];
};

/** `backflush`: the raw material a dispatch of this product deducts (raw husk for chips). */
export type KioskStockedProduct = KioskProduct & { stock: KioskStockRow[]; lots: KioskLot[]; backflush: KioskProduct | null };

export type KioskRecipeOutput = KioskProduct & { derived: boolean; requires_secondary_quantity: boolean };

/**
 * `consumes_input` false: the run takes no input now (husk chipping — husk is deducted at
 * dispatch). `labour`: the run asks for a labour rate per input unit (dehusking).
 */
export type KioskRecipe = {
    input: KioskProduct;
    /** How much of the input each store holds (stores holding none are left out). */
    input_stock: { warehouse_id: number; quantity: string }[];
    lots: KioskLot[];
    outputs: KioskRecipeOutput[];
    consumes_input: boolean;
    input_tracks_lots: boolean;
    labour: { default_rate: string | null } | null;
};
