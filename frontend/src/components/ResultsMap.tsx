import L from 'leaflet';
import {useEffect,useRef} from 'react';
import {useNavigate} from 'react-router-dom';
import type {Listing} from '../types';

export function ResultsMap({items}:{items:Listing[]}){const ref=useRef<HTMLDivElement>(null);const nav=useNavigate();useEffect(()=>{if(!ref.current||!items.length)return;const map=L.map(ref.current).setView([items[0].latitude,items[0].longitude],11);L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap contributors'}).addTo(map);const bounds:L.LatLngExpression[]=[];items.forEach(item=>{const point:L.LatLngExpression=[item.latitude,item.longitude];bounds.push(point);const marker=L.circleMarker(point,{radius:9,color:'#0a4d43',fillColor:'#cbe96b',fillOpacity:1}).addTo(map);marker.bindTooltip(`${item.title} — Rs. ${item.price.toLocaleString('en-LK')}`);marker.on('click',()=>nav(`/listing/${item.id}`))});if(bounds.length>1)map.fitBounds(L.latLngBounds(bounds),{padding:[30,30]});return()=>{map.remove();}},[items,nav]);return <div ref={ref} className="results-map" role="region" aria-label={`Map of ${items.length} search results`}/>}
