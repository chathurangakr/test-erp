/** Fields shared by every party-style master (suppliers, customers). */
export type PartyContactFields = {
    contact_person: string | null;
    phone: string | null;
    secondary_phone: string | null;
    email: string | null;
    address_line_1: string | null;
    address_line_2: string | null;
    city: string | null;
    district: string | null;
    registration_number: string | null;
    tax_number: string | null;
    notes: string | null;
};

export type Supplier = PartyContactFields & {
    id: number;
    code: string;
    name: string;
    payment_terms_days: number;
    is_active: boolean;
    created_at: string | null;
    updated_at: string | null;
};

export type Customer = PartyContactFields & {
    id: number;
    code: string;
    name: string;
    credit_limit: string;
    credit_days: number;
    is_active: boolean;
    created_at: string | null;
    updated_at: string | null;
};

export type Warehouse = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    address: string | null;
    is_active: boolean;
    is_default: boolean;
    created_at: string | null;
    updated_at: string | null;
};
