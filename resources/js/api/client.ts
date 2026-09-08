import axios from 'axios';
import type { ApiError } from '../types';

export const http = axios.create({
    baseURL: '/api',
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json' },
});

let onUnauthenticated: (() => void) | null = null;

/** AuthProvider registers a handler so a 401/419 anywhere logs the user out client-side. */
export function setUnauthenticatedHandler(handler: (() => void) | null): void {
    onUnauthenticated = handler;
}

http.interceptors.response.use(
    (response) => response,
    (error: unknown) => {
        if (axios.isAxiosError(error)) {
            const status = error.response?.status;
            if (status === 401 || status === 419) {
                onUnauthenticated?.();
            }
        }
        return Promise.reject(error);
    },
);

/** Sanctum SPA auth needs the XSRF-TOKEN cookie before the first POST. */
export async function ensureCsrfCookie(): Promise<void> {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}

export function extractApiError(error: unknown): ApiError {
    if (axios.isAxiosError(error) && error.response) {
        if (error.response.status === 429) {
            return { message: 'Too many attempts. Please wait a minute and try again.' };
        }
        const data = error.response.data as Partial<ApiError> | undefined;
        return {
            message: data?.message ?? `Request failed (${error.response.status}).`,
            errors: data?.errors,
        };
    }
    return { message: 'Network error. Please try again.' };
}
