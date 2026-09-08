import { Link, Outlet, useNavigate } from 'react-router';
import { useAuth } from '../hooks/useAuth';
import { Button } from './Button';

export function Layout() {
    const { user, logout } = useAuth();
    const navigate = useNavigate();

    async function handleLogout() {
        await logout();
        navigate('/login', { replace: true });
    }

    return (
        <div className="min-h-screen">
            <header className="border-b border-gray-200 bg-white">
                <div className="mx-auto flex max-w-5xl items-center justify-between px-4 py-3">
                    <Link to="/labels" className="text-lg font-semibold">
                        USPS Labels
                    </Link>
                    <div className="flex items-center gap-4 text-sm text-gray-600">
                        <span>{user?.name}</span>
                        <Button variant="secondary" onClick={handleLogout}>
                            Log out
                        </Button>
                    </div>
                </div>
            </header>
            <main className="mx-auto max-w-5xl px-4 py-8">
                <Outlet />
            </main>
        </div>
    );
}
