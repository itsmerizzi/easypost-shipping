import { useState, type FormEvent } from 'react';
import { Link, Navigate, useNavigate } from 'react-router';
import { extractApiError } from '../api/client';
import { Alert } from '../components/Alert';
import { Button } from '../components/Button';
import { Field } from '../components/Field';
import { useAuth } from '../hooks/useAuth';
import type { ApiError } from '../types';

export function LoginPage() {
    const { user, loading, login } = useAuth();
    const navigate = useNavigate();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState<ApiError | null>(null);
    const [submitting, setSubmitting] = useState(false);

    if (!loading && user) {
        return <Navigate to="/labels" replace />;
    }

    async function handleSubmit(event: FormEvent) {
        event.preventDefault();
        setSubmitting(true);
        setError(null);
        try {
            await login(email, password);
            navigate('/labels', { replace: true });
        } catch (e) {
            setError(extractApiError(e));
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="mx-auto mt-16 max-w-sm rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h1 className="mb-6 text-xl font-semibold">Log in</h1>
            <form onSubmit={handleSubmit} className="space-y-4">
                <Alert message={error?.errors ? null : error?.message ?? null} />
                <Field
                    label="Email"
                    name="email"
                    type="email"
                    autoComplete="email"
                    required
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    error={error?.errors?.email}
                />
                <Field
                    label="Password"
                    name="password"
                    type="password"
                    autoComplete="current-password"
                    required
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    error={error?.errors?.password}
                />
                <Button type="submit" loading={submitting} className="w-full">
                    Log in
                </Button>
            </form>
            <p className="mt-4 text-center text-sm text-gray-600">
                No account?{' '}
                <Link to="/register" className="text-blue-600 hover:underline">
                    Register
                </Link>
            </p>
        </div>
    );
}
