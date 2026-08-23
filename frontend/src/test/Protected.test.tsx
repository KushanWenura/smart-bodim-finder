import {render,screen} from '@testing-library/react';
import {MemoryRouter,Route,Routes} from 'react-router-dom';
import {beforeEach,describe,expect,it,vi} from 'vitest';
import {Protected} from '../components/Protected';

const auth={user:null as null|{id:number;role:'tenant'|'owner'|'admin';name:string;email:string;status:string},loading:false};
vi.mock('../auth',()=>({useAuth:()=>auth}));

function subject(){return <MemoryRouter initialEntries={['/admin']}><Routes><Route path="/login" element={<div>Login page</div>}/><Route path="/tenant/dashboard" element={<div>Tenant home</div>}/><Route path="/admin" element={<Protected role="admin"><div>Admin secret</div></Protected>}/></Routes></MemoryRouter>}

describe('Protected route',()=>{beforeEach(()=>{auth.user=null;auth.loading=false});it('redirects a guest to login',()=>{render(subject());expect(screen.getByText('Login page')).toBeInTheDocument()});it('redirects a tenant away from admin content',()=>{auth.user={id:1,role:'tenant',name:'Tenant',email:'t@example.com',status:'active'};render(subject());expect(screen.getByText('Tenant home')).toBeInTheDocument();expect(screen.queryByText('Admin secret')).not.toBeInTheDocument()});it('renders matching-role content',()=>{auth.user={id:2,role:'admin',name:'Admin',email:'a@example.com',status:'active'};render(subject());expect(screen.getByText('Admin secret')).toBeInTheDocument()})});
