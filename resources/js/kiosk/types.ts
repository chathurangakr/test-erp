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

export type KioskStockedProduct = KioskProduct & { stock: KioskStockRow[]; lots: KioskLot[] };

export type KioskRecipeOutput = KioskProduct & { derived: boolean; requires_secondary_quantity: boolean };

export type KioskRecipe = { input: KioskProduct; lots: KioskLot[]; outputs: KioskRecipeOutput[] };
