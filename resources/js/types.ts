export interface User {
    id: number;
    name: string;
    email: string;
}

export interface Address {
    name: string;
    street1: string;
    street2: string | null;
    city: string;
    state: string;
    zip: string;
    country: 'US';
    phone: string | null;
}

export interface Parcel {
    weight_oz: number;
    length_in: number;
    width_in: number;
    height_in: number;
}

export interface Label {
    id: number;
    carrier: string;
    service: string;
    rate: string;
    currency: string;
    tracking_code: string | null;
    from_address: Address;
    to_address: Address;
    parcel: Parcel;
    created_at: string;
}

export interface Paginated<T> {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    links: {
        prev: string | null;
        next: string | null;
    };
}

export interface ApiError {
    message: string;
    errors?: Record<string, string[]>;
}

export interface NewLabelPayload {
    from_address: Address;
    to_address: Address;
    parcel: Parcel;
}
