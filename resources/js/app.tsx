import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router';
import { Layout } from './components/Layout';
import { RequireAuth } from './components/RequireAuth';
import { AuthProvider } from './hooks/useAuth';
import { LoginPage } from './pages/LoginPage';
import { RegisterPage } from './pages/RegisterPage';

function LabelsPlaceholder() {
    return <p className="text-gray-600">Labels coming in the next task.</p>;
}

createRoot(document.getElementById('app')!).render(
    <StrictMode>
        <BrowserRouter>
            <AuthProvider>
                <Routes>
                    <Route path="/login" element={<LoginPage />} />
                    <Route path="/register" element={<RegisterPage />} />
                    <Route element={<RequireAuth />}>
                        <Route element={<Layout />}>
                            <Route path="/labels" element={<LabelsPlaceholder />} />
                        </Route>
                    </Route>
                    <Route path="*" element={<Navigate to="/labels" replace />} />
                </Routes>
            </AuthProvider>
        </BrowserRouter>
    </StrictMode>,
);
