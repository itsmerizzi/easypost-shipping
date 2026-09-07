import { ensureCsrfCookie, http } from './client';
import type { User } from '../types';

export interface RegisterPayload {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
}

export async function me(): Promise<User> {
    return (await http.get<User>('/user')).data;
}

export async function login(email: string, password: string): Promise<User> {
    await ensureCsrfCookie();
    return (await http.post<User>('/login', { email, password })).data;
}

export async function register(payload: RegisterPayload): Promise<User> {
    await ensureCsrfCookie();
    return (await http.post<User>('/register', payload)).data;
}

export async function logout(): Promise<void> {
    await http.post('/logout');
}
