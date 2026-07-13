import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useParams } from 'react-router-dom';
import { vendorApi, type ListingType } from '../api/vendor';
import { DashLayout } from '../components/DashLayout';
import { ApiError } from '../api/client';

type FieldValue = string | number | boolean;

export function VendorListingForm() {
  const { type, id } = useParams<{ type: ListingType; id?: string }>();
  const ltype = (type ?? 'product') as ListingType;
  const listingId = id ? Number(id) : null;
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const metaQuery = useQuery({ queryKey: ['vendor', 'meta', ltype], queryFn: () => vendorApi.meta(ltype) });
  const itemQuery = useQuery({
    queryKey: ['vendor', 'listing', ltype, listingId],
    queryFn: () => vendorApi.get(ltype, listingId as number),
    enabled: listingId !== null,
  });

  const [fields, setFields] = useState<Record<string, FieldValue>>({});
  const [attrValues, setAttrValues] = useState<Record<string, FieldValue>>({});
  const [errors, setErrors] = useState<string[]>([]);
  const [imageFiles, setImageFiles] = useState<FileList | null>(null);

  // Prefill from the existing listing once it loads (edit mode).
  useEffect(() => {
    const item = itemQuery.data?.data;
    if (!item) return;
    setFields({
      title: item.title, category_id: item.category_id, description: item.description,
      city: item.city, subcity: item.subcity, price: item.price ?? '', discount_price: item.discount_price ?? '',
      is_negotiable: !!item.is_negotiable, stock_quantity: item.stock_quantity ?? '', material: item.material ?? '',
      brand: item.brand ?? '', color: item.color ?? '', dimensions: item.dimensions ?? '', warranty: item.warranty ?? '',
      delivery_available: !!item.delivery_available, installation_available: !!item.installation_available,
      customization_available: !!item.customization_available, product_type: item.product_type ?? 'ready_made',
      condition_type: item.condition_type ?? 'new', experience_years: item.experience_years ?? 0,
      price_type: item.price_type ?? 'quote_required', starting_price: item.price ?? '', grade: item.grade ?? '',
      size: item.size ?? '', thickness: item.thickness ?? '', unit_of_measurement: item.unit_of_measurement ?? 'piece',
      bulk_price: item.bulk_price ?? '', minimum_order_quantity: item.minimum_order_quantity ?? 1,
      price_per_unit: item.price ?? '',
    });
    setAttrValues(item.attributes as Record<string, FieldValue>);
  }, [itemQuery.data]);

  const categoryId = Number(fields.category_id ?? 0);
  const attrDefs = useMemo(
    () => metaQuery.data?.attributes_by_category[String(categoryId)] ?? [],
    [metaQuery.data, categoryId],
  );

  const setField = (name: string, value: FieldValue) => setFields((f) => ({ ...f, [name]: value }));
  const setAttr = (name: string, value: FieldValue) => setAttrValues((a) => ({ ...a, [name]: value }));

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload = { ...fields, attributes: attrValues };
      const result = listingId
        ? await vendorApi.update(ltype, listingId, payload)
        : await vendorApi.create(ltype, payload);
      if (imageFiles && imageFiles.length > 0) {
        await vendorApi.uploadImages(ltype, result.data.id, imageFiles);
      }
      return result;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['vendor', 'listings', ltype] });
      navigate(`/vendor/listings/${ltype}`);
    },
    onError: (err) => {
      if (err instanceof ApiError) {
        const fieldErrors = err.fields?._;
        setErrors(Array.isArray(fieldErrors) ? fieldErrors : [err.message]);
      } else {
        setErrors(['Something went wrong. Try again.']);
      }
    },
  });

  const mediaMutation = useMutation({
    mutationFn: ({ action, imageId }: { action: 'primary' | 'delete'; imageId?: number }) => {
      if (!listingId) throw new Error('Listing id is required.');
      return action === 'primary'
        ? vendorApi.setPrimaryImage(listingId, imageId as number)
        : vendorApi.deleteImage(ltype, listingId, imageId);
    },
    onSuccess: () => {
      if (listingId) queryClient.invalidateQueries({ queryKey: ['vendor', 'listing', ltype, listingId] });
      queryClient.invalidateQueries({ queryKey: ['vendor', 'listings', ltype] });
    },
    onError: (err) => {
      setErrors([err instanceof ApiError ? err.message : 'Could not update listing media.']);
    },
  });

  const onSubmit = (e: FormEvent) => {
    e.preventDefault();
    setErrors([]);
    saveMutation.mutate();
  };

  if (metaQuery.isLoading || (listingId !== null && itemQuery.isLoading)) {
    return (
      <DashLayout>
        <p className="muted">Loading…</p>
      </DashLayout>
    );
  }

  const meta = metaQuery.data;
  const subcityOptions = fields.city ? meta?.subcities[String(fields.city)] ?? [] : [];
  const currentImages = itemQuery.data?.data.images ?? [];

  return (
    <DashLayout>
      <h1>{listingId ? 'Edit' : 'New'} {ltype}</h1>

      {!listingId && meta && !meta.can_add_listing && (
        <div className="alert alert-error">
          Listing limit reached for your {meta.plan} plan. Upgrade to add more.
        </div>
      )}

      {errors.length > 0 && (
        <div className="alert alert-error">
          <ul style={{ margin: 0, paddingLeft: 18 }}>
            {errors.map((e, i) => (
              <li key={i}>{e}</li>
            ))}
          </ul>
        </div>
      )}

      <form className="panel form-2col" onSubmit={onSubmit}>
        <label className="span2">
          Title *
          <input value={String(fields.title ?? '')} onChange={(e) => setField('title', e.target.value)} required />
        </label>

        <label>
          Category *
          <select value={String(fields.category_id ?? '')} onChange={(e) => setField('category_id', Number(e.target.value))} required>
            <option value="">Select…</option>
            {meta?.categories.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        </label>

        <label>
          City *
          <select value={String(fields.city ?? '')} onChange={(e) => { setField('city', e.target.value); setField('subcity', ''); }} required>
            <option value="">Select…</option>
            {meta?.cities.map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </select>
        </label>

        <label>
          Sub-city
          <select value={String(fields.subcity ?? '')} onChange={(e) => setField('subcity', e.target.value)}>
            <option value="">Select…</option>
            {subcityOptions.map((s) => (
              <option key={s} value={s}>
                {s}
              </option>
            ))}
          </select>
        </label>

        {attrDefs.length > 0 && (
          <div className="span2 form-2col" style={{ border: '1px dashed var(--line)', borderRadius: 12, padding: 12 }}>
            {attrDefs.map((def) => {
              const val = attrValues[def.key_name];
              if (def.input_type === 'boolean') {
                return (
                  <label key={def.id} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <input type="checkbox" checked={!!val} onChange={(e) => setAttr(def.key_name, e.target.checked)} />
                    {def.label}
                  </label>
                );
              }
              if (def.input_type === 'select') {
                const options: string[] = def.options ? JSON.parse(def.options) : [];
                return (
                  <label key={def.id}>
                    {def.label}
                    {def.is_required ? ' *' : ''}
                    <select value={String(val ?? '')} onChange={(e) => setAttr(def.key_name, e.target.value)} required={!!def.is_required}>
                      <option value="">Select…</option>
                      {options.map((o) => (
                        <option key={o} value={o}>
                          {o}
                        </option>
                      ))}
                    </select>
                  </label>
                );
              }
              return (
                <label key={def.id}>
                  {def.label}
                  {def.unit ? ` (${def.unit})` : ''}
                  {def.is_required ? ' *' : ''}
                  <input
                    type={def.input_type === 'number' ? 'number' : 'text'}
                    value={String(val ?? '')}
                    onChange={(e) => setAttr(def.key_name, e.target.value)}
                    required={!!def.is_required}
                  />
                </label>
              );
            })}
          </div>
        )}

        {ltype === 'product' && (
          <>
            <label>
              Type
              <select value={String(fields.product_type ?? 'ready_made')} onChange={(e) => setField('product_type', e.target.value)}>
                {['ready_made', 'custom_made', 'imported', 'used', 'made_to_order', 'decor', 'tool', 'machine'].map((t) => (
                  <option key={t} value={t}>
                    {t}
                  </option>
                ))}
              </select>
            </label>
            <label>
              Condition
              <select value={String(fields.condition_type ?? 'new')} onChange={(e) => setField('condition_type', e.target.value)}>
                {['new', 'used', 'refurbished'].map((c) => (
                  <option key={c} value={c}>
                    {c}
                  </option>
                ))}
              </select>
            </label>
            <label>
              Price (ETB)
              <input type="number" step="0.01" value={String(fields.price ?? '')} onChange={(e) => setField('price', e.target.value)} />
            </label>
            <label>
              Discount price
              <input type="number" step="0.01" value={String(fields.discount_price ?? '')} onChange={(e) => setField('discount_price', e.target.value)} />
            </label>
            <label>
              Material
              <input value={String(fields.material ?? '')} onChange={(e) => setField('material', e.target.value)} />
            </label>
            <label>
              Brand
              <input value={String(fields.brand ?? '')} onChange={(e) => setField('brand', e.target.value)} />
            </label>
            <label className="span2">
              Images
              <input type="file" accept="image/*" multiple onChange={(e) => setImageFiles(e.target.files)} />
            </label>
            {listingId && currentImages.length > 0 && (
              <div className="span2 media-grid" aria-label="Current product images">
                {currentImages.map((image) => (
                  <div className="media-tile" key={image.id}>
                    <img src={image.url} alt="" />
                    <div className="media-actions">
                      <span className={`badge ${image.is_primary ? 'badge-status-active' : ''}`}>
                        {image.is_primary ? 'Primary' : 'Image'}
                      </span>
                      {!image.is_primary && image.id > 0 && (
                        <button
                          className="btn btn-outline btn-sm"
                          type="button"
                          disabled={mediaMutation.isPending}
                          onClick={() => mediaMutation.mutate({ action: 'primary', imageId: image.id })}
                        >
                          Make primary
                        </button>
                      )}
                      {image.id > 0 && (
                        <button
                          className="btn btn-error btn-sm"
                          type="button"
                          disabled={mediaMutation.isPending}
                          onClick={() => {
                            if (confirm('Delete this image?')) mediaMutation.mutate({ action: 'delete', imageId: image.id });
                          }}
                        >
                          Delete
                        </button>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </>
        )}

        {ltype === 'service' && (
          <>
            <label>
              Experience (years)
              <input type="number" value={String(fields.experience_years ?? 0)} onChange={(e) => setField('experience_years', e.target.value)} />
            </label>
            <label>
              Starting price (ETB)
              <input type="number" step="0.01" value={String(fields.starting_price ?? '')} onChange={(e) => setField('starting_price', e.target.value)} />
            </label>
          </>
        )}

        {ltype === 'supply' && (
          <>
            <label>
              Price per unit (ETB)
              <input type="number" step="0.01" value={String(fields.price_per_unit ?? '')} onChange={(e) => setField('price_per_unit', e.target.value)} />
            </label>
            <label>
              Unit
              <input value={String(fields.unit_of_measurement ?? 'piece')} onChange={(e) => setField('unit_of_measurement', e.target.value)} />
            </label>
          </>
        )}

        {ltype !== 'product' && (
          <>
            <label className="span2">
              Image
              <input type="file" accept="image/*" onChange={(e) => setImageFiles(e.target.files)} />
            </label>
            {listingId && currentImages.length > 0 && (
              <div className="span2 media-grid" aria-label="Current listing image">
                <div className="media-tile">
                  <img src={currentImages[0].url} alt="" />
                  <div className="media-actions">
                    <span className="badge badge-status-active">Current image</span>
                    <button
                      className="btn btn-error btn-sm"
                      type="button"
                      disabled={mediaMutation.isPending}
                      onClick={() => {
                        if (confirm('Delete this image?')) mediaMutation.mutate({ action: 'delete' });
                      }}
                    >
                      Delete
                    </button>
                  </div>
                </div>
              </div>
            )}
          </>
        )}

        <label className="span2">
          Description
          <textarea value={String(fields.description ?? '')} onChange={(e) => setField('description', e.target.value)} />
        </label>

        {listingId && itemQuery.data?.data.status && (
          <div className="span2">
            Moderation status: <span className={`badge badge-status-${itemQuery.data.data.status}`}>{itemQuery.data.data.status.replace('_', ' ')}</span>
          </div>
        )}

        <div className="span2">
          <button className="btn btn-primary" type="submit" disabled={saveMutation.isPending}>
            {saveMutation.isPending ? 'Saving…' : listingId ? 'Save changes' : 'Create listing'}
          </button>
        </div>
      </form>
    </DashLayout>
  );
}
