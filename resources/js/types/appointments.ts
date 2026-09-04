export type AppointmentStatus =
    'booked' | 'checked_in' | 'in_service' | 'completed' | 'cancelled';

export type BoardFilters = {
    branch_id: string;
    status: AppointmentStatus | '';
    from: string;
    to: string;
    q: string;
    page: number;
};

export type AppointmentRow = {
    id: number;
    scheduled_at: string;
    status: AppointmentStatus;
    bay: string | null;
    customer_name: string | null;
    plate_number: string | null;
    advisor_name: string | null;
};

export type BoardResponse = {
    data: AppointmentRow[];
    meta: {
        branch_id: number | null;
        page: number;
        per_page: number;
        has_more: boolean;
        timezone: string;
    };
};

export type BranchResponse = {
    data: { id: number; name: string }[];
    meta: { default_branch_id: number | null };
};
