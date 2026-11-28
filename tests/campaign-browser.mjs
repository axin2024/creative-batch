import assert from 'node:assert/strict';
import { join } from 'node:path';
import { chromium } from 'playwright-core';

const env = process.env;
const browser = await chromium.launch({ executablePath: env.CREATIVE_CHROMIUM || undefined, headless: true });
async function enter(page, token) {
  await page.goto(env.CREATIVE_TEST_WEB_ORIGIN);
  await page.getByLabel('工作室凭据').fill(token);
  await page.getByRole('button', { name: '登录' }).click();
  await page.getByRole('button', { name: '地区活动', exact: true }).click();
}
try {
  const page = await browser.newPage({ timezoneId: 'UTC' });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await enter(page, env.CREATIVE_BROWSER_TOKEN);
  await page.getByRole('button', { name: 'Kite Coffee · regional launch', exact: true }).click();
  await page.getByRole('button', { name: /AE · ar · square · v[23]/ }).first().click();
  const image = page.getByRole('img', { name: 'AE ar square 地区投放预览' });
  await image.waitFor();
  await page.waitForFunction(() => [...document.images].some(image => image.alt.includes('AE ar') && image.complete && image.naturalWidth > 0));
  await page.getByLabel('地区代码').fill('AE');
  await page.getByLabel('语言标签').fill('ar');
  await page.getByRole('button', { name: '读取地区版本', exact: true }).click();
  await page.waitForFunction(() => [...document.querySelectorAll('textarea')].some(node => node.value.includes('قهوة كايت')));
  assert.equal(await page.getByRole('button', { name: '提交渠道投放', exact: true }).count(), 0);
  await page.getByRole('button', { name: 'US · fr · square · withdrawn', exact: true }).click();
  await page.waitForFunction(() => [...document.querySelectorAll('pre')].some(node => node.textContent.includes('"remote_receipt"')));
  await page.getByLabel('撤回编号').fill(env.CREATIVE_BROWSER_RECALL);
  await page.getByRole('button', { name: '读取撤回进度', exact: true }).click();
  await page.waitForFunction(id => [...document.querySelectorAll('pre')].some(node => node.textContent.includes(id) &&
    node.textContent.includes('"open_receipts": []')), env.CREATIVE_BROWSER_RECALL);
  assert.equal(await page.getByRole('button', { name: '保存地区许可', exact: true }).count(), 0);
  await enter(page, env.CREATIVE_BROWSER_ADMIN);
  await page.getByLabel('字体文件').setInputFiles(join(env.CREATIVE_TEST_FONTS, 'noto-sans.ttf'));
  await page.getByLabel('字体名称').fill('Noto sans');
  await page.getByLabel('字体许可证').fill('OFL-1.1');
  await page.getByLabel('许可证地址').fill('Pinned Google Fonts version');
  const fontResult = page.locator('article').filter({ has: page.getByRole('heading', { name: '字体登记', exact: true }) }).locator('pre');
  const fontSaved = page.waitForResponse(response => response.url().endsWith('/api/campaign-fonts') && response.request().method() === 'POST');
  await page.getByRole('button', { name: '登记字体', exact: true }).click();
  const font = await fontSaved;
  assert.equal(font.ok(), true);
  await fontResult.filter({ hasText: env.CREATIVE_BROWSER_FONT }).waitFor();
  await page.getByLabel('许可编号', { exact: true }).fill('browser-font-ca');
  await page.getByLabel('素材类型（image 或 font）').fill('font');
  await page.getByLabel('许可素材摘要').fill(env.CREATIVE_BROWSER_FONT);
  await page.getByLabel('授权地区（逗号分隔）').fill('ca, US');
  await page.getByLabel('授权渠道（逗号分隔）').fill('square，portrait');
  const now = Date.now();
  await page.getByLabel('许可生效时间').fill(new Date(now - 60_000).toISOString().slice(0, 16));
  await page.getByLabel('许可到期时间').fill(new Date(now + 3_600_000).toISOString().slice(0, 16));
  await page.getByLabel('许可方').fill('Kite demo');
  await page.getByLabel('许可证明').fill('OFL-1.1 rehearsal');
  await page.getByLabel('允许商业使用').check();
  const grantResult = page.locator('article').filter({ has: page.getByRole('heading', { name: '地区许可登记', exact: true }) }).locator('pre');
  const grantSaved = page.waitForResponse(response => response.url().endsWith('/api/campaign-grants/browser-font-ca') && response.request().method() === 'POST');
  await page.getByRole('button', { name: '保存地区许可', exact: true }).click();
  const grant = await grantSaved;
  assert.equal(grant.ok(), true);
  await grantResult.filter({ hasText: 'browser-font-ca' }).waitFor();
  const { revision, snapshot } = JSON.parse(await grantResult.textContent());
  assert.equal(revision, 1);
  assert.deepEqual(snapshot.territories, ['CA', 'US']);
  assert.deepEqual(snapshot.channels, ['portrait', 'square']);
  assert.equal(snapshot.commercial, true);
  await enter(page, env.CREATIVE_BROWSER_FOREIGN);
  await page.getByRole('heading', { name: '活动工作区' }).waitFor();
  assert.equal(await page.getByRole('button', { name: 'Kite Coffee · regional launch', exact: true }).count(), 0);
  assert.deepEqual(errors, []);
  process.stdout.write(JSON.stringify({ chromium: browser.version(), checks: ['rtl-image-visible', 'localized-copy-readable',
    'reviewer-cannot-publish-or-register-grants', 'channel-withdrawal-receipt', 'recall-complete', 'font-upload', 'region-grant-saved',
    'cross-tenant-list-isolated'] }));
} finally {
  await browser.close();
}
