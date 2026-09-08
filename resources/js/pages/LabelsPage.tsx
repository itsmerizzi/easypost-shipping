import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { extractApiError } from '../api/client';
import { labelDownloadUrl, listLabels } from '../api/labels';
import { Alert } from '../components/Alert';
import { Button } from '../components/Button';
import type { Label, Paginated } from '../types';

function formatDate(iso: string): string {
    return new Date(iso).toLocaleString();
}

export function LabelsPage() {
    const [searchParams, setSearchParams] = useSearchParams();
    const page = Number(searchParams.get('page') ?? '1') || 1;
    const [result, setResult] = useState<Paginated<Label> | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let cancelled = false;
        setResult(null);
        setError(null);
        listLabels(page)
            .then((data) => {
                if (!cancelled) setResult(data);
            })
            .catch((e) => {
                if (!cancelled) setError(extractApiError(e).message);
            });
        return () => {
            cancelled = true;
        };
    }, [page]);

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <h1 className="text-2xl font-semibold">Your labels</h1>
                <Link to="/labels/new">
                    <Button>New label</Button>
                </Link>
            </div>

            <Alert message={error} />

            {result && result.data.length === 0 && (
                <div className="rounded-lg border border-dashed border-gray-300 bg-white p-12 text-center">
                    <p className="text-gray-600">No labels yet.</p>
                    <Link to="/labels/new" className="mt-4 inline-block text-blue-600 hover:underline">
                        Create your first USPS label
                    </Link>
                </div>
            )}

            {result && result.data.length > 0 && (
                <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                    <table className="min-w-full divide-y divide-gray-200 text-sm">
                        <thead className="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                            <tr>
                                <th className="px-4 py-3">Created</th>
                                <th className="px-4 py-3">Recipient</th>
                                <th className="px-4 py-3">Service</th>
                                <th className="px-4 py-3">Rate</th>
                                <th className="px-4 py-3">Tracking</th>
                                <th className="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {result.data.map((label) => (
                                <tr key={label.id}>
                                    <td className="whitespace-nowrap px-4 py-3">{formatDate(label.created_at)}</td>
                                    <td className="px-4 py-3">
                                        <div className="font-medium">{label.to_address.name}</div>
                                        <div className="text-gray-500">
                                            {label.to_address.city}, {label.to_address.state}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        {label.carrier} {label.service}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        {label.currency} {label.rate}
                                    </td>
                                    <td className="px-4 py-3 font-mono text-xs">{label.tracking_code ?? '—'}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-right">
                                        <Link to={`/labels/${label.id}`} className="mr-3 text-blue-600 hover:underline">
                                            Details
                                        </Link>
                                        <a
                                            href={labelDownloadUrl(label.id)}
                                            target="_blank"
                                            rel="noopener"
                                            className="text-blue-600 hover:underline"
                                        >
                                            View / Print
                                        </a>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {result && result.meta.last_page > 1 && (
                <div className="flex items-center justify-between text-sm text-gray-600">
                    <span>
                        Page {result.meta.current_page} of {result.meta.last_page} · {result.meta.total} labels
                    </span>
                    <div className="flex gap-2">
                        <Button
                            variant="secondary"
                            disabled={result.meta.current_page <= 1}
                            onClick={() => setSearchParams({ page: String(result.meta.current_page - 1) })}
                        >
                            Previous
                        </Button>
                        <Button
                            variant="secondary"
                            disabled={result.meta.current_page >= result.meta.last_page}
                            onClick={() => setSearchParams({ page: String(result.meta.current_page + 1) })}
                        >
                            Next
                        </Button>
                    </div>
                </div>
            )}

            {!result && !error && <p className="text-gray-500">Loading…</p>}
        </div>
    );
}
