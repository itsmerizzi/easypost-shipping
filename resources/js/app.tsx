import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

function App() {
    return <h1 className="p-8 text-2xl font-semibold">USPS Labels</h1>;
}

createRoot(document.getElementById('app')!).render(
    <StrictMode>
        <App />
    </StrictMode>,
);
