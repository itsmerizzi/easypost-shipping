import { http } from './client';
import type { Label, NewLabelPayload, Paginated } from '../types';

export async function listLabels(page = 1): Promise<Paginated<Label>> {
    return (await http.get<Paginated<Label>>('/labels', { params: { page } })).data;
}

export async function createLabel(payload: NewLabelPayload): Promise<Label> {
    return (await http.post<{ data: Label }>('/labels', payload)).data.data;
}

export async function showLabel(id: string | number): Promise<Label> {
    return (await http.get<{ data: Label }>(`/labels/${id}`)).data.data;
}

/** Authenticated PDF route; open in a new tab, the browser viewer handles printing. */
export function labelDownloadUrl(id: string | number): string {
    return `/api/labels/${id}/download`;
}
