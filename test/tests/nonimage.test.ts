import { expect, test } from '@playwright/test'

test('should refuse to process a source that is not an image', async ({ request }) => {
  const resp = await request.get('http://imagehandler/imagehandler/scaler/origin/not-an-image.html?width=100')
  expect(resp.status()).toEqual(400)
})

test('should refuse a source that passes the mime check but cannot be decoded', async ({ request }) => {
  const resp = await request.get('http://imagehandler/imagehandler/scaler/origin/not-an-image.mvg?width=100')
  expect(resp.status()).toEqual(400)
})
