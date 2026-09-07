import { Field } from './Field';
import type { Address } from '../types';
import { US_STATES } from '../usStates';

interface AddressFieldsProps {
    legend: string;
    prefix: 'from_address' | 'to_address';
    value: Address;
    errors: Record<string, string[]> | undefined;
    onChange: (next: Address) => void;
}

export function AddressFields({ legend, prefix, value, errors, onChange }: AddressFieldsProps) {
    const errorFor = (field: keyof Address) => errors?.[`${prefix}.${field}`];
    const set = (field: keyof Address) => (e: { target: { value: string } }) =>
        onChange({ ...value, [field]: e.target.value === '' && (field === 'street2' || field === 'phone') ? null : e.target.value });

    const stateError = errorFor('state');

    return (
        <fieldset className="space-y-4 rounded-lg border border-gray-200 bg-white p-6">
            <legend className="px-2 text-sm font-semibold uppercase tracking-wide text-gray-500">{legend}</legend>
            <Field label="Full name" name={`${prefix}.name`} required value={value.name} onChange={set('name')} error={errorFor('name')} />
            <Field label="Street address" name={`${prefix}.street1`} required value={value.street1} onChange={set('street1')} error={errorFor('street1')} />
            <Field label="Apt, suite, etc. (optional)" name={`${prefix}.street2`} value={value.street2 ?? ''} onChange={set('street2')} error={errorFor('street2')} />
            <div className="grid gap-4 sm:grid-cols-3">
                <Field label="City" name={`${prefix}.city`} required value={value.city} onChange={set('city')} error={errorFor('city')} />
                <div>
                    <label htmlFor={`${prefix}.state`} className="block text-sm font-medium text-gray-700">
                        State
                    </label>
                    <select
                        id={`${prefix}.state`}
                        name={`${prefix}.state`}
                        required
                        value={value.state}
                        onChange={set('state')}
                        className={`mt-1 block w-full rounded-md border bg-white px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 ${stateError ? 'border-red-500 focus:ring-red-200' : 'border-gray-300 focus:ring-blue-200'}`}
                    >
                        <option value="">Select…</option>
                        {US_STATES.map((s) => (
                            <option key={s.code} value={s.code}>
                                {s.code} — {s.name}
                            </option>
                        ))}
                    </select>
                    {stateError && <p className="mt-1 text-sm text-red-600">{stateError[0]}</p>}
                </div>
                <Field label="ZIP" name={`${prefix}.zip`} required inputMode="numeric" placeholder="94104" value={value.zip} onChange={set('zip')} error={errorFor('zip')} />
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Country" name={`${prefix}.country`} value="US" readOnly className="opacity-70" error={errorFor('country')} />
                <Field label="Phone (optional)" name={`${prefix}.phone`} type="tel" value={value.phone ?? ''} onChange={set('phone')} error={errorFor('phone')} />
            </div>
        </fieldset>
    );
}
