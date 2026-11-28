import assert from 'node:assert/strict';
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
  const page = await browser.newPage();
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
  await enter(page, env.CREATIVE_BROWSER_FOREIGN);
  await page.getByRole('heading', { name: '活动工作区' }).waitFor();
  assert.equal(await page.getByRole('button', { name: 'Kite Coffee · regional launch', exact: true }).count(), 0);
  assert.deepEqual(errors, []);
  process.stdout.write(JSON.stringify({ chromium: browser.version(), checks: ['rtl-image-visible', 'localized-copy-readable',
    'reviewer-cannot-publish', 'channel-withdrawal-receipt', 'recall-complete', 'cross-tenant-list-isolated'] }));
} finally {
  await browser.close();
}
