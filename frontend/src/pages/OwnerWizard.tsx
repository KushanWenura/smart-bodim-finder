import { useQuery } from '@tanstack/react-query';
import { type FormEvent, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../api';
import { RoleLayout } from '../components/Shell';

type Facility = { id: number; name: string };
type FormField = HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement;

export function OwnerWizard() {
  const nav = useNavigate();
  const [step, setStep] = useState(1);
  const [images, setImages] = useState<File[]>([]);
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);
  const { data: meta } = useQuery({ queryKey: ['meta'], queryFn: async () => (await api.get('/meta')).data });

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
      const created = (await api.post('/owner/listings', {
        title: fields.get('title'), description: fields.get('description'), propertyType: fields.get('propertyType'),
        price: Number(fields.get('price')), deposit: Number(fields.get('deposit') || 0), privateAddress: fields.get('privateAddress') || null,
        area: fields.get('area'), city: fields.get('city'), district: fields.get('district'), latitude: Number(fields.get('latitude')),
        longitude: Number(fields.get('longitude')), genderRule: fields.get('genderRule'), occupancy: Number(fields.get('occupancy')),
        availableFrom: fields.get('availableFrom') || null, sharingAllowed: fields.has('sharingAllowed'), furnished: fields.has('furnished'),
        houseRules: fields.get('houseRules') || null, facilityIds: fields.getAll('facilityIds').map(Number),
      })).data.data;
      for (const file of images) {
        const upload = new FormData();
        upload.append('image', file);
        await api.post(`/owner/listings/${created.id}/images`, upload);
      }
      if (fields.has('submitForReview')) await api.post(`/owner/listings/${created.id}/submit`);
      nav('/owner/listings');
    } catch (exception) {
      setError((exception as { message?: string }).message || 'The listing could not be saved.');
    } finally {
      setSaving(false);
    }
  };

  const addImages = (files: FileList | null) => {
    if (!files) return;
    const next = [...images, ...Array.from(files)];
    if (next.length > 10) {
      setError('A listing can have at most ten images.');
      return;
    }
    setImages(next);
  };

  return <RoleLayout role="owner">
    <div className="dash-head wizard-heading">
      <div><span className="eyebrow">Guided submission</span><h1>Create a property listing</h1><p>Four clear steps. Your information stays in this form while you move between them.</p></div>
      <div className="wizard-progress-copy"><strong>Step {step} of 4</strong><span>{Math.round(step / 4 * 100)}% complete</span></div>
    </div>
    <div className="wizard">
      <nav className="wizard-steps" aria-label="Listing creation progress">{['Basics', 'Location & rules', 'Facilities', 'Images & review'].map((name, index) => <button type="button" key={name} className={`wizard-step ${step === index + 1 ? 'active' : ''} ${step > index + 1 ? 'complete' : ''}`} onClick={() => index + 1 < step && setStep(index + 1)}><span>{step > index + 1 ? '✓' : index + 1}</span>{name}</button>)}</nav>
      <form className="form-card wizard-card" noValidate onSubmit={submit}>
        {error && <div className="notice notice-warning" role="alert">{error}</div>}
        <div data-step="1" className={step === 1 ? 'form' : 'hidden-step'}>
          <div className="step-kicker">Step 1 · The essentials</div><h2>Describe the accommodation</h2>
          <label className="field">Listing title<input className="input" name="title" required maxLength={160} /></label>
          <label className="field">Complete description<textarea className="textarea" name="description" required minLength={40} maxLength={8000} /></label>
          <div className="form-row"><label className="field">Property type<select className="select" name="propertyType">{(meta?.propertyTypes || ['boarding_room', 'private_room', 'annex']).map((type: string) => <option key={type} value={type}>{type.replaceAll('_', ' ')}</option>)}</select></label><label className="field">Accommodation rule<select className="select" name="genderRule"><option value="any">Open to anyone</option><option value="female_only">Female only</option><option value="male_only">Male only</option></select></label></div>
          <div className="form-row"><label className="field">Monthly LKR<input className="input" name="price" type="number" min="5000" max="2000000" required /></label><label className="field">Refundable deposit LKR<input className="input" name="deposit" type="number" min="0" max="5000000" /></label></div>
        </div>
        <div data-step="2" className={step === 2 ? 'form' : 'hidden-step'}>
          <div className="step-kicker">Step 2 · Privacy-aware location</div><h2>Location, occupancy and rules</h2>
          <label className="field">Private street address<input className="input" name="privateAddress" maxLength={300} /><span className="field-help">Stored privately and never displayed on the public listing.</span></label>
          <div className="form-row"><label className="field">Public area<input className="input" name="area" required /></label><label className="field">City<input className="input" name="city" required /></label></div>
          <div className="form-row"><label className="field">District<input className="input" name="district" required /></label><label className="field">Occupancy limit<input className="input" name="occupancy" type="number" min="1" max="20" defaultValue="1" required /></label></div>
          <div className="form-row"><label className="field">Latitude<input className="input" name="latitude" type="number" step="any" min="-90" max="90" defaultValue="6.9271" required /></label><label className="field">Longitude<input className="input" name="longitude" type="number" step="any" min="-180" max="180" defaultValue="79.8612" required /></label></div>
          <label className="field">Available from<input className="input" name="availableFrom" type="date" /></label><label className="field">House rules<textarea className="textarea" name="houseRules" maxLength={3000} /></label>
          <div className="check-row"><label className="check"><input type="checkbox" name="furnished" /> Furnished</label><label className="check"><input type="checkbox" name="sharingAllowed" /> Room sharing allowed</label></div>
        </div>
        <div data-step="3" className={step === 3 ? 'form' : 'hidden-step'}>
          <div className="step-kicker">Step 3 · What residents receive</div><h2>Choose available facilities</h2><p>Select only facilities that are currently usable.</p>
          <div className="facility-picker">{(meta?.facilities || []).map((facility: Facility) => <label className="facility-option" key={facility.id}><input type="checkbox" name="facilityIds" value={facility.id} />{facility.name}</label>)}</div>
        </div>
        <div data-step="4" className={step === 4 ? 'form' : 'hidden-step'}>
          <div className="step-kicker">Step 4 · Final review</div><h2>Photos and publication choice</h2><p>JPEG, PNG or WebP; up to 5 MB each and ten images maximum. The first image becomes the cover.</p>
          <label className="field upload-drop">Property images<input className="input" type="file" accept="image/jpeg,image/png,image/webp" multiple onChange={event => addImages(event.target.files)} /><span>Choose clear, recent photographs</span></label>
          <div className="image-preview-grid">{images.map((file, index) => <div className="image-file" key={`${file.name}-${index}`}><span>{index === 0 ? 'Cover' : `Image ${index + 1}`}</span><strong>{file.name}</strong><button type="button" className="btn btn-danger btn-sm" onClick={() => setImages(images.filter((_, itemIndex) => itemIndex !== index))}>Remove</button></div>)}</div>
          <label className="check"><input type="checkbox" name="submitForReview" disabled={!images.length} /> Submit for administrator review immediately</label><div className="notice notice-info">Saving without a photo creates a private draft. Administrator review requires at least one photo.</div>
        </div>
        <div className="actions-row"><span className="step-save-note">Your progress is kept while switching steps.</span>{step > 1 && <button type="button" className="btn btn-ghost" onClick={() => setStep(step - 1)}>← Back</button>}<button className="btn btn-primary" disabled={saving}>{step < 4 ? 'Continue →' : saving ? 'Saving…' : 'Save listing'}</button></div>
      </form>
    </div>
  </RoleLayout>;
}
