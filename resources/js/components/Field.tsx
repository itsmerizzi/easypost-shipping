import type { InputHTMLAttributes } from 'react';

interface FieldProps extends InputHTMLAttributes<HTMLInputElement> {
    label: string;
    error?: string[];
}

export function Field({ label, error, id, name, className, ...props }: FieldProps) {
    const inputId = id ?? name;
    const border = error ? 'border-red-500 focus:ring-red-200' : 'border-gray-300 focus:ring-blue-200';

    return (
        <div className={className}>
            <label htmlFor={inputId} className="block text-sm font-medium text-gray-700">
                {label}
            </label>
            <input
                id={inputId}
                name={name}
                {...props}
                className={`mt-1 block w-full rounded-md border bg-white px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 ${border}`}
            />
            {error && <p className="mt-1 text-sm text-red-600">{error[0]}</p>}
        </div>
    );
}
