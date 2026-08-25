import { expect, test, type Page } from '@playwright/test';

const accounts = {
  tenant: ['tenant@smartbodim.lk', 'Tenant@123'],
  owner: ['owner@smartbodim.lk', 'Owner@123'],
  admin: ['admin@smartbodim.lk', 'Admin@123'],
} as const;

async function login(page: Page, role: keyof typeof accounts) {
  const [email, password] = accounts[role];
  await page.goto('/login');
  await page.getByLabel('Email address').fill(email);
  await page.getByLabel('Password', { exact: true }).fill(password);
  await page.getByRole('button', { name: 'Log in securely' }).click();
  await expect(page).toHaveURL(new RegExp(`/${role}/dashboard$`));
}

test('guest can search and open a public listing without a private address', async ({ page }) => {
  await page.goto('/search?city=Colombo&maxPrice=50000');
  await expect(page.getByRole('heading', { name: 'Search with control.' })).toBeVisible();

  const firstListing = page.locator('a[href^="/listing/"]').first();
  await expect(firstListing).toBeVisible();
  await firstListing.click();
  await expect(page).toHaveURL(/\/listing\/\d+$/);
  await expect(page.getByRole('heading', { name: 'See the neighbourhood, not just the room.' })).toBeVisible();
  await page.getByRole('button', { name: /Open interactive map/i }).click();
  await expect(page.getByRole('region', { name: /Map of .* and 5 nearby essential places/i })).toBeVisible();
  const nearbyFilters = page.getByRole('group', { name: 'Filter nearby places' });
  await expect(nearbyFilters.getByRole('button', { name: /Cargills & markets/i })).toBeVisible();
  await expect(nearbyFilters.getByRole('button', { name: /Bus stops/i })).toBeVisible();
  await expect(nearbyFilters.getByRole('button', { name: /Hospitals/i })).toBeVisible();
  await expect(page.locator('.leaflet-interactive')).toHaveCount(6);
  await expect(page.locator('body')).not.toContainText(/exact street address/i);
});

test('Buddy AI asks for an exact branch before ranking a multi-branch campus', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('button', { name: 'Open Buddy AI assistant' }).click();
  await page.getByPlaceholder(/Campus, budget, WiFi, AC, parking/i).fill('Find a room near ICBT Campus');
  await page.getByRole('button', { name: 'Send to Buddy AI' }).click();

  await expect(page.getByText(/has several branches in Sri Lanka/i)).toBeVisible();
  const branches = page.locator('[aria-label="Choose a destination branch"]');
  await expect(branches.getByRole('button')).toHaveCount(10);
  await branches.getByRole('button', { name: 'ICBT Campus - Kandy' }).click();

  await expect(page.getByText(/exact eligible matches near ICBT Campus - Kandy/i)).toBeVisible();
  await expect(page.locator('.sb-ai-results > a').first()).toBeVisible();
});

test('Buddy AI applies strict requirements and explains the ranked best matches', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('button', { name: 'Open Buddy AI assistant' }).click();
  await page.getByPlaceholder(/Campus, budget, WiFi, AC, parking/i).fill('Near University of Moratuwa Katubedda with WiFi, AC and car park under Rs. 35,000');
  await page.getByRole('button', { name: 'Send to Buddy AI' }).click();

  await expect(page.getByText(/exact eligible matches near University of Moratuwa - Katubedda/i)).toBeVisible();
  const requirements = page.getByLabel('Applied requirements');
  await expect(requirements.getByText('Air conditioning', { exact: true })).toBeVisible();
  await expect(requirements.getByText('Parking', { exact: true })).toBeVisible();
  await expect(page.getByText(/#1 Best match/i).first()).toBeVisible();
  await expect(page.getByText(/% fit/i).first()).toBeVisible();
  await expect(page.getByText(/Includes all 3 requested facilities/i).first()).toBeVisible();
});

test('tenant reaches protected shortlist, messages and saved-search workflows', async ({ page }) => {
  await login(page, 'tenant');
  await expect(page.getByRole('heading', { name: /Welcome back/i })).toBeVisible();

  await page.getByRole('link', { name: /Favorites/i }).click();
  await expect(page.getByRole('heading', { name: 'Saved places', exact: true })).toBeVisible();

  await page.getByRole('link', { name: /Messages/i }).click();
  await expect(page.getByRole('heading', { name: 'Messages' })).toBeVisible();

  await page.getByRole('link', { name: /Search alerts/i }).click();
  await expect(page.getByRole('heading', { name: 'Saved searches' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'New saved search' })).toBeEnabled();
});

test('owner reaches protected portfolio and complete listing wizard', async ({ page }) => {
  await login(page, 'owner');
  await expect(page.getByRole('heading', { name: 'Manage your places with confidence.' })).toBeVisible();

  await page.getByRole('link', { name: /Listings/i }).click();
  await expect(page.getByRole('heading', { name: 'My listings' })).toBeVisible();
  await expect(page.getByRole('table')).toBeVisible();

  await page.getByRole('link', { name: /Add property/i }).click();
  await expect(page.getByRole('heading', { name: 'Create a property listing' })).toBeVisible();
  await expect(page.getByRole('navigation', { name: 'Listing creation progress' })).toBeVisible();
  await page.getByLabel(/Listing title/i).fill('End-to-end verified room');
  await page.getByLabel(/Complete description/i).fill(
    'A clean, secure and professionally managed room with reliable WiFi and convenient transport access.',
  );
  await page.getByLabel(/Monthly/i).fill('35000');
  await page.getByRole('button', { name: /Continue/i }).click();
  await expect(page.getByRole('heading', { name: 'Location, occupancy and rules' })).toBeVisible();
});

test('admin reaches moderation, AI health and privacy-safe global search', async ({ page }) => {
  await login(page, 'admin');
  await expect(page.getByRole('heading', { name: 'Everything important, in one view.' })).toBeVisible();

  await page.getByRole('link', { name: /AI & data/i }).click();
  await expect(page.getByRole('heading', { name: 'AI service health' })).toBeVisible();
  await expect(page.getByText('Models ready')).toBeVisible();

  await page.getByRole('link', { name: /Global search/i }).click();
  await page.getByLabel('Search users, listings, reviews and conversation metadata').fill('Availability');
  await page.getByRole('button', { name: 'Search', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Conversation metadata' })).toBeVisible();
  await expect(page.getByText(/private message bodies are excluded/i)).toBeVisible();
});
