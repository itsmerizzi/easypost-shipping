import { useState, type FormEvent } from 'react';
import { Link, Navigate, useNavigate } from 'react-router';
import { extractApiError } from '../api/client';
import { Alert } from '../components/Alert';
import { Button } from '../components/Button';
import { Field } from '../components/Field';
import { useAuth } from '../hooks/useAuth';
import type { ApiError } from '../types';

export function RegisterPage() {
    const { user, loading, register } = useAuth();
    const navigate = useNavigate();
    const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '' });
    const [error, setError] = useState<ApiError | null>(null);
    const [submitting, setSubmitting] = useState(false);

    if (!loading && user) {
        return <Navigate to="/labels" replace />;
    }

    function update(field: keyof typeof form) {
        return (e: { target: { value: string } }) => setForm((prev) => ({ ...prev, [field]: e.target.value }));
    }

    async function handleSubmit(event: FormEvent) {
        event.preventDefault();
        setSubmitting(true);
        setError(null);
        try {
            await register(form);
            navigate('/labels', { replace: true });
        } catch (e) {
            setError(extractApiError(e));
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="mx-auto mt-16 max-w-sm rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h1 className="mb-6 text-xl font-semibold">Create an account</h1>
            <form onSubmit={handleSubmit} className="space-y-4">
                <Alert message={error?.errors ? null : error?.message ?? null} />
                <Field label="Name" name="name" required value={form.name} onChange={update('name')} error={error?.errors?.name} />
                <Field label="Email" name="email" type="email" autoComplete="email" required value={form.email} onChange={update('email')} error={error?.errors?.email} />
                <Field
                    label="Password"
                    name="password"
                    type="password"
                    autoComplete="new-password"
                    required
                    minLength={8}
                    value={form.password}
                    onChange={update('password')}
                    error={error?.errors?.password}
                />
                <Field
                    label="Confirm password"
                    name="password_confirmation"
                    type="password"
                    autoComplete="new-password"
                    required
                    value={form.password_confirmation}
                    onChange={update('password_confirmation')}
                />
                <Button type="submit" loading={submitting} className="w-full">
                    Register
                </Button>
            </form>
            <p className="mt-4 text-center text-sm text-gray-600">
                Already registered?{' '}
                <Link to="/login" className="text-blue-600 hover:underline">
                    Log in
                </Link>
            </p>
        </div>
    );
}
