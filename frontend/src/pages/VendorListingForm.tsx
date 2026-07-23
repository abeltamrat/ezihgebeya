import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
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
  const [imageFiles, setImageFiles] = useState<File[]>([]);
  const [imagePreviewUrls, setImagePreviewUrls] = useState<string[]>([]);
  const [imageError, setImageError] = useState('');
  const [glbModel, setGlbModel] = useState<File | null>(null);
  const [usdzModel, setUsdzModel] = useState<File | null>(null);

  useEffect(() => {
    const urls = imageFiles.map((file) => URL.createObjectURL(file));
    setImagePreviewUrls(urls);
    return () => urls.forEach((url) => URL.revokeObjectURL(url));
  }, [imageFiles]);

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
      if (imageFiles.length > 0) {
        await vendorApi.uploadImages(ltype, result.data.id, imageFiles);
      }
      if (ltype === 'product' && (glbModel || usdzModel)) {
        await vendorApi.uploadModels(result.data.id, glbModel, usdzModel);
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
  const currentArModels = itemQuery.data?.data.ar_models ?? [];
  const maxProductImages = Math.max(1, meta?.max_images_per_listing ?? 6);
  const usedProductImages = currentImages.length + imageFiles.length;
  const remainingProductImages = Math.max(0, maxProductImages - usedProductImages);
  const maxImageBytes = Math.max(1, meta?.max_image_upload_mb ?? 30) * 1024 * 1024;

  const addProductImages = (selected: FileList | null) => {
    if (!selected) return;
    setImageError('');
    const supportedTypes = new Set(['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
    const existingKeys = new Set(imageFiles.map((file) => `${file.name}:${file.size}:${file.lastModified}`));
    const valid: File[] = [];
    let rejectedType = 0;
    let rejectedSize = 0;
    let duplicates = 0;
    for (const file of Array.from(selected)) {
      const key = `${file.name}:${file.size}:${file.lastModified}`;
      if (!supportedTypes.has(file.type)) {
        rejectedType++;
      } else if (file.size > maxImageBytes) {
        rejectedSize++;
      } else if (existingKeys.has(key)) {
        duplicates++;
      } else {
        existingKeys.add(key);
        valid.push(file);
      }
    }
    const accepted = valid.slice(0, remainingProductImages);
    setImageFiles((files) => [...files, ...accepted]);
    const messages: string[] = [];
    if (valid.length > accepted.length) messages.push(`Only ${remainingProductImages} more image${remainingProductImages === 1 ? '' : 's'} can be added.`);
    if (rejectedType) messages.push(`${rejectedType} unsupported file${rejectedType === 1 ? '' : 's'} skipped. Use JPG, PNG, WebP, or GIF.`);
    if (rejectedSize) messages.push(`${rejectedSize} image${rejectedSize === 1 ? '' : 's'} exceeded ${meta?.max_image_upload_mb ?? 30} MB.`);
    if (duplicates) messages.push(`${duplicates} duplicate image${duplicates === 1 ? '' : 's'} skipped.`);
    setImageError(messages.join(' '));
  };

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

        {ltype === 'product' ? (
          <section className={`span2 profile-location-card ${meta?.profile_location.city ? '' : 'is-missing'}`} aria-labelledby="listing-location-title">
            <span className="profile-location-icon" aria-hidden="true">⌖</span>
            <div>
              <h2 id="listing-location-title">Product location</h2>
              {meta?.profile_location.city ? (
                <>
                  <strong>
                    {meta.profile_location.city}
                    {meta.profile_location.subcity ? ` · ${meta.profile_location.subcity}` : ''}
                  </strong>
                  <p>Automatically taken from your business profile and cannot be changed on this listing.</p>
                </>
              ) : (
                <>
                  <strong>Location missing from your business profile</strong>
                  <p>Add your city before creating a product listing.</p>
                </>
              )}
            </div>
            <Link className="btn btn-outline btn-sm" to="/vendor/business">Edit business profile</Link>
          </section>
        ) : (
          <>
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
          </>
        )}

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
            <section className="span2 listing-image-uploader" aria-labelledby="product-images-title">
              <div className="listing-image-uploader-head">
                <div>
                  <h2 id="product-images-title">Product photos</h2>
                  <p>Show the product from different angles. The first photo becomes the primary image.</p>
                </div>
                <span className={`upload-remaining ${remainingProductImages === 0 ? 'is-full' : ''}`} aria-live="polite">
                  {remainingProductImages === 0
                    ? 'Upload limit reached'
                    : `${remainingProductImages} upload${remainingProductImages === 1 ? '' : 's'} remaining`}
                </span>
              </div>

              <div className="selected-image-grid">
                {imageFiles.map((file, index) => (
                  <figure className="selected-image-preview" key={`${file.name}:${file.size}:${file.lastModified}`}>
                    <img src={imagePreviewUrls[index]} alt={`Selected product preview ${index + 1}`} />
                    <figcaption>
                      <strong>{currentImages.length === 0 && index === 0 ? 'Primary photo' : `Photo ${currentImages.length + index + 1}`}</strong>
                      <span>{(file.size / (1024 * 1024)).toFixed(file.size >= 1024 * 1024 ? 1 : 2)} MB</span>
                    </figcaption>
                    <button
                      type="button"
                      className="selected-image-remove"
                      aria-label={`Remove ${file.name}`}
                      title="Remove image"
                      onClick={() => setImageFiles((files) => files.filter((_, fileIndex) => fileIndex !== index))}
                    >
                      ×
                    </button>
                  </figure>
                ))}

                {remainingProductImages > 0 && (
                  <label className="add-image-tile">
                    <input
                      type="file"
                      accept="image/jpeg,image/png,image/webp,image/gif"
                      multiple
                      onChange={(event) => {
                        addProductImages(event.target.files);
                        event.target.value = '';
                      }}
                    />
                    <span className="add-image-plus" aria-hidden="true">+</span>
                    <strong>Add photos</strong>
                    <small>JPG, PNG, WebP or GIF</small>
                  </label>
                )}
              </div>
              {imageFiles.length === 0 && currentImages.length === 0 && (
                <p className="image-uploader-empty-hint">Select the + tile to add up to {maxProductImages} product photos.</p>
              )}
              {imageError && <p className="image-upload-error" role="alert">{imageError}</p>}
            </section>
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
            {meta?.ar_enabled && (
              <section className="span2 ar-model-uploader" aria-labelledby="ar-model-title">
                <div className="ar-model-head">
                  <div>
                    <p className="eyebrow">Interactive preview</p>
                    <h2 id="ar-model-title">3D &amp; AR models</h2>
                    <p>Add a GLB model for Android and web. Add USDZ as well for the best iPhone and iPad AR experience.</p>
                  </div>
                  <span className="badge badge-featured">AR</span>
                </div>

                {meta.ar_allowed ? (
                  <>
                    <div className="ar-model-fields">
                      <label className={`ar-model-file ${glbModel ? 'has-file' : ''}`}>
                        <span><strong>GLB model</strong><small>Android + web · maximum {meta.ar_model_max_mb} MB</small></span>
                        <input
                          type="file"
                          accept=".glb,model/gltf-binary"
                          onChange={(event) => setGlbModel(event.target.files?.[0] ?? null)}
                        />
                        <span className="ar-file-name">{glbModel?.name ?? (currentArModels.some((model) => model.type === 'glb') ? 'Current GLB attached · choose to replace' : 'Choose .glb')}</span>
                      </label>
                      <label className={`ar-model-file ${usdzModel ? 'has-file' : ''}`}>
                        <span><strong>USDZ model</strong><small>iPhone + iPad · maximum {meta.ar_model_max_mb} MB</small></span>
                        <input
                          type="file"
                          accept=".usdz,model/vnd.usdz+zip"
                          onChange={(event) => setUsdzModel(event.target.files?.[0] ?? null)}
                        />
                        <span className="ar-file-name">{usdzModel?.name ?? (currentArModels.some((model) => model.type === 'usdz') ? 'Current USDZ attached · choose to replace' : 'Choose .usdz')}</span>
                      </label>
                    </div>
                    <p className="ar-model-help">You may upload only GLB, but adding both formats gives customers the widest device support. Files are validated after saving the product.</p>
                  </>
                ) : (
                  <div className="ar-upgrade-note">
                    <div><strong>AR uploads are a Premium feature</strong><p>Upgrade your listing plan to attach interactive 3D product models.</p></div>
                    <Link className="btn btn-outline btn-sm" to="/vendor/boost">View Premium options</Link>
                  </div>
                )}
              </section>
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
              <input type="file" accept="image/*" onChange={(e) => setImageFiles(e.target.files ? Array.from(e.target.files).slice(0, 1) : [])} />
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
          <button
            className="btn btn-primary"
            type="submit"
            disabled={saveMutation.isPending || (ltype === 'product' && !meta?.profile_location.city)}
          >
            {saveMutation.isPending ? 'Saving…' : listingId ? 'Save changes' : 'Create listing'}
          </button>
        </div>
      </form>
    </DashLayout>
  );
}
