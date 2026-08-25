import { Link } from 'react-router-dom';
import type { Listing } from '../types';

const money = (value: number) => `Rs. ${Number(value).toLocaleString('en-LK')}`;
const facilityIcon = (facility: string) => facility === 'WiFi' ? 'bi-wifi' : facility === 'Parking' ? 'bi-car-front' : facility === 'Air conditioning' ? 'bi-snow' : facility === 'Attached bathroom' ? 'bi-droplet' : facility === 'Kitchen access' ? 'bi-cup-hot' : 'bi-check2';

export function ListingCard({ item, favorite, onFavorite }: { item: Listing; favorite?: boolean; onFavorite?: (id: number) => void }) {
  const nearby = item.nearbyPlaces?.slice().sort((a, b) => a.distanceM - b.distanceM)[0];
  return <article className="sb-listing-card h-100">
    <div className="sb-listing-image">
      <Link to={`/listing/${item.id}`} aria-label={`View ${item.title}`}><img src={item.image || item.images?.[0]?.url} alt={item.images?.[0]?.alt || item.title} loading="lazy" /></Link>
      <div className="sb-card-badges"><span className="sb-verified"><i className="bi bi-patch-check-fill" /> Verified</span>{item.matchRank && <span className="sb-rank-badge">#{item.matchRank} {item.matchLabel}</span>}</div>
      {onFavorite && <button className={favorite ? 'active' : ''} onClick={() => onFavorite(item.id)} aria-label={favorite ? 'Remove saved listing' : 'Save listing'}><i className={`bi ${favorite ? 'bi-heart-fill' : 'bi-heart'}`} /></button>}
      {item.distanceKm !== undefined && <div className="sb-distance-badge"><i className="bi bi-signpost-split" /><div><strong>{item.distanceKm} km away</strong><span>about {item.commuteEstimateMinutes} min</span></div></div>}
    </div>
    <div className="sb-listing-body">
      <div className="sb-listing-place"><span><i className="bi bi-geo-alt" /> {item.area}, {item.city}</span><b><i className="bi bi-star-fill" /> {item.rating || 'New'} <small>({item.reviewCount})</small></b></div>
      <h3><Link to={`/listing/${item.id}`}>{item.title}</Link></h3>
      <div className="sb-listing-price"><strong>{money(item.price)}</strong><span>/ month</span>{item.deposit ? <small>Deposit {money(item.deposit)}</small> : null}</div>
      <div className="sb-tags">{item.facilities?.slice(0, 4).map(facility => <span key={facility}><i className={`bi ${facilityIcon(facility)}`} /> {facility}</span>)}</div>
      {nearby && <div className="sb-card-nearby"><span><i className="bi bi-compass" /> Closest essential</span><strong>{nearby.name}</strong><b>{Math.round(nearby.distanceM / 100) / 10} km</b></div>}
      <div className="sb-listing-foot"><span><i className="bi bi-house-door" /> {item.propertyType?.replaceAll('_', ' ')}</span><Link to={`/listing/${item.id}`}>See the full place <i className="bi bi-arrow-right" /></Link></div>
    </div>
  </article>;
}
