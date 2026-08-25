import {fireEvent,render,screen} from '@testing-library/react';
import {describe,expect,it,vi} from 'vitest';
import {NeighbourhoodExplorer} from '../components/NeighbourhoodExplorer';

vi.mock('../components/MapView',()=>({MapView:({places}:{places:unknown[]})=><div role="region" aria-label={`Test map with ${places.length} places`}/> }));

const places=[
  {type:'bus_station',name:'Malabe Bus Stand',distanceM:420,latitude:6.9043,longitude:79.9548},
  {type:'supermarket',name:'Cargills Food City Malabe',distanceM:610,latitude:6.9051,longitude:79.9542},
  {type:'hospital',name:'Neville Fernando Teaching Hospital',distanceM:1800,latitude:6.9235,longitude:79.9602},
];

describe('NeighbourhoodExplorer',()=>{
  it('opens the highlighted essentials map and filters distance cards',()=>{
    render(<NeighbourhoodExplorer latitude={6.91} longitude={79.95} label="Malabe" places={places}/>);
    expect(screen.getByText('Cargills Food City Malabe')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button',{name:/explore nearby with buddy/i}));
    expect(screen.getByRole('region',{name:/test map with 3 places/i})).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button',{name:/hospitals/i}));
    expect(screen.getByText('Neville Fernando Teaching Hospital')).toBeInTheDocument();
    expect(screen.queryByText('Malabe Bus Stand')).not.toBeInTheDocument();
  });
});
