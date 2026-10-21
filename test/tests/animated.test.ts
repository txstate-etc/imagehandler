import { expect, test } from '@playwright/test'

// walks the GIF block structure to get the canvas size and count the frames
function parseGif (buf: Buffer) {
  const skipSubBlocks = (pos: number) => {
    while (buf[pos] !== 0) pos += buf[pos] + 1
    return pos + 1
  }
  const width = buf.readUInt16LE(6)
  const height = buf.readUInt16LE(8)
  let pos = 13
  if (buf[10] & 0x80) pos += 3 * 2 ** ((buf[10] & 0x07) + 1)
  let frames = 0
  while (pos < buf.length && buf[pos] !== 0x3B) {
    if (buf[pos] === 0x21) {
      pos = skipSubBlocks(pos + 2)
    } else if (buf[pos] === 0x2C) {
      frames++
      const packed = buf[pos + 9]
      pos += 10
      if (packed & 0x80) pos += 3 * 2 ** ((packed & 0x07) + 1)
      pos = skipSubBlocks(pos + 1)
    } else {
      throw new Error(`unexpected GIF block 0x${buf[pos].toString(16)} at ${pos}`)
    }
  }
  return { width, height, frames }
}

test('should resize an animated gif and keep every frame', async ({ request }) => {
  const resp = await request.get('http://imagehandler/imagehandler/scaler/origin/animated.gif?width=100')
  expect(resp.status()).toEqual(200)
  expect(resp.headers()['content-type']).toMatch(/^image\/gif/)
  const body = await resp.body()
  expect(body.subarray(0, 6).toString()).toEqual('GIF89a')
  expect(parseGif(body)).toEqual({ width: 100, height: 75, frames: 4 })
})
