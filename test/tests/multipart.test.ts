import { expect, test } from '@playwright/test'

const url = 'http://imagehandler/imagehandler/scaler/origin/animated.gif?width=100'

test('should refuse multipart/form-data requests', async ({ request }) => {
  const resp = await request.post(url, { multipart: { file: { name: 'a.txt', mimeType: 'text/plain', buffer: Buffer.from('hello') } } })
  expect(resp.status()).toEqual(403)
})

test('should refuse multipart/form-data regardless of case', async ({ request }) => {
  const resp = await request.post(url, { headers: { 'Content-Type': 'MULTIPART/Form-Data; boundary=x' }, data: '--x--' })
  expect(resp.status()).toEqual(403)
})

test('should still accept other POST bodies', async ({ request }) => {
  const resp = await request.post(url, { form: { a: 'b' } })
  expect(resp.status()).toEqual(200)
})
