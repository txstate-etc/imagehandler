import { expect, test } from '@playwright/test'

// a query string no other test uses, since the resize cache is keyed on the full request url
const url = 'http://imagehandler/imagehandler/scaler/origin/Meadows_Center_research_bg.jpg?width=150'

test('should answer a repeat request with 304 when the etag matches', async ({ request }) => {
  const first = await request.get(url)
  expect(first.status()).toEqual(200)
  const etag = first.headers().etag
  expect(etag).toBeTruthy()

  const repeat = await request.get(url, { headers: { 'If-None-Match': etag } })
  expect(repeat.status()).toEqual(304)
  expect(repeat.headers().etag).toEqual(etag)

  const stale = await request.get(url, { headers: { 'If-None-Match': 'stale' } })
  expect(stale.status()).toEqual(200)
  expect(stale.headers().etag).toEqual(etag)
  expect(await stale.body()).toEqual(await first.body())
})
