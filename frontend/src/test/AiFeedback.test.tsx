import{fireEvent,render,screen,waitFor}from'@testing-library/react';
import{MemoryRouter}from'react-router-dom';
import{beforeEach,describe,expect,it,vi}from'vitest';
import{AiChatbot}from'../components/Shell';

const mocks=vi.hoisted(()=>({post:vi.fn()}));
vi.mock('../api',()=>({api:{post:mocks.post}}));
vi.mock('../auth',()=>({useAuth:()=>({user:{id:8,role:'tenant',name:'Test Tenant'}})}));

describe('Buddy AI detailed feedback',()=>{
  beforeEach(()=>{
    mocks.post.mockReset();
    mocks.post.mockImplementation((url:string)=>url==='/assistant/chat'?Promise.resolve({data:{answer:'I found three eligible places.',results:[],requirements:['WiFi'],disclaimer:'Verify every place in person.',search:{searchLogId:42},understanding:{language:'en',confidence:{overall:.9}}}}):Promise.resolve({data:{recorded:true}}));
  });

  it('collects a structured improvement reason without copying the conversation',async()=>{
    render(<MemoryRouter><AiChatbot/></MemoryRouter>);
    fireEvent.click(screen.getByRole('button',{name:/open buddy ai assistant/i}));
    fireEvent.change(screen.getByPlaceholderText(/campus, budget, wifi, ac, parking/i),{target:{value:'Find a WiFi room near campus'}});
    fireEvent.click(screen.getByRole('button',{name:/send to buddy ai/i}));
    fireEvent.click(await screen.findByRole('button',{name:/this answer was not helpful/i}));
    expect(screen.getByText(/what should buddy improve/i)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button',{name:/a must-have was missed/i}));
    await waitFor(()=>expect(mocks.post).toHaveBeenCalledWith('/ai/feedback',expect.objectContaining({event:'not_helpful',searchLogId:42,issueCategory:'missing_facility'})));
    expect(await screen.findByText(/feedback saved—thank you/i)).toBeInTheDocument();
  });
});
