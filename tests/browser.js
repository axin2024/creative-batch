async page => {
  const fixture = /* STUDIO_FIXTURE */ {};
  const assert = (condition, message) => { if (!condition) throw new Error(message); };
  const visit = async role => {
    await page.reload();
    await page.getByLabel('工作室凭据').fill(fixture.grants[role]);
    await page.getByRole('button', { name: '登录' }).click();
    await page.getByRole('heading', { name: '生产批次' }).waitFor();
  };
  const api = (role, path) => page.request.get(new URL('/api/' + path, page.url()).href, {
    headers: { Authorization: 'Bearer ' + fixture.grants[role] },
  });
  const openBatch = () => page.getByRole('button', { name: /browser-recovery/ }).click();
  const responseTo = (path, method) => page.waitForResponse(response =>
    new URL(response.url()).pathname === '/api/' + path && response.request().method() === method);

  await visit('channel');
  assert(await page.getByRole('button', { name: /browser-recovery|private-draft/ }).count() === 0, 'Channel saw unpublished batches.');
  assert((await api('channel', `batches/${fixture.draft}/bundles`)).status() === 404, 'Channel could access a draft delivery route.');

  await visit('operator');
  const first = await (await api('operator', 'batches?limit=1')).json();
  const second = await (await api('operator', 'batches?limit=1&before=' + first.next_cursor)).json();
  assert(first.items[0].id === fixture.draft && second.items[0].id === fixture.batch && second.next_cursor === null,
    'Cursor pagination lost a batch or returned a duplicate.');
  assert((await api('foreign', 'batches?limit=1&before=' + first.next_cursor)).status() === 404, 'Another tenant can use the private cursor.');
  await openBatch();
  await page.getByText('1 项生产任务', { exact: true }).click();
  const original = responseTo(`tasks/${fixture.task}/resolve`, 'POST');
  await page.getByRole('button', { name: '读取原回执' }).click();
  const settled = await (await original).json();
  assert(settled.id === fixture.task && settled.attempts === 1 && settled.phase === 'completed', 'The original receipt was not recovered.');
  await page.getByText(/可比对的商品像素太少/).waitFor();
  assert(await page.getByRole('button', { name: '选用此稿' }).count() === 0, 'Operator could select a pending candidate.');

  await visit('reviewer');
  await openBatch();
  await page.getByText(/可比对的商品像素太少/).waitFor();
  const images = page.locator('.comparison img');
  await images.first().scrollIntoViewIfNeeded();
  await page.waitForFunction(() => [...document.querySelectorAll('.comparison img')].filter(image => image.naturalWidth > 0).length === 2);
  const source = await api('reviewer', `candidates/${fixture.task}/source`);
  const candidate = await api('reviewer', `candidates/${fixture.task}/image`);
  assert(source.ok() && candidate.ok() && source.headers().etag !== candidate.headers().etag, 'Comparison did not expose two distinct images.');
  for (const label of ['色彩', '包装', '外形', '背景', '标识']) await page.getByLabel(label, { exact: true }).check();
  await page.getByLabel('审核理由').fill('已对照原图，确认稀少采样来自测试素材。');
  const review = responseTo(`candidates/${fixture.task}/review`, 'POST');
  await page.getByRole('button', { name: '通过', exact: true }).click();
  assert((await review).ok(), 'Reviewer could not submit results.');
  assert(await page.getByRole('button', { name: '选用此稿' }).count() === 0, 'Reviewer has an operator control.');

  await visit('operator');
  await openBatch();
  await page.getByRole('button', { name: '选用此稿' }).click();
  await page.getByLabel('选用理由').fill('复核后选择此稿用于渠道交付。');
  await page.getByRole('button', { name: '确认选用' }).click();
  await page.getByRole('heading', { name: '已选用' }).waitFor();
  await page.getByRole('button', { name: '核对交付清单' }).click();
  await page.getByText('全部输入已通过检查').waitFor();
  const publication = responseTo(`batches/${fixture.batch}/publish`, 'POST');
  await page.getByRole('button', { name: '冻结交付包' }).click();
  const bundle = await (await publication).json();
  assert(bundle.revision === 1 && bundle.manifest.version === 2, 'Could not publish the reviewed selection.');
  await page.getByRole('button', { name: '下载 R1' }).waitFor();
  await page.getByRole('button', { name: '生产统计' }).click();
  await page.getByRole('heading', { name: '生产统计' }).waitFor();
  await page.getByText(/当前采用 1/).waitFor();

  await visit('channel');
  assert(await page.getByRole('button', { name: /private-draft/ }).count() === 0, 'Channel saw the private draft after publication.');
  await openBatch();
  const zip = responseTo(`bundles/${bundle.id}/download`, 'GET');
  await page.getByRole('button', { name: '下载 R1' }).click();
  const archive = await zip;
  const headers = archive.headers();
  assert(archive.ok() && headers['content-type'] === 'application/zip' && headers['x-creative-receipt'] &&
    headers['x-creative-manifest'] === bundle.digest && (await archive.body()).length > 1000, 'Delivery did not return the frozen manifest and receipt.');
  await page.getByRole('status').getByText(/下载回执/).waitFor();
  assert((await api('foreign', `bundles/${bundle.id}/download`)).status() === 404, 'Another tenant could read a delivery.');

  await visit('admin');
  await page.getByRole('button', { name: '素材与授权' }).click();
  const rights = await (await api('admin', 'rights')).json();
  const sparse = rights.items.find(row => row.id === 'reference-sparse');
  const utc = value => value.replace(' ', 'T').split('.')[0] + 'Z';
  const newGrant = { id: 'browser-import', image_digest: sparse.image_digest, grantor: 'Browser acceptance',
    commercial: true, channels: sparse.scope, valid_from: utc(sparse.valid_from), valid_until: utc(sparse.valid_until) };
  await page.getByLabel('授权记录 JSON 数组').fill(JSON.stringify([newGrant, newGrant, { ...newGrant, commercial: false }]));
  const imported = responseTo('catalog/rights/import', 'POST');
  await page.getByRole('button', { name: '导入授权' }).click();
  const report = await (await imported).json();
  assert(report.results.map(row => row.status).join(',') === 'created,existing,rejected', 'Batch import did not isolate conflicting rows.');
  await page.getByText(/第 2 行 · browser-import · 已存在/).waitFor();
  await page.getByRole('heading', { name: 'browser-import', exact: true }).waitFor();
  const record = page.getByRole('article').filter({ has: page.getByRole('heading', { name: 'reference-sparse', exact: true }) }).last();
  await record.getByRole('button', { name: '撤销授权' }).click();
  await record.getByLabel('撤销原因').fill('验证已交付素材撤回后的下载门禁。');
  await record.getByRole('button', { name: '确认撤销' }).click();
  await record.getByText(bundle.id + ' · channel').waitFor();
  assert((await api('channel', `bundles/${bundle.id}/download`)).status() === 409, 'Revocation did not close the delivery after listing its recipient.');
  return { result: 'BROWSER_ACCEPTANCE_PASSED', roles: 4, sampledImages: 2, originalAttempts: settled.attempts,
    zip: true, foreignDownloadBlocked: true, importStatuses: report.results.map(row => row.status), revokedDeliveryBlocked: true };
}
