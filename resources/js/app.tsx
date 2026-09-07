import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router';
import { Layout } from './components/Layout';
import { RequireAuth } from './components/RequireAuth';
import { AuthProvider } from './hooks/useAuth';
import { LabelDetailPage } from './pages/LabelDetailPage';
import { LabelsPage } from './pages/LabelsPage';
import { LoginPage } from './pages/LoginPage';
import { RegisterPage } from './pages/RegisterPage';

createRoot(document.getElementById('app')!).render(
    <StrictMode>
        <BrowserRouter>
            <AuthProvider>
                <Routes>
                    <Route path="/login" element={<LoginPage />} />
                    <Route path="/register" element={<RegisterPage />} />
                    <Route element={<RequireAuth />}>
                        <Route element={<Layout />}>
                            <Route path="/labels" element={<LabelsPage />} />
                            <Route path="/labels/:id" element={<LabelDetailPage />} />
                        </Route>
                    </Route>
                    <Route path="*" element={<Navigate to="/labels" replace />} />
                </Routes>
            </AuthProvider>
        </BrowserRouter>
    </StrictMode>,
);
