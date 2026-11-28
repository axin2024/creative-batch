import { createHash, timingSafeEqual } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import * as fontkit from 'fontkit';
import { chromium } from 'playwright-core';

const digest = (bytes) => createHash('sha256').update(bytes).digest('hex');
const release = digest(await readFile(new URL(import.meta.url)));
const token = process.env.CREATIVE_LAYOUT_TOKEN;
const port = Number(process.env.CREATIVE_LAYOUT_PORT ?? 8126);
if (!token || token.length < 16 || !Number.isInteger(port) || port < 1024 || port > 65535) {
  throw new Error('Set CREATIVE_LAYOUT_TOKEN (at least 16 characters) and an available loopback port.');
}

const browser = await chromium.launch({
  executablePath: process.env.CREATIVE_CHROMIUM || undefined,
  headless: true,
});
const renderer = { chromium: browser.version(), code_sha256: release };
const cache = new Map();
const queue = [];
let active = 0;

function compose(input) {
  const canvas = document.createElement('canvas');
  canvas.width = input.width;
  canvas.height = input.height;
  const context = canvas.getContext('2d', { willReadFrequently: true });
  const box = ([x, y, w, h]) => [x * input.width / 10000, y * input.height / 10000, w * input.width / 10000, h * input.height / 10000];
  const overlaps = ([x, y, w, h], [a, b, c, d]) => x < a + c && x + w > a && y < b + d && y + h > b;
  const picture = async (encoded) => {
    const image = new Image();
    image.src = 'data:image/png;base64,' + encoded;
    await image.decode();
    return image;
  };

  return (async () => {
    const face = await new FontFace('Campaign', `url(data:font/ttf;base64,${input.font})`).load();
    document.fonts.add(face);
    const [product, logo, background] = await Promise.all([
      picture(input.product.png), picture(input.logo.png), input.background ? picture(input.background) : null,
    ]);
    context.fillStyle = input.background_color;
    context.fillRect(0, 0, input.width, input.height);
    if (background) {
      const scale = Math.max(input.width / background.width, input.height / background.height);
      const w = background.width * scale, h = background.height * scale;
      context.drawImage(background, (input.width - w) / 2, (input.height - h) / 2, w, h);
    }
    const drawLayer = (image, sourceBox) => {
      const [x, y, w, h] = box(sourceBox);
      const factor = Math.min(w / image.width, h / image.height);
      const width = image.width * factor, height = image.height * factor;
      context.drawImage(image, x + (w - width) / 2, y + (h - height) / 2, width, height);
    };
    drawLayer(product, input.product.rect);
    drawLayer(logo, input.logo.rect);
    const productBox = box(input.product.rect);
    const logoBox = box(input.logo.rect);
    const flags = [];
    if (overlaps(productBox, logoBox)) flags.push({ code: 'locked-layer-overlap', layer: 'logo' });
    const [left, top, width, height] = box(input.text_area);
    const rtl = input.direction === 'rtl';
    context.direction = input.direction;
    context.textAlign = rtl ? 'right' : 'left';
    context.textBaseline = 'alphabetic';
    context.fillStyle = input.ink;
    const x = rtl ? left + width : left;
    let y = top;
    const lines = [];
    const segmenter = new Intl.Segmenter(input.locale, { granularity: 'word' });
    for (const segment of input.segments) {
      const increment = { headline: 0.060, body: 0.038, disclaimer: 0.028 }[segment.kind];
      const size = Math.max(12, Math.round(Math.min(input.width, input.height) * increment));
      context.font = `${size}px Campaign`;
      const lineHeight = Math.ceil(size * 1.5);
      const words = [];
      for (const line of segment.text.split(/\r?\n/)) {
        let draft = '';
        for (const { segment: part } of segmenter.segment(line)) {
          if (draft.trim() && context.measureText((draft + part).trimEnd()).width > width) {
            words.push(draft.trim());
            draft = part.trimStart();
          } else {
            draft += part;
          }
        }
        words.push(draft.trim());
      }
      for (const text of words) {
        const baseline = y + size;
        const metrics = context.measureText(text);
        const bounds = [x - metrics.actualBoundingBoxLeft, baseline - metrics.actualBoundingBoxAscent,
          metrics.actualBoundingBoxLeft + metrics.actualBoundingBoxRight, metrics.actualBoundingBoxAscent + metrics.actualBoundingBoxDescent];
        const [bx, by, bw, bh] = bounds;
        const overflow = text && (metrics.width > width || bx < left - 1 || bx + bw > left + width + 1 || by < top || by + bh > top + height);
        const obstructed = text && (overlaps(bounds, productBox) || overlaps(bounds, logoBox));
        if (overflow) flags.push({ segment: segment.id, code: 'safe-area-overflow', bounds });
        if (obstructed) flags.push({ segment: segment.id, code: 'protected-layer-overlap', bounds });
        if (!overflow && !obstructed) context.fillText(text, x, baseline);
        lines.push({ segment: segment.id, width: metrics.width, bounds });
        y += lineHeight;
      }
      y += Math.ceil(size * 0.32);
    }
    return {
      png_base64: canvas.toDataURL('image/png').slice('data:image/png;base64,'.length),
      direction: input.direction, alignment: rtl ? 'right' : 'left', product_box: productBox, logo_box: logoBox,
      product_transform: 'contain', flags, lines,
    };
  })();
}

async function layout(input) {
  if (!Number.isInteger(input.width) || !Number.isInteger(input.height) ||
      Math.min(input.width, input.height) < 256 || Math.max(input.width, input.height) > 2048) throw new Error('Invalid dimensions');
  const font = fontkit.create(Buffer.from(input.font, 'base64'));
  const missing = input.segments.flatMap(({ id, text }) => {
    const points = [...new Set(Array.from(text).filter((s) => !/[\p{Z}\p{C}]/u.test(s)))];
    const glyphs = points.filter((s) => !font.hasGlyphForCodePoint(s.codePointAt(0)));
    const controls = Array.from(text).filter((s) => /[\u202a-\u202e\u2066-\u2069]/u.test(s));
    const flags = [];
    if (glyphs.length) flags.push({ segment: id, code: 'missing-glyph', codepoints: glyphs.map((s) => 'U+' + s.codePointAt(0).toString(16).toUpperCase()) });
    if (controls.length) flags.push({ segment: id, code: 'bidi-control' });
    return flags;
  });
  const page = await browser.newPage({ offline: true });
  try {
    const result = await page.evaluate(compose, input);
    return { ...result, flags: [...missing, ...result.flags], renderer,
      product_sha256: digest(Buffer.from(input.product.png, 'base64')), logo_sha256: digest(Buffer.from(input.logo.png, 'base64')) };
  } finally {
    await page.close();
  }
}

function drain() {
  while (active < 2 && queue.length) {
    const { input, resolve, reject } = queue.shift();
    active++;
    layout(input).then(resolve, reject).finally(() => { active--; drain(); });
  }
}

const server = createServer({ requestTimeout: 30000, headersTimeout: 5000 }, async (request, response) => {
  const send = (status, body) => {
    response.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
    response.end(JSON.stringify(body));
  };
  if (request.method !== 'POST' || request.url !== '/render') return send(404, { error: 'not-found' });
  const incoming = Buffer.from(request.headers.authorization ?? '');
  const expected = Buffer.from('Bearer ' + token);
  if (incoming.length !== expected.length || !timingSafeEqual(incoming, expected)) return send(401, { error: 'unauthorized' });
  try {
    const pieces = [];
    let size = 0;
    for await (const piece of request) {
      size += piece.length;
      if (size > 32 * 1024 * 1024) return send(413, { error: 'render-input-too-large' });
      pieces.push(piece);
    }
    const raw = Buffer.concat(pieces);
    const key = digest(raw);
    if (cache.has(key)) return send(200, await cache.get(key));
    if (queue.length >= 8) return send(503, { error: 'layout-busy' });
    const input = JSON.parse(raw.toString('utf8'));
    const job = new Promise((resolve, reject) => queue.push({ input, resolve, reject }));
    cache.set(key, job);
    if (cache.size > 12) cache.delete(cache.keys().next().value);
    drain();
    try {
      send(200, await job);
    } catch (error) {
      cache.delete(key);
      throw error;
    }
  } catch {
    send(422, { error: 'render-input-invalid' });
  }
});
server.listen(port, '127.0.0.1', () => process.stdout.write(`Layout renderer listening on 127.0.0.1:${port}\n`));
for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, () => { server.close(); browser.close().finally(() => process.exit(0)); });
}
