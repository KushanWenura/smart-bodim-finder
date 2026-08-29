import {lazy,Suspense,useEffect,useLayoutEffect} from 'react';
import {Navigate,Route,Routes,useLocation} from 'react-router-dom';
import {useAuth} from './auth';
import {Protected} from './components/Protected';
import {PublicLayout} from './components/Shell';

const Home=lazy(()=>import('./pages/Home'));
const Search=lazy(()=>import('./pages/Search'));
const Nearby=lazy(()=>import('./pages/Nearby'));
const ListingDetail=lazy(()=>import('./pages/ListingDetail'));
const Login=lazy(()=>import('./pages/Auth').then(m=>({default:m.Login})));
const Register=lazy(()=>import('./pages/Auth').then(m=>({default:m.Register})));
const ForgotPassword=lazy(()=>import('./pages/Password').then(m=>({default:m.ForgotPassword})));
const ResetPassword=lazy(()=>import('./pages/Password').then(m=>({default:m.ResetPassword})));
const InfoPage=lazy(()=>import('./pages/Info').then(m=>({default:m.InfoPage})));
const AboutPage=lazy(()=>import('./pages/Info').then(m=>({default:m.AboutPage})));
const Messages=lazy(()=>import('./pages/CommonRole').then(m=>({default:m.Messages})));
const Notifications=lazy(()=>import('./pages/CommonRole').then(m=>({default:m.Notifications})));
const RentalJourney=lazy(()=>import('./pages/RentalJourney'));
const VisitShare=lazy(()=>import('./pages/VisitShare'));
const TenantDashboard=lazy(()=>import('./pages/Tenant').then(m=>({default:m.TenantDashboard})));
const Favorites=lazy(()=>import('./pages/Tenant').then(m=>({default:m.Favorites})));
const Compare=lazy(()=>import('./pages/Tenant').then(m=>({default:m.Compare})));
const SavedSearches=lazy(()=>import('./pages/Tenant').then(m=>({default:m.SavedSearches})));
const MyReviews=lazy(()=>import('./pages/Tenant').then(m=>({default:m.MyReviews})));
const Profile=lazy(()=>import('./pages/Account').then(m=>({default:m.Profile})));
const Security=lazy(()=>import('./pages/Account').then(m=>({default:m.Security})));
const OwnerDashboard=lazy(()=>import('./pages/Owner').then(m=>({default:m.OwnerDashboard})));
const OwnerAnalytics=lazy(()=>import('./pages/OwnerAnalytics'));
const OwnerListings=lazy(()=>import('./pages/Owner').then(m=>({default:m.OwnerListings})));
const OwnerWizard=lazy(()=>import('./pages/OwnerWizard').then(m=>({default:m.OwnerWizard})));
const ListingHistory=lazy(()=>import('./pages/OwnerExtras').then(m=>({default:m.ListingHistory})));
const OwnerReviews=lazy(()=>import('./pages/OwnerExtras').then(m=>({default:m.OwnerReviews})));
const AdminDashboard=lazy(()=>import('./pages/Admin').then(m=>({default:m.AdminDashboard})));
const AdminListings=lazy(()=>import('./pages/Admin').then(m=>({default:m.AdminListings})));
const AdminOwners=lazy(()=>import('./pages/Admin').then(m=>({default:m.AdminOwners})));
const AdminUsers=lazy(()=>import('./pages/Admin').then(m=>({default:m.AdminUsers})));
const AdminReviews=lazy(()=>import('./pages/Admin').then(m=>({default:m.AdminReviews})));
const AdminNotifications=lazy(()=>import('./pages/Admin').then(m=>({default:m.AdminNotifications})));
const AdminAi=lazy(()=>import('./pages/Admin').then(m=>({default:m.AdminAi})));
const AdminAudit=lazy(()=>import('./pages/Admin').then(m=>({default:m.AdminAudit})));
const AdminSearch=lazy(()=>import('./pages/AdminSearch').then(m=>({default:m.AdminSearch})));
const AdminTrust=lazy(()=>import('./pages/AdminTrust'));
const AdminSystem=lazy(()=>import('./pages/AdminSystem'));

function ProtectedPage({role,children}:{role:'tenant'|'owner'|'admin';children:React.ReactNode}){return <Protected role={role}>{children}</Protected>}
function RoleRedirect(){const{user}=useAuth();return <Navigate to={user?`/${user.role}/dashboard`:'/login'} replace/>}
function NotFound(){return <PublicLayout><section className="page"><div className="container empty"><h1>404</h1><h2>This page took a wrong turn.</h2></div></section></PublicLayout>}

const pageTitles:Array<[RegExp,string]>=[
  [/^\/$/,'Your friendly stay finder'],[/^\/search/,'Discover verified stays'],[/^\/nearby/,'Commute match'],[/^\/listing\//,'Stay details'],[/^\/login/,'Log in'],[/^\/register/,'Create an account'],[/^\/(forgot|reset)-password/,'Account recovery'],[/^\/about/,'Our story'],[/^\/safety/,'Safety guide'],[/^\/privacy/,'Privacy'],[/^\/terms/,'Terms'],[/^\/tenant\/dashboard/,'Tenant dashboard'],[/^\/owner\/dashboard/,'Owner dashboard'],[/^\/admin\/dashboard/,'Operations dashboard'],[/^\/tenant\//,'Tenant workspace'],[/^\/owner\//,'Owner workspace'],[/^\/admin\//,'Administration'],
];

export function RouteScrollManager(){
  const{pathname,hash}=useLocation();
  useEffect(()=>{const previous=window.history.scrollRestoration;window.history.scrollRestoration='manual';return()=>{window.history.scrollRestoration=previous}},[]);
  useEffect(()=>{const label=pageTitles.find(([pattern])=>pattern.test(pathname))?.[1]??'Page not found';document.title=`${label} | BodimBuddy.lk`},[pathname]);
  useLayoutEffect(()=>{
    if(hash){
      const frame=window.requestAnimationFrame(()=>document.getElementById(decodeURIComponent(hash.slice(1)))?.scrollIntoView({block:'start'}));
      return()=>window.cancelAnimationFrame(frame);
    }
    window.scrollTo({top:0,left:0,behavior:'auto'});
  },[pathname,hash]);
  return null;
}

export default function App(){return <><RouteScrollManager/><Suspense fallback={<main id="main" className="page container"><div className="skeleton"/></main>}><Routes>
<Route path="/" element={<Home/>}/><Route path="/search" element={<Search/>}/><Route path="/nearby" element={<Nearby/>}/><Route path="/listing/:id" element={<ListingDetail/>}/><Route path="/visit/:token" element={<VisitShare/>}/><Route path="/login" element={<Login/>}/><Route path="/register" element={<Register/>}/><Route path="/forgot-password" element={<ForgotPassword/>}/><Route path="/reset-password" element={<ResetPassword/>}/><Route path="/about" element={<AboutPage/>}/><Route path="/safety" element={<InfoPage kind="safety"/>}/><Route path="/privacy" element={<InfoPage kind="privacy"/>}/><Route path="/terms" element={<InfoPage kind="terms"/>}/><Route path="/dashboard" element={<RoleRedirect/>}/>
<Route path="/tenant/dashboard" element={<ProtectedPage role="tenant"><TenantDashboard/></ProtectedPage>}/><Route path="/tenant/favorites" element={<ProtectedPage role="tenant"><Favorites/></ProtectedPage>}/><Route path="/tenant/compare" element={<ProtectedPage role="tenant"><Compare/></ProtectedPage>}/><Route path="/tenant/journey" element={<ProtectedPage role="tenant"><RentalJourney/></ProtectedPage>}/><Route path="/tenant/saved-searches" element={<ProtectedPage role="tenant"><SavedSearches/></ProtectedPage>}/><Route path="/tenant/reviews" element={<ProtectedPage role="tenant"><MyReviews/></ProtectedPage>}/><Route path="/tenant/profile" element={<ProtectedPage role="tenant"><Profile/></ProtectedPage>}/><Route path="/tenant/security" element={<ProtectedPage role="tenant"><Security/></ProtectedPage>}/><Route path="/tenant/messages" element={<ProtectedPage role="tenant"><Messages/></ProtectedPage>}/><Route path="/tenant/notifications" element={<ProtectedPage role="tenant"><Notifications/></ProtectedPage>}/>
<Route path="/owner/dashboard" element={<ProtectedPage role="owner"><OwnerDashboard/></ProtectedPage>}/><Route path="/owner/analytics" element={<ProtectedPage role="owner"><OwnerAnalytics/></ProtectedPage>}/><Route path="/owner/listings" element={<ProtectedPage role="owner"><OwnerListings/></ProtectedPage>}/><Route path="/owner/listings/:id/edit" element={<ProtectedPage role="owner"><OwnerWizard/></ProtectedPage>}/><Route path="/owner/listings/:id/history" element={<ProtectedPage role="owner"><ListingHistory/></ProtectedPage>}/><Route path="/owner/create" element={<ProtectedPage role="owner"><OwnerWizard/></ProtectedPage>}/><Route path="/owner/journey" element={<ProtectedPage role="owner"><RentalJourney/></ProtectedPage>}/><Route path="/owner/reviews" element={<ProtectedPage role="owner"><OwnerReviews/></ProtectedPage>}/><Route path="/owner/profile" element={<ProtectedPage role="owner"><Profile/></ProtectedPage>}/><Route path="/owner/security" element={<ProtectedPage role="owner"><Security/></ProtectedPage>}/><Route path="/owner/messages" element={<ProtectedPage role="owner"><Messages/></ProtectedPage>}/><Route path="/owner/notifications" element={<ProtectedPage role="owner"><Notifications/></ProtectedPage>}/>
<Route path="/admin/dashboard" element={<ProtectedPage role="admin"><AdminDashboard/></ProtectedPage>}/><Route path="/admin/system" element={<ProtectedPage role="admin"><AdminSystem/></ProtectedPage>}/><Route path="/admin/search" element={<ProtectedPage role="admin"><AdminSearch/></ProtectedPage>}/><Route path="/admin/listings" element={<ProtectedPage role="admin"><AdminListings/></ProtectedPage>}/><Route path="/admin/owners" element={<ProtectedPage role="admin"><AdminOwners/></ProtectedPage>}/><Route path="/admin/trust" element={<ProtectedPage role="admin"><AdminTrust/></ProtectedPage>}/><Route path="/admin/users" element={<ProtectedPage role="admin"><AdminUsers/></ProtectedPage>}/><Route path="/admin/reviews" element={<ProtectedPage role="admin"><AdminReviews/></ProtectedPage>}/><Route path="/admin/notifications" element={<ProtectedPage role="admin"><AdminNotifications/></ProtectedPage>}/><Route path="/admin/ai" element={<ProtectedPage role="admin"><AdminAi/></ProtectedPage>}/><Route path="/admin/audit" element={<ProtectedPage role="admin"><AdminAudit/></ProtectedPage>}/><Route path="*" element={<NotFound/>}/>
</Routes></Suspense></>}
