import React, { useCallback, useEffect, useMemo, useState } from 'react';
import type { NearbyPlace } from '../types';
import { MapView } from './MapView';

const details: Record<string, { label: string; icon: string; colour: string }> = {
  bus_station: { label: 'Bus stops', icon: 'bi-bus-front', colour: '#2477d4' },
  train_station: { label: 'Train stations', icon: 'bi-train-front', colour: '#7856c8' },
  supermarket: { label: 'Cargills & markets', icon: 'bi-cart3', colour: '#ef6b3a' },
  hospital: { label: 'Hospitals', icon: 'bi-hospital', colour: '#d84d57' },
  food: { label: 'Food places', icon: 'bi-cup-hot', colour: '#d59620' },
};
const order = ['bus_station', 'train_station', 'supermarket', 'hospital', 'food'];
const distance = (metres: number) => metres < 1000 ? `${metres} m` : `${(metres / 1000).toFixed(1)} km`;

export function NeighbourhoodExplorer({ latitude, longitude, label, places, autoOpen = false }: { latitude: number; longitude: number; label: string; places: NearbyPlace[]; autoOpen?: boolean }) {
  const [open, setOpen] = useState(autoOpen);
  const [selected, setSelected] = useState('all');
  useEffect(() => { if (autoOpen) setOpen(true); }, [autoOpen]);
  const available = useMemo(() => order.filter(type => places.some(place => place.type === type)), [places]);
  const visible = selected === 'all' ? places : places.filter(place => place.type === selected);
  const openExplorer = useCallback(() => { setOpen(true); window.setTimeout(() => document.getElementById('neighbourhood-explorer')?.scrollIntoView?.({ behavior: 'smooth', block: 'start' }), 50); }, []);
  useEffect(() => { const listener = () => openExplorer(); window.addEventListener('smartbodim:open-neighbourhood', listener); return () => window.removeEventListener('smartbodim:open-neighbourhood', listener); }, [openExplorer]);

  return <section className="sb-detail-section sb-neighbourhood" id="neighbourhood-explorer">
    <header className="sb-neighbourhood-head"><div><span className="sb-kicker"><i className="bi bi-stars" /> Buddy neighbourhood view</span><h2>See what your daily life looks like here.</h2><p>Compare the nearest transport, shopping, healthcare and food options around the approximate property area.</p></div><button className="btn sb-nearby-ai-button" aria-expanded={open} onClick={() => open ? setOpen(false) : openExplorer()}><i className="bi bi-stars" />{open ? 'Hide nearby map' : 'Explore nearby with Buddy'}<i className={`bi ${open ? 'bi-chevron-up' : 'bi-arrow-right'}`} /></button></header>
    {!open && <div className="sb-nearby-preview">{places.map(place => { const item = details[place.type] || { label: place.type.replaceAll('_', ' '), icon: 'bi-geo-alt', colour: '#16745e' }; return <article key={place.type}><span style={{ '--place-colour': item.colour } as React.CSSProperties}><i className={`bi ${item.icon}`} /></span><div><small>{item.label}</small><strong>{place.name}</strong></div><b>{distance(place.distanceM)}</b></article>; })}</div>}
    {open && <div className="sb-nearby-explorer">
      <div className="sb-ai-method"><span><i className="bi bi-cpu" /></span><div><strong>How this result is produced</strong><p>Buddy AI helps match the property to your request. Nearby-place distances are then calculated from coordinates, so the map remains clear and verifiable.</p></div></div>
      <div className="sb-place-filters" role="group" aria-label="Filter nearby places"><button className={selected === 'all' ? 'active' : ''} onClick={() => setSelected('all')}><i className="bi bi-grid" /> All essentials</button>{available.map(type => { const item = details[type]; return <button className={selected === type ? 'active' : ''} style={{ '--place-colour': item.colour } as React.CSSProperties} key={type} onClick={() => setSelected(type)}><i className={`bi ${item.icon}`} /> {item.label}</button>; })}</div>
      <div className="sb-nearby-layout"><div className="sb-nearby-map-wrap"><MapView latitude={latitude} longitude={longitude} label={label} places={places} highlightedType={selected} /><div className="sb-map-legend"><span><i /> Approximate property area</span>{available.map(type => <span key={type}><i style={{ background: details[type].colour }} /> {details[type].label}</span>)}</div></div><div className="sb-nearby-list">{visible.map(place => { const item = details[place.type] || { label: place.type.replaceAll('_', ' '), icon: 'bi-geo-alt', colour: '#16745e' }; return <article key={place.type}><span style={{ '--place-colour': item.colour } as React.CSSProperties}><i className={`bi ${item.icon}`} /></span><div><small>{item.label}</small><strong>{place.name}</strong><p>Straight-line distance from the approximate property marker</p></div><b>{distance(place.distanceM)}</b></article>; })}</div></div>
      <small className="sb-distance-disclaimer"><i className="bi bi-info-circle" /> Distances are straight-line estimates, not live walking or traffic routes. Confirm the route before making a decision.</small>
    </div>}
  </section>;
}
