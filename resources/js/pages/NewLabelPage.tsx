import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router';
import { extractApiError } from '../api/client';
import { createLabel } from '../api/labels';
import { AddressFields } from '../components/AddressFields';
import { Alert } from '../components/Alert';
import { Button } from '../components/Button';
import { Field } from '../components/Field';
import type { Address, ApiError } from '../types';

const emptyAddress = (): Address => ({
    name: '',
    street1: '',
    street2: null,
    city: '',
    state: '',
    zip: '',
    country: 'US',
    phone: null,
});

type ParcelForm = { weight_oz: string; length_in: string; width_in: string; height_in: string };

export function NewLabelPage() {
    const navigate = useNavigate();
    const [fromAddress, setFromAddress] = useState<Address>(emptyAddress);
    const [toAddress, setToAddress] = useState<Address>(emptyAddress);
    const [parcel, setParcel] = useState<ParcelForm>({ weight_oz: '', length_in: '', width_in: '', height_in: '' });
    const [error, setError] = useState<ApiError | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const parcelError = (field: keyof ParcelForm) => error?.errors?.[`parcel.${field}`];
    const setParcelField = (field: keyof ParcelForm) => (e: { target: { value: string } }) =>
        setParcel((prev) => ({ ...prev, [field]: e.target.value }));

    async function handleSubmit(event: FormEvent) {
        event.preventDefault();
        setSubmitting(true);
        setError(null);
        try {
            const label = await createLabel({
                from_address: fromAddress,
                to_address: toAddress,
                parcel: {
                    weight_oz: Number(parcel.weight_oz),
                    length_in: Number(parcel.length_in),
                    width_in: Number(parcel.width_in),
                    height_in: Number(parcel.height_in),
                },
            });
            navigate(`/labels/${label.id}`);
        } catch (e) {
            setError(extractApiError(e));
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="space-y-6">
            <Link to="/labels" className="text-sm text-blue-600 hover:underline">
                ← Back to labels
            </Link>
            <h1 className="text-2xl font-semibold">New USPS label</h1>

            <form onSubmit={handleSubmit} className="space-y-6">
                <Alert message={error?.message ?? null} />

                <div className="grid gap-6 lg:grid-cols-2">
                    <AddressFields legend="From" prefix="from_address" value={fromAddress} errors={error?.errors} onChange={setFromAddress} />
                    <AddressFields legend="To" prefix="to_address" value={toAddress} errors={error?.errors} onChange={setToAddress} />
                </div>

                <fieldset className="rounded-lg border border-gray-200 bg-white p-6">
                    <legend className="px-2 text-sm font-semibold uppercase tracking-wide text-gray-500">Parcel</legend>
                    <div className="grid gap-4 sm:grid-cols-4">
                        <Field label="Weight (oz)" name="parcel.weight_oz" type="number" min={0.1} max={1120} step="0.1" required value={parcel.weight_oz} onChange={setParcelField('weight_oz')} error={parcelError('weight_oz')} />
                        <Field label="Length (in)" name="parcel.length_in" type="number" min={0.1} step="0.1" required value={parcel.length_in} onChange={setParcelField('length_in')} error={parcelError('length_in')} />
                        <Field label="Width (in)" name="parcel.width_in" type="number" min={0.1} step="0.1" required value={parcel.width_in} onChange={setParcelField('width_in')} error={parcelError('width_in')} />
                        <Field label="Height (in)" name="parcel.height_in" type="number" min={0.1} step="0.1" required value={parcel.height_in} onChange={setParcelField('height_in')} error={parcelError('height_in')} />
                    </div>
                    <p className="mt-3 text-xs text-gray-500">USPS accepts up to 70 lb (1120 oz). The cheapest USPS service is selected automatically.</p>
                </fieldset>

                <div className="flex items-center gap-3">
                    <Button type="submit" loading={submitting}>
                        Buy USPS label
                    </Button>
                    <span className="text-sm text-gray-500">Test mode: labels are watermarked "SAMPLE" and free.</span>
                </div>
            </form>
        </div>
    );
}
