import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes, useNavigate } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { RouteScrollManager } from '../App';

function NavigationFixture() {
  const navigate = useNavigate();
  return <><RouteScrollManager /><button onClick={() => navigate('/listing/52')}>Open related listing</button><Routes><Route path="/listing/:id" element={<div>Listing page</div>} /></Routes></>;
}

describe('RouteScrollManager', () => {
  beforeEach(() => vi.restoreAllMocks());

  it('returns to the top when a related listing changes the pathname', () => {
    const scrollTo = vi.spyOn(window, 'scrollTo').mockImplementation(() => undefined);
    render(<MemoryRouter initialEntries={['/listing/49']}><NavigationFixture /></MemoryRouter>);
    scrollTo.mockClear();

    fireEvent.click(screen.getByRole('button', { name: 'Open related listing' }));

    expect(scrollTo).toHaveBeenCalledWith({ top: 0, left: 0, behavior: 'auto' });
  });
});
