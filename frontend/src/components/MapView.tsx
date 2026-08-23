import L from 'leaflet';
import {useEffect,useRef} from 'react';
import type {NearbyPlace} from '../types';
import 'leaflet/dist/leaflet.css';

const placeColour:Record<string,string>={bus_station:'#2477d4',train_station:'#7856c8',supermarket:'#ef6b3a',hospital:'#d84d57',food:'#d59620'};
const placeLabel=(type:string)=>type.replaceAll('_',' ').replace(/\b\w/g,letter=>letter.toUpperCase());
const distanceLabel=(metres:number)=>metres<1000?`${metres} m`:`${(metres/1000).toFixed(1)} km`;

export function MapView({latitude,longitude,label,places=[],highlightedType='all'}:{latitude:number;longitude:number;label:string;places?:NearbyPlace[];highlightedType?:string}){
  const element=useRef<HTMLDivElement>(null);
  useEffect(()=>{
    if(!element.current)return;
    const map=L.map(element.current,{scrollWheelZoom:false,zoomControl:true});
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap contributors'}).addTo(map);
    const home:L.LatLngTuple=[latitude,longitude];
    const bounds:L.LatLngTuple[]=[home];
    const homeMarker=L.circleMarker(home,{radius:12,color:'#0d3b33',weight:4,fillColor:'#bfead8',fillOpacity:1}).addTo(map);
    const homePopup=document.createElement('div');
    const homeTitle=document.createElement('strong');homeTitle.textContent='Approximate property area';
    const homeCopy=document.createElement('div');homeCopy.textContent=label;
    homePopup.append(homeTitle,homeCopy);homeMarker.bindPopup(homePopup);
    places.filter(place=>place.latitude!=null&&place.longitude!=null).forEach(place=>{
      const point:L.LatLngTuple=[Number(place.latitude),Number(place.longitude)];bounds.push(point);
      const active=highlightedType==='all'||highlightedType===place.type;
      const colour=placeColour[place.type]||'#16745e';
      const marker=L.circleMarker(point,{radius:active?10:7,color:active?'#ffffff':colour,weight:active?3:2,fillColor:colour,fillOpacity:active?1:.38}).addTo(map);
      const popup=document.createElement('div');
      const kind=document.createElement('small');kind.textContent=placeLabel(place.type);
      const name=document.createElement('strong');name.textContent=place.name;
      const distance=document.createElement('div');distance.textContent=`${distanceLabel(place.distanceM)} from the property area`;
      popup.append(kind,name,distance);marker.bindPopup(popup);
      marker.bindTooltip(`${place.name} · ${distanceLabel(place.distanceM)}`);
    });
    if(bounds.length>1)map.fitBounds(L.latLngBounds(bounds),{padding:[38,38],maxZoom:16});else map.setView(home,15);
    window.setTimeout(()=>map.invalidateSize(),0);
    return()=>{map.remove()};
  },[latitude,longitude,label,places,highlightedType]);
  return <div ref={element} className={`leaflet-map ${places.length?'nearby-map':''}`} role="region" aria-label={places.length?`Map of ${label} and ${places.length} nearby essential places`:`Approximate map location for ${label}`}/>;
}
