import axios from 'axios';
export const api=axios.create({baseURL:'/api/v1',withCredentials:true,withXSRFToken:true,headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});
export async function csrf(){await axios.get('/sanctum/csrf-cookie',{withCredentials:true});}
api.interceptors.response.use(r=>r,e=>Promise.reject({message:e.response?.data?.message||'Something went wrong.',errors:e.response?.data?.errors,status:e.response?.status,code:e.response?.data?.code,suggestions:e.response?.data?.suggestions}));
