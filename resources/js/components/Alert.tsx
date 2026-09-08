interface AlertProps {
    message: string | null;
    tone?: 'error' | 'info';
}

export function Alert({ message, tone = 'error' }: AlertProps) {
    if (!message) {
        return null;
    }

    const styles = tone === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-blue-200 bg-blue-50 text-blue-800';

    return (
        <div role="alert" className={`rounded-md border px-4 py-3 text-sm ${styles}`}>
            {message}
        </div>
    );
}
