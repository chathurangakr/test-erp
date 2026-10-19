// ------------------------------------------------------------- users and roles

export type RoleRef = { id: number; code: string; name: string; is_active: boolean };

export type AdminUser = {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    /** Lands on the simple-mode (kiosk) screens after signing in. */
    start_in_simple_mode: boolean;
    created_at: string | null;
    roles: RoleRef[];
};

export type RoleOption = RoleRef & { description: string | null };

export type RoleRow = RoleOption & {
    is_administrator: boolean;
    users_count: number;
    permissions_count: number;
};

export type RoleDetail = RoleOption & {
    is_administrator: boolean;
    permission_ids: number[];
};

export type PermissionGroup = {
    module: string;
    permissions: { id: number; code: string; name: string }[];
};

/** The company as printed on invoices and reports (shared with every signed-in page). */
export type CompanyLetterhead = {
    name: string;
    address: string | null;
    phone: string | null;
    email: string | null;
    registration_number: string | null;
    logo_url: string | null;
};
