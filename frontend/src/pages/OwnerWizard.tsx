import { useQuery, useQueryClient } from '@tanstack/react-query';
import { type FormEvent, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { api } from '../api';
import { BuddyMark, RoleLayout } from '../components/Shell';
import type { Listing } from '../types';

type Facility = { id: number; name: string };
type FormField = HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement;

export function OwnerWizard() {
  const nav = useNavigate();
  const queryClient = useQueryClient();
  const { id } = useParams();
  const editingId = id ? Number(id) : null;
  const editing = editingId !== null;
  const [step, setStep] = useState(1);
  const [images, setImages] = useState<File[]>([]);
  const [existingImages, setExistingImages] = useState<Listing['images']>([]);
  const [error, setError] = useState('');
  const [locationNotice,setLocationNotice]=useState('');
  const [locationFields,setLocationFields]=useState({area:'',city:'',district:'',latitude:'6.9271',longitude:'79.8612'});
  const [imageChecks,setImageChecks]=useState<Record<string,{score:number;flags:string[]}>>({});
  const [saving, setSaving] = useState(false);
  const { data: meta } = useQuery({ queryKey: ['meta'], queryFn: async () => (await api.get('/meta')).data });
  const { data: listing, isLoading: listingLoading, error: listingError } = useQuery<Listing>({
    queryKey: ['owner-listing', editingId],
    queryFn: async () => (await api.get(`/owner/listings/${editingId}`)).data.data,
    enabled: editing,
  });

  useEffect(() => {
    if (!listing) return;
    setLocationFields({ area: listing.area, city: listing.city, district: listing.district, latitude: String(listing.latitude), longitude: String(listing.longitude) });
    setExistingImages(listing.images || []);
  }, [listing]);

  const validateStep = (form: HTMLFormElement, targetStep = step) => {
    const panel = form.querySelector(`[data-step="${targetStep}"]`);
    const fields = Array.from(panel?.querySelectorAll<FormField>('input, textarea, select') ?? []);
    const invalid = fields.find(field => !field.checkValidity());
    invalid?.reportValidity();
    return !invalid;
  };

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = event.currentTarget;
    setError('');
    if (step < 4) {
      if (validateStep(form)) {
        setStep(current => current + 1);
        window.scrollTo({ top: 0, behavior: 'smooth' });
      }
      return;
    }
    for (let target = 1; target <= 3; target += 1) {
      if (!validateStep(form, target)) {
        setStep(target);
        setError('Please complete the highlighted fields before saving.');
        return;
      }
    }
    setSaving(true);
    try {
      const fields = new FormData(form);
      const payload = {
        title: fields.get('title'), description: fields.get('description'), propertyType: fields.get('propertyType'),
        price: Number(fields.get('price')), deposit: Number(fields.get('deposit') || 0), privateAddress: fields.get('privateAddress') || null,
        area: fields.get('area'), city: fields.get('city'), district: fields.get('district'), latitude: Number(fields.get('latitude')),
        longitude: Number(fields.get('longitude')), genderRule: fields.get('genderRule'), occupancy: Number(fields.get('occupancy')),
        availableFrom: fields.get('availableFrom') || null, sharingAllowed: fields.has('sharingAllowed'), furnished: fields.has('furnished'),
        houseRules: fields.get('houseRules') || null, facilityIds: fields.getAll('facilityIds').map(Number),
      };
      const saved = (editing
        ? await api.put(`/owner/listings/${editingId}`, payload)
        : await api.post('/owner/listings', payload)).data.data as Listing;
      for (const file of images) {
        const upload = new FormData();
        upload.append('image', file);
        await api.post(`/owner/listings/${saved.id}/images`, upload);
      }
      if (fields.has('submitForReview') && ['draft', 'rejected', 'rejected_changes'].includes(saved.status)) await api.post(`/owner/listings/${saved.id}/submit`);
      await queryClient.invalidateQueries({ queryKey: ['owner-listings'] });
      nav('/owner/listings');
    } catch (exception) {
      setError((exception as { message?: string }).message || 'The listing could not be saved.');
    } finally {
      setSaving(false);
    }
  };

  const removeExistingImage = async (imageId: number) => {
    if (!editingId || !window.confirm('Remove this photo from the listing?')) return;
    setError('');
    try {
      await api.delete(`/owner/listings/${editingId}/images/${imageId}`);
      setExistingImages(current => current.filter(image => image.id !== imageId));
    } catch (exception) {
      setError((exception as { message?: string }).message || 'The photo could not be removed.');
    }
  };

  const normalizeLocation=async()=>{setLocationNotice('Checking Sri Lankan place aliases…');try{const response=await api.get('/address/normalize',{params:{q:`${locationFields.area} ${locationFields.city}`}});const match=response.data.data;if(!match){setLocationNotice('No catalog match. Keep your wording and verify the map coordinates.');return}setLocationFields({area:match.area||locationFields.area,city:match.city||locationFields.city,district:match.district||locationFields.district,latitude:String(match.latitude||locationFields.latitude),longitude:String(match.longitude||locationFields.longitude)});setLocationNotice(`Matched ${match.area}, ${match.city} with ${match.confidence} confidence. Verify the marker before publishing.`)}catch{setLocationNotice('Location helper is unavailable. You can still enter the location manually.')}};

  const addImages = async(files: FileList | null) => {
    if (!files) return;
    const next = [...images, ...Array.from(files)];
    if (next.length > 10) {
      setError('A listing can have at most ten images.');
      return;
    }
    setImages(next);
    for(const file of Array.from(files)){try{const bitmap=await createImageBitmap(file);let score=100;const flags:string[]=[];if(bitmap.width<800||bitmap.height<600){score-=28;flags.push('Low resolution')}const ratio=bitmap.width/bitmap.height;if(ratio>2.4||ratio<.55){score-=16;flags.push('Extreme shape')}if(file.size<45000){score-=10;flags.push('Heavy compression')}setImageChecks(current=>({...current,[file.name]:{score,flags}}));bitmap.close()}catch{setImageChecks(current=>({...current,[file.name]:{score:0,flags:['Unreadable image']}}))}}
  };

  if (editing && listingLoading) return <RoleLayout role="owner"><div className="skeleton" /></RoleLayout>;
  if (editing && (listingError || !listing)) return <RoleLayout role="owner"><div className="notice notice-warning">This listing could not be opened. It may not belong to this owner account.</div></RoleLayout>;

  const reviewStatus = listing?.status;
  const totalPhotos = existingImages.length + images.length;

  return <RoleLayout role="owner">
    <div className="dash-head wizard-heading">
      <div><span className="eyebrow">{editing ? 'Listing editor' : 'Guided submission'}</span><h1>{editing ? 'Edit property listing' : 'Create a property listing'}</h1><p>{editing ? 'Update rent, facilities, availability, rules or photos. Important public changes return to administrator review.' : 'Four clear steps. Your information stays in this form while you move between them.'}</p></div>
      <div className="wizard-progress-copy"><strong>Step {step} of 4</strong><span>{Math.round(step / 4 * 100)}% complete</span></div>
    </div>
    <div className="wizard">
      <nav className="wizard-steps" aria-label="Listing creation progress">{['Basics', 'Location & rules', 'Facilities', 'Images & review'].map((name, index) => <button type="button" key={name} className={`wizard-step ${step === index + 1 ? 'active' : ''} ${step > index + 1 ? 'complete' : ''}`} disabled={index + 1 > step} aria-current={step === index + 1 ? 'step' : undefined} onClick={() => index + 1 < step && setStep(index + 1)}><span>{step > index + 1 ? '✓' : index + 1}</span>{name}</button>)}</nav>
      <form key={listing?.id || 'create'} className="form-card wizard-card" noValidate onSubmit={submit}>
        {error && <div className="notice notice-warning" role="alert">{error}</div>}
        {editing && reviewStatus === 'change_pending' && <div className="notice notice-info">These changes are currently waiting for administrator approval. You can still correct the details while they are being reviewed.</div>}
        {editing && reviewStatus === 'rejected_changes' && <div className="notice notice-warning">The administrator requested corrections. Update the listing and select resubmit on the final step.</div>}
        <div data-step="1" className={step === 1 ? 'form' : 'hidden-step'}>
          <div className="step-kicker">Step 1 · The essentials</div><h2>Describe the accommodation</h2>
          <label className="field">Listing title<input className="input" name="title" required maxLength={160} defaultValue={listing?.title} /></label>
          <label className="field">Complete description<textarea className="textarea" name="description" required minLength={40} maxLength={8000} defaultValue={listing?.description} /></label>
          <div className="form-row"><label className="field">Property type<select className="select" name="propertyType" defaultValue={listing?.propertyType}>{(meta?.propertyTypes || ['boarding_room', 'private_room', 'annex']).map((type: string) => <option key={type} value={type}>{type.replaceAll('_', ' ')}</option>)}</select></label><label className="field">Accommodation rule<select className="select" name="genderRule" defaultValue={listing?.genderRule || 'any'}><option value="any">Open to anyone</option><option value="female_only">Female only</option><option value="male_only">Male only</option></select></label></div>
          <div className="form-row"><label className="field">Monthly LKR<input className="input" name="price" type="number" min="5000" max="2000000" required defaultValue={listing?.price} /></label><label className="field">Refundable deposit LKR<input className="input" name="deposit" type="number" min="0" max="5000000" defaultValue={listing?.deposit} /></label></div>
        </div>
        <div data-step="2" className={step === 2 ? 'form' : 'hidden-step'}>
          <div className="step-kicker">Step 2 · Privacy-aware location</div><h2>Location, occupancy and rules</h2>
          <label className="field">Private street address<input className="input" name="privateAddress" maxLength={300} defaultValue={listing?.privateAddress} /><span className="field-help">Stored privately and never displayed on the public listing.</span></label>
          <div className="form-row"><label className="field">Public area<input className="input" name="area" required value={locationFields.area} onChange={event=>setLocationFields(current=>({...current,area:event.target.value}))}/></label><label className="field">City<input className="input" name="city" required value={locationFields.city} onChange={event=>setLocationFields(current=>({...current,city:event.target.value}))}/></label></div>
          <div className="form-row"><label className="field">District<input className="input" name="district" required value={locationFields.district} onChange={event=>setLocationFields(current=>({...current,district:event.target.value}))}/></label><label className="field">Occupancy limit<input className="input" name="occupancy" type="number" min="1" max="20" defaultValue={listing?.occupancy || 1} required /></label></div>
          <button type="button" className="location-normalize" onClick={()=>void normalizeLocation()}><BuddyMark className="is-symbol"/><span><strong>Check Sri Lankan place spelling</strong><small>Matches English, Sinhala, Tamil and common alternative spellings</small></span></button>{locationNotice&&<div className="notice notice-info">{locationNotice}</div>}
          <div className="form-row"><label className="field">Latitude<input className="input" name="latitude" type="number" step="any" min="-90" max="90" value={locationFields.latitude} onChange={event=>setLocationFields(current=>({...current,latitude:event.target.value}))} required /></label><label className="field">Longitude<input className="input" name="longitude" type="number" step="any" min="-180" max="180" value={locationFields.longitude} onChange={event=>setLocationFields(current=>({...current,longitude:event.target.value}))} required /></label></div>
          <label className="field">Available from<input className="input" name="availableFrom" type="date" defaultValue={listing?.availableFrom} /></label><label className="field">House rules<textarea className="textarea" name="houseRules" maxLength={3000} defaultValue={listing?.houseRules} /></label>
          <div className="check-row"><label className="check"><input type="checkbox" name="furnished" defaultChecked={listing?.furnished} /> Furnished</label><label className="check"><input type="checkbox" name="sharingAllowed" defaultChecked={listing?.sharingAllowed} /> Room sharing allowed</label></div>
        </div>
        <div data-step="3" className={step === 3 ? 'form' : 'hidden-step'}>
          <div className="step-kicker">Step 3 · What residents receive</div><h2>Choose available facilities</h2><p>Select only facilities that are currently usable.</p>
          <div className="facility-picker">{(meta?.facilities || []).map((facility: Facility) => <label className="facility-option" key={facility.id}><input type="checkbox" name="facilityIds" value={facility.id} defaultChecked={listing?.facilities?.includes(facility.name)} />{facility.name}</label>)}</div>
        </div>
        <div data-step="4" className={step === 4 ? 'form' : 'hidden-step'}>
          <div className="step-kicker">Step 4 · Final review</div><h2>Photos and publication choice</h2><p>JPEG, PNG or WebP; up to 5 MB each and ten images maximum. The first image becomes the cover.</p>
          <label className="field upload-drop">Property images<input className="input" type="file" accept="image/jpeg,image/png,image/webp" multiple onChange={event => addImages(event.target.files)} /><span>Choose clear, recent photographs</span></label>
          <div className="image-preview-grid">{existingImages.map((image, index) => <div className="image-file image-file-existing" key={image.id}><img src={image.thumbnail || image.url} alt={image.alt || `Current listing photo ${index + 1}`} /><span>{image.cover ? 'Current cover' : `Current image ${index + 1}`}</span><button type="button" className="btn btn-danger btn-sm" onClick={() => void removeExistingImage(image.id)}>Remove photo</button></div>)}{images.map((file, index) => <div className="image-file" key={`${file.name}-${index}`}><span>{existingImages.length + index === 0 ? 'New cover' : `New image ${existingImages.length + index + 1}`}</span><strong>{file.name}</strong>{imageChecks[file.name]&&<small className={`image-check is-${imageChecks[file.name].score>=70?'good':'warning'}`}><i className={`bi ${imageChecks[file.name].score>=70?'bi-check-circle':'bi-exclamation-triangle'}`}/>{imageChecks[file.name].score}/100 · {imageChecks[file.name].flags.join(', ')||'Good resolution and framing'}</small>}<button type="button" className="btn btn-danger btn-sm" onClick={() => setImages(images.filter((_, itemIndex) => itemIndex !== index))}>Remove</button></div>)}</div>
          {(!editing || ['draft', 'rejected', 'rejected_changes'].includes(reviewStatus || '')) && <label className="check"><input type="checkbox" name="submitForReview" disabled={!totalPhotos} /> {reviewStatus === 'rejected_changes' ? 'Resubmit corrected changes for administrator review' : 'Submit for administrator review immediately'}</label>}<div className="notice notice-info">{editing && ['published', 'change_pending'].includes(reviewStatus || '') ? 'Saving public details or photo changes places the listing in change review. Private address-only corrections stay published.' : 'Administrator review requires at least one photo.'}</div>
        </div>
        <div className="actions-row"><span className="step-save-note">Your progress is kept while switching steps.</span>{step > 1 && <button type="button" className="btn btn-ghost" onClick={() => setStep(step - 1)}>← Back</button>}<button className="btn btn-primary" disabled={saving}>{step < 4 ? 'Continue →' : saving ? 'Saving…' : editing ? 'Save changes' : 'Save listing'}</button></div>
      </form>
    </div>
  </RoleLayout>;
}
