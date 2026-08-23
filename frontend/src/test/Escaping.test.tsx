import {render,screen} from '@testing-library/react';
import {describe,expect,it} from 'vitest';

function PlainText({value}:{value:string}){return <p>{value}</p>}

describe('plain-text rendering',()=>{it('does not execute or inject stored XSS payloads',()=>{const payload='<script>window.compromised=true</script><img src=x onerror=alert(1)>';const{container}=render(<PlainText value={payload}/>);expect(screen.getByText(payload)).toBeInTheDocument();expect(container.querySelector('script')).toBeNull();expect(container.querySelector('img')).toBeNull();expect((window as unknown as {compromised?:boolean}).compromised).not.toBe(true)})});
