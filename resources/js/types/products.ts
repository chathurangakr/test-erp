export type UnitOfMeasure = {
    id: number;
    code: string;
    name: string;
    decimal_places?: number;
};

export type Product = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    primary_uom_id: number;
    secondary_uom_id: number | null;
    is_active: boolean;
    created_at: string | null;
    updated_at: string | null;
    primary_uom?: UnitOfMeasure | null;
    secondary_uom?: UnitOfMeasure | null;
};

export type ProductFilters = {
    search: string;
    status: 'all' | 'active' | 'inactive';
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
};
