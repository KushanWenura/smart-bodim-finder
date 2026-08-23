import L from 'leaflet';
import {useEffect,useRef} from 'react';
import 'leaflet/dist/leaflet.css';

export function MapView({latitude,longitude,label}:{latitude:number;longitude:number;label:string}){
  const element=useRef<HTMLDivElement>(null);
  useEffect(()=>{if(!element.current)return;const map=L.map(element.current,{scrollWheelZoom:false}).setView([latitude,longitude],15);L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap contributors'}).addTo(map);L.circle([latitude,longitude],{radius:220,color:'#11695c',fillColor:'#cbe96b',fillOpacity:.35}).addTo(map).bindPopup(label);setTimeout(()=>map.invalidateSize(),0);return()=>{map.remove();}},[latitude,longitude,label]);
  return <div ref={element} className="leaflet-map" role="region" aria-label={`Approximate map location for ${label}`}/>;
}
