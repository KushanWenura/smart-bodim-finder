import {createContext,useContext,useEffect,useMemo,useState,type ReactNode} from 'react';
import {api,csrf} from './api';
import type {Role,User} from './types';
type AuthValue={user:User|null;loading:boolean;login:(email:string,password:string)=>Promise<User>;logout:()=>Promise<void>;refresh:()=>Promise<void>};
const AuthContext=createContext<AuthValue|null>(null);
export function AuthProvider({children}:{children:ReactNode}){const[user,setUser]=useState<User|null>(null);const[loading,setLoading]=useState(true);const refresh=async()=>{try{const{data}=await api.get('/auth/me');setUser(data.data)}catch{setUser(null)}finally{setLoading(false)}};useEffect(()=>{void refresh()},[]);const login=async(email:string,password:string)=>{await csrf();const{data}=await api.post('/auth/login',{email,password});setUser(data.data);return data.data};const logout=async()=>{await api.post('/auth/logout');setUser(null)};return <AuthContext.Provider value={useMemo(()=>({user,loading,login,logout,refresh}),[user,loading])}>{children}</AuthContext.Provider>}
export function useAuth(){const value=useContext(AuthContext);if(!value)throw new Error('AuthProvider missing');return value}
export function roleHome(role:Role){return `/${role}/dashboard`}
