import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router';
import { extractApiError } from '../api/client';
import { labelDownloadUrl, showLabel } from '../api/labels';
import { Alert } from '../components/Alert';
import { Button } from '../components/Button';
import type { Address, Label } from '../types';

function AddressBlock({ title, address }: { title: string; address: Address }) {
    return (
        <div>
            <h2 className="text-sm font-medium uppercase tracking-wide text-gray-500">{title}</h2>
            <address className="mt-1 not-italic text-gray-900">
                <div>{address.name}</div>
                <div>{address.street1}</div>
                {address.street2 && <div>{address.street2}</div>}
                <div>
                    {address.city}, {address.state} {address.zip}
                </div>
                {address.phone && <div className="text-gray-500">{address.phone}</div>}
            </address>
        </div>
    );
}

export function LabelDetailPage() {
    const { id } = useParams<{ id: string }>();
    const [label, setLabel] = useState<Label | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!id) return;
        let cancelled = false;
        setLabel(null);
        setError(null);
        showLabel(id)
            .then((data) => {
                if (!cancelled) setLabel(data);
            })
            .catch((e) => {
                if (!cancelled) setError(extractApiError(e).message);
            });
        return () => {
            cancelled = true;
        };
    }, [id]);

    if (error) {
        return (
            <div className="space-y-4">
                <Alert message={error} />
                <Link to="/labels" className="text-blue-600 hover:underline">
                    Back to labels
                </Link>
            </div>
        );
    }

    if (!label) {
        return <p className="text-gray-500">Loading…</p>;
    }

    return (
        <div className="space-y-6">
            <Link to="/labels" className="text-sm text-blue-600 hover:underline">
                ← Back to labels
            </Link>

            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold">
                        {label.carrier} {label.service}
                    </h1>
                    <p className="text-gray-600">
                        {label.currency} {label.rate} · created {new Date(label.created_at).toLocaleString()}
                    </p>
                </div>
                <a href={labelDownloadUrl(label.id)} target="_blank" rel="noopener">
                    <Button>View / Print label</Button>
                </a>
            </div>

            <div className="grid gap-6 rounded-lg border border-gray-200 bg-white p-6 sm:grid-cols-2">
                <AddressBlock title="From" address={label.from_address} />
                <AddressBlock title="To" address={label.to_address} />
                <div>
                    <h2 className="text-sm font-medium uppercase tracking-wide text-gray-500">Parcel</h2>
                    <p className="mt-1">
                        {label.parcel.weight_oz} oz · {label.parcel.length_in} × {label.parcel.width_in} × {label.parcel.height_in} in
                    </p>
                </div>
                <div>
                    <h2 className="text-sm font-medium uppercase tracking-wide text-gray-500">Tracking</h2>
                    <p className="mt-1 font-mono">{label.tracking_code ?? '—'}</p>
                </div>
            </div>
        </div>
    );
}
