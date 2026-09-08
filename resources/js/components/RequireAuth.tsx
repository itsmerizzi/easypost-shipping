import { Navigate, Outlet } from 'react-router';
import { useAuth } from '../hooks/useAuth';

export function RequireAuth() {
    const { user, loading } = useAuth();

    if (loading) {
        return <p className="p-8 text-center text-gray-500">Loading…</p>;
    }

    if (!user) {
        return <Navigate to="/login" replace />;
    }

    return <Outlet />;
}
