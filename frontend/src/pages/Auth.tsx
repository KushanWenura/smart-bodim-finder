import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { z } from 'zod';
import { api, csrf } from '../api';
import { roleHome, useAuth } from '../auth';
import { BuddyMark, Header } from '../components/Shell';

const loginSchema = z.object({ email: z.email(), password: z.string().min(8) });
type LoginData = z.infer<typeof loginSchema>;

export function Login() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const { register, handleSubmit, setError, formState: { errors, isSubmitting } } = useForm<LoginData>({ resolver: zodResolver(loginSchema) });
  const submit = async (data: LoginData) => { try { const user = await login(data.email, data.password); navigate(roleHome(user.role)); } catch (error) { setError('root', { message: (error as { message: string }).message }); } };
  return <><Header /><main className="auth-page"><div className="auth-art"><div className="auth-quote"><BuddyMark /><span className="eyebrow">Welcome back</span><h2>Your next chapter starts with the right place.</h2><p>One account for Buddy shortlists, saved places and direct owner conversations.</p></div></div><section className="auth-panel"><div className="auth-box"><span className="eyebrow">Secure sign in</span><h1>Good to see you.</h1><p>Sign in to continue to your BodimBuddy.lk workspace.</p><form className="form" onSubmit={handleSubmit(submit)}>{errors.root && <div className="form-error show">{errors.root.message}</div>}<div className="field"><label htmlFor="login-email">Email address</label><input id="login-email" className="input" type="email" autoComplete="email" placeholder="you@example.com" {...register('email')} />{errors.email && <small>{errors.email.message}</small>}</div><div className="field"><label htmlFor="login-password">Password</label><input id="login-password" className="input" type="password" autoComplete="current-password" placeholder="Enter your password" {...register('password')} />{errors.password && <small>{errors.password.message}</small>}</div><div className="actions-row"><Link className="text-link" to="/forgot-password">Forgot password?</Link><button className="btn btn-primary" disabled={isSubmitting}>Log in securely →</button></div></form><div className="auth-switch">New here? <Link className="text-link" to="/register">Create an account</Link></div></div></section></main></>;
}

const registerSchema = z.object({ name: z.string().min(2), email: z.email(), phone: z.string().regex(/^(?:\+94|0)7\d{8}$/), password: z.string().min(8).regex(/[A-Z]/).regex(/\d/), password_confirmation: z.string() }).refine(value => value.password === value.password_confirmation, { path: ['password_confirmation'], message: 'Passwords do not match' });
type RegisterData = z.infer<typeof registerSchema>;
const fields: Array<{ name: keyof RegisterData; label: string; type: string; autoComplete: string }> = [
  { name: 'name', label: 'Full name', type: 'text', autoComplete: 'name' },
  { name: 'email', label: 'Email', type: 'email', autoComplete: 'email' },
  { name: 'phone', label: 'Sri Lankan mobile', type: 'tel', autoComplete: 'tel' },
  { name: 'password', label: 'Password', type: 'password', autoComplete: 'new-password' },
  { name: 'password_confirmation', label: 'Confirm password', type: 'password', autoComplete: 'new-password' },
];

export function Register() {
  const [params] = useSearchParams();
  const role = params.get('role') === 'owner' ? 'owner' : 'tenant';
  const navigate = useNavigate();
  const { register, handleSubmit, setError, formState: { errors, isSubmitting } } = useForm<RegisterData>({ resolver: zodResolver(registerSchema) });
  const submit = async (data: RegisterData) => { try { await csrf(); await api.post('/auth/register', { ...data, role }); navigate('/login'); } catch (error) { setError('root', { message: (error as { message: string }).message }); } };
  return <><Header /><main className="auth-page"><div className="auth-art"><div className="auth-quote"><BuddyMark /><span className="eyebrow">Join the community</span><h2>{role === 'owner' ? 'Share a good place with the right people.' : 'Bring a Buddy to your next room search.'}</h2><p>Friendly discovery, clear requirements and safer direct conversations.</p></div></div><section className="auth-panel"><div className="auth-box"><span className="eyebrow">Create account</span><h1>Let’s get started.</h1><div className="view-switch"><Link className={`btn ${role === 'tenant' ? 'btn-primary' : 'btn-ghost'}`} to="/register?role=tenant">Tenant</Link><Link className={`btn ${role === 'owner' ? 'btn-primary' : 'btn-ghost'}`} to="/register?role=owner">Owner</Link></div><form className="form" onSubmit={handleSubmit(submit)}>{errors.root && <div className="form-error show">{errors.root.message}</div>}{fields.map(field => <div className="field" key={field.name}><label htmlFor={`register-${field.name}`}>{field.label}</label><input id={`register-${field.name}`} className="input" type={field.type} autoComplete={field.autoComplete} {...register(field.name)} /><small>{errors[field.name]?.message}</small></div>)}<button className="btn btn-primary" disabled={isSubmitting}>Create {role} account →</button></form></div></section></main></>;
}
