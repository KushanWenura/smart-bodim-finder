import {fireEvent,render,screen} from '@testing-library/react';
import {MemoryRouter} from 'react-router-dom';
import {describe,expect,it} from 'vitest';
import {ListingCard} from '../components/ListingCard';
import {AiChatbot} from '../components/Shell';
const listing={id:1,slug:'SBF-1',title:'Quiet room in Moratuwa',description:'Clean room',propertyType:'private_room',price:25000,area:'Moratuwa',city:'Colombo',district:'Colombo',latitude:6.7,longitude:79.8,genderRule:'any',occupancy:1,sharingAllowed:false,available:true,furnished:true,status:'published',rating:4.5,reviewCount:4,favoriteCount:2,viewCount:20,facilities:['WiFi'],images:[{id:1,url:'test.jpg',thumbnail:'test.jpg',alt:'Room',cover:true}],image:'test.jpg'};
describe('ListingCard',()=>{it('renders accessible listing information and LKR price',()=>{render(<MemoryRouter><ListingCard item={listing}/></MemoryRouter>);expect(screen.getByRole('heading',{name:/quiet room/i})).toBeInTheDocument();expect(screen.getByText(/25,000/)).toBeInTheDocument();expect(screen.getByText('WiFi')).toBeInTheDocument()})});

describe('AiChatbot',()=>{it('opens the transparent ranking interface from the floating launcher',()=>{render(<MemoryRouter><AiChatbot/></MemoryRouter>);fireEvent.click(screen.getByRole('button',{name:/open buddy ai assistant/i}));expect(screen.getByRole('dialog',{name:/buddy ai assistant/i})).toBeInTheDocument();expect(screen.getByText(/friendly guidance · honest ranking/i)).toBeInTheDocument();expect(screen.getByPlaceholderText(/campus, budget, wifi, ac, parking/i)).toBeInTheDocument();expect(screen.getByRole('button',{name:/university of moratuwa/i})).toBeInTheDocument()})});
