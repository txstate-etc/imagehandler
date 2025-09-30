import { expect, test } from '@playwright/test'

test('should be able to get a scaled image', async ({ page }) => {
  await page.goto('http://imagehandler/imagehandler/scaler/jsnider2.github.io/Meadows_Center_research_bg.jpg?width=200')
  await page.waitForLoadState('domcontentloaded')
  expect(await page.title()).toEqual('Meadows_Center_research_bg.jpg (200×118)')
})
