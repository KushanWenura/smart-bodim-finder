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
  await expect(page.getByRole('heading', { name: 'See what your daily life looks like here.' })).toBeVisible();
  await page.getByRole('button', { name: /Explore nearby with AI/i }).first().click();
  await expect(page.getByRole('region', { name: /Map of .* and 5 nearby essential places/i })).toBeVisible();
  const nearbyFilters = page.getByRole('group', { name: 'Filter nearby places' });
  await expect(nearbyFilters.getByRole('button', { name: /Cargills & markets/i })).toBeVisible();
  await expect(nearbyFilters.getByRole('button', { name: /Bus stops/i })).toBeVisible();
  await expect(nearbyFilters.getByRole('button', { name: /Hospitals/i })).toBeVisible();
  await expect(page.locator('.leaflet-interactive')).toHaveCount(6);
  await expect(page.locator('body')).not.toContainText(/exact street address/i);
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
