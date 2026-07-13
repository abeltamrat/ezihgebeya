import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { vendorApi } from '../api/vendor';
import { ApiError } from '../api/client';
import { DashLayout } from '../components/DashLayout';

export function VendorBusinessProfile() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const profile = useQuery({ queryKey: ['vendor', 'business'], queryFn: vendorApi.business });

  const [form, setForm] = useState({
    business_name: '',
    phone: '',
    city: '',
    subcity: '',
    area_name: '',
    address: '',
    description: '',
    tin_number: '',
    license_number: '',
  });
  const [logo, setLogo] = useState<File | null>(null);
  const [cover, setCover] = useState<File | null>(null);

  useEffect(() => {
    if (!profile.data) return;
    const business = profile.data.business;
    setForm({
      business_name: business?.business_name ?? '',
      phone: business?.phone ?? profile.data.default_phone ?? '',
      city: business?.city ?? '',
      subcity: business?.subcity ?? '',
      area_name: business?.area_name ?? '',
      address: business?.address ?? '',
      description: business?.description ?? '',
      tin_number: business?.tin_number ?? '',
      license_number: business?.license_number ?? '',
    });
  }, [profile.data]);

  const saveMutation = useMutation({
    mutationFn: () => {
      const payload = new FormData();
      Object.entries(form).forEach(([key, value]) => payload.set(key, value));
      if (logo) payload.set('logo', logo);
      if (cover) payload.set('cover_image', cover);
      return vendorApi.saveBusiness(payload);
    },
    onSuccess: (result) => {
      queryClient.setQueryData(['vendor', 'business'], (current: typeof profile.data) =>
        current ? { ...current, business: result.business } : current,
      );
      queryClient.invalidateQueries({ queryKey: ['vendor', 'dashboard'] });
      navigate('/vendor');
    },
  });

  const onSubmit = (event: FormEvent) => {
    event.preventDefault();
    saveMutation.mutate();
  };

  const data = profile.data;
  const citySubcities = data?.subcities[form.city] ?? [];

  return (
    <DashLayout>
      <h1>{data?.business ? 'Edit business profile' : 'Register business'}</h1>
      {profile.isLoading ? <p className="muted">Loading…</p> : null}
      {profile.error ? (
        <div className="alert alert-error">
          {profile.error instanceof ApiError ? profile.error.message : 'Could not load business profile.'}
        </div>
      ) : null}

      {data?.business ? (
        <div className="panel">
          <h2>{data.business.business_name}</h2>
          <p className="muted">
            Status: <span className={`badge badge-status-${data.business.status}`}>{data.business.status}</span> ·
            Verification: {data.business.verification_status.replaceAll('_', ' ')}
          </p>
          {data.business.public_url ? (
            <a className="btn btn-outline btn-sm" href={data.business.public_url}>
              View public page
            </a>
          ) : null}
        </div>
      ) : null}

      {data ? (
        <form className="panel form-2col" onSubmit={onSubmit}>
          <label>
            Business name *
            <input
              required
              value={form.business_name}
              onChange={(e) => setForm((current) => ({ ...current, business_name: e.target.value }))}
            />
          </label>
          <label>
            Business phone *
            <input
              required
              value={form.phone}
              onChange={(e) => setForm((current) => ({ ...current, phone: e.target.value }))}
            />
          </label>
          <label>
            City *
            <select
              required
              value={form.city}
              onChange={(e) => setForm((current) => ({ ...current, city: e.target.value, subcity: '' }))}
            >
              <option value="">Select…</option>
              {data.cities.map((city) => (
                <option key={city} value={city}>
                  {city}
                </option>
              ))}
            </select>
          </label>
          <label>
            Sub-city
            <select value={form.subcity} onChange={(e) => setForm((current) => ({ ...current, subcity: e.target.value }))}>
              <option value="">Select…</option>
              {citySubcities.map((subcity) => (
                <option key={subcity} value={subcity}>
                  {subcity}
                </option>
              ))}
            </select>
          </label>
          <label>
            Area / neighborhood
            <input
              placeholder="e.g. Wollo Sefer"
              value={form.area_name}
              onChange={(e) => setForm((current) => ({ ...current, area_name: e.target.value }))}
            />
          </label>
          <label>
            Street address
            <input value={form.address} onChange={(e) => setForm((current) => ({ ...current, address: e.target.value }))} />
          </label>
          <label className="span2">
            Description
            <textarea
              rows={4}
              placeholder="What do you sell or what services do you offer?"
              value={form.description}
              onChange={(e) => setForm((current) => ({ ...current, description: e.target.value }))}
            />
          </label>
          <label>
            TIN number
            <input
              placeholder="For verification badge"
              value={form.tin_number}
              onChange={(e) => setForm((current) => ({ ...current, tin_number: e.target.value }))}
            />
          </label>
          <label>
            Business license no.
            <input
              value={form.license_number}
              onChange={(e) => setForm((current) => ({ ...current, license_number: e.target.value }))}
            />
          </label>
          <label>
            Logo
            <input type="file" accept="image/*" onChange={(e) => setLogo(e.target.files?.[0] ?? null)} />
          </label>
          <label>
            Cover image
            <input type="file" accept="image/*" onChange={(e) => setCover(e.target.files?.[0] ?? null)} />
          </label>
          {saveMutation.error ? (
            <div className="span2 alert alert-error">
              {saveMutation.error instanceof ApiError ? saveMutation.error.message : 'Could not save business profile.'}
            </div>
          ) : null}
          <div className="span2">
            <button className="btn btn-primary" disabled={saveMutation.isPending}>
              {saveMutation.isPending ? 'Saving…' : data.business ? 'Save changes' : 'Submit for approval'}
            </button>
          </div>
        </form>
      ) : null}

      <p className="muted small">
        Providing TIN and license number makes you eligible for the verified badge, higher ranking, and promotions.
      </p>
    </DashLayout>
  );
}
