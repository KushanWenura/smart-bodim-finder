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
  await expect(page.getByRole('heading', { name: 'Find a place that fits' })).toBeVisible();

  const firstListing = page.locator('a[href^="/listing/"]').first();
  await expect(firstListing).toBeVisible();
  await firstListing.click();
  await expect(page).toHaveURL(/\/listing\/\d+$/);
  await expect(page.getByRole('heading', { name: 'Approximate location' })).toBeVisible();
  await expect(page.locator('body')).not.toContainText(/exact street address/i);
});

test('tenant reaches protected shortlist, messages and saved-search workflows', async ({ page }) => {
  await login(page, 'tenant');
  await expect(page.getByRole('heading', { name: /Ayubowan/i })).toBeVisible();

  await page.getByRole('link', { name: 'Favorites' }).click();
  await expect(page.getByRole('heading', { name: 'Saved places', exact: true })).toBeVisible();

  await page.getByRole('link', { name: 'Messages' }).click();
  await expect(page.getByRole('heading', { name: 'Messages' })).toBeVisible();

  await page.getByRole('link', { name: 'Saved searches' }).click();
  await expect(page.getByRole('heading', { name: 'Saved searches' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'New saved search' })).toBeEnabled();
});

test('owner reaches protected portfolio and complete listing wizard', async ({ page }) => {
  await login(page, 'owner');
  await expect(page.getByRole('heading', { name: 'Your properties at a glance' })).toBeVisible();

  await page.getByRole('link', { name: 'My listings' }).click();
  await expect(page.getByRole('heading', { name: 'My listings' })).toBeVisible();
  await expect(page.getByRole('table')).toBeVisible();

  await page.getByRole('link', { name: 'Add property' }).click();
  await expect(page.getByRole('heading', { name: 'Create a property listing' })).toBeVisible();
  await expect(page.getByRole('navigation', { name: 'Listing creation progress' })).toBeVisible();
  await expect(page.getByLabel(/Listing title/i)).toBeVisible();
});

test('admin reaches moderation, AI health and privacy-safe global search', async ({ page }) => {
  await login(page, 'admin');
  await expect(page.getByRole('heading', { name: 'Administrator overview' })).toBeVisible();

  await page.getByRole('link', { name: 'AI status' }).click();
  await expect(page.getByRole('heading', { name: 'AI health & index' })).toBeVisible();
  await expect(page.getByText('Models ready')).toBeVisible();

  await page.getByRole('link', { name: 'Global search' }).click();
  await page.getByLabel('Search users, listings, reviews and conversation metadata').fill('Availability');
  await page.getByRole('button', { name: 'Search', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Conversation metadata' })).toBeVisible();
  await expect(page.getByText(/private message bodies are excluded/i)).toBeVisible();
});
