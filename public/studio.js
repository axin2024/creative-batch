'use strict';

const get = selector => document.querySelector(selector);
const checks = { color: '色彩', packaging: '包装', form: '外形', background: '背景', marks: '标识' };
const state = { token: '', role: '', id: '', batchCursor: null, images: new Set() };
let noticeTimer;
let viewVersion = 0;
let listVersion = 0;

function el(tag, text, className) {
  const node = document.createElement(tag);
  if (text !== undefined) node.textContent = text;
  if (className) node.className = className;
  return node;
}

function show(message, error = false) {
  const notice = get('#notice');
  notice.textContent = message;
  notice.toggleAttribute('data-error', error);
  clearTimeout(noticeTimer);
  noticeTimer = setTimeout(() => { notice.textContent = ''; }, 7000);
}

async function api(path, { method = 'GET', body, blob = false, headers: additional = {} } = {}) {
  const headers = { ...additional, Authorization: 'Bearer ' + state.token };
  const upload = body instanceof Blob;
  if (body !== undefined && !headers['Content-Type']) headers['Content-Type'] = upload ? 'image/png' : 'application/json';
  const res = await fetch('/api/' + path, { method, headers, body: upload || body === undefined ? body : JSON.stringify(body) });
  if (!res.ok) {
    const problem = await res.json();
    const error = new Error(problem.message ?? '请求失败');
    error.status = res.status;
    error.problem = problem;
    throw error;
  }
  if (blob) return { data: await res.blob(), receipt: res.headers.get('X-Creative-Receipt') };
  return res.json();
}

async function run(control, work) {
  control.disabled = true;
  try { await work(); }
  catch (error) { show(error.message, true); }
  finally { control.disabled = false; }
}

function action(label, fn, secondary = false) {
  const button = el('button', label, secondary ? 'secondary' : '');
  button.type = 'button';
  button.addEventListener('click', () => run(button, fn));
  return button;
}

function reasonAction(label, fieldLabel, submitLabel, submitReason, secondary = false) {
  const content = el('div');
  const form = el('form');
  form.hidden = true;
  const field = el('label', fieldLabel);
  const reason = el('textarea');
  reason.rows = 2;
  reason.required = true;
  reason.maxLength = 600;
  field.append(reason);
  const open = action(label, () => {
    form.hidden = !form.hidden;
    open.setAttribute('aria-expanded', String(!form.hidden));
    if (!form.hidden) reason.focus();
  }, secondary);
  open.setAttribute('aria-expanded', 'false');
  form.append(field, el('button', submitLabel));
  form.addEventListener('submit', event => {
    event.preventDefault();
    run(event.submitter, async () => {
      if (!reason.value.trim()) throw new Error('请填写操作理由');
      await submitReason(reason.value.trim());
    });
  });
  content.append(open, form);
  return content;
}

function can(role) { return state.role === 'admin' || state.role === role; }

async function list(append = false) {
  const version = ++listVersion;
  const before = append ? '&before=' + encodeURIComponent(state.batchCursor) : '';
  const page = await api('batches?limit=25' + before);
  if (listVersion !== version) return;
  const items = page.items.map(row => {
    const button = action(row.campaign, () => load(row.id), true);
    button.className = 'batch';
    button.dataset.batch = row.id;
    button.append(el('small', ' · ' + row.phase));
    return button;
  });
  if (append) get('#batches').append(...items);
  else get('#batches').replaceChildren(...items);
  state.batchCursor = page.next_cursor;
  get('#next-batches').hidden = state.batchCursor === null;
  markActive();
}

function markActive() {
  for (const button of document.querySelectorAll('[data-batch]')) {
    button.setAttribute('aria-current', String(state.id === button.dataset.batch));
  }
}

function clearImages() {
  for (const url of state.images) URL.revokeObjectURL(url);
  state.images.clear();
}

async function load(id) {
  const version = ++viewVersion;
  state.id = id;
  markActive();
  const target = get('#campaign');
  clearImages();
  target.replaceChildren(el('p', '正在读取批次…', 'empty'));
  try {
    if (state.role === 'channel') {
      const bundles = await api('batches/' + id + '/bundles');
      if (version === viewVersion) target.replaceChildren(bundlePanel(bundles));
      return;
    }
    const batch = await api('batches/' + id);
    if (version !== viewVersion) return;
    const header = el('div', undefined, 'panel');
    header.append(el('h1', batch.campaign), el('p', batch.phase + ' · ' + batch.provider + ' · ' + batch.planned_tasks + ' 份候选'));
    header.append(el('code', '输入 SHA-256 ' + batch.input_hash));
    if (can('operator')) {
      header.append(el('p', `计划费用 ${batch.estimated_cost_micros}，批次上限 ${batch.ceiling_micros}（微单位）`, 'muted'));
    }
    const actions = el('div', undefined, 'row');
    if (can('operator') && batch.phase === 'draft') {
      actions.append(action('按当前摘要提交', () => mutate('batches/' + id + '/submit', { input_hash: batch.input_hash })));
    }
    if (can('operator') && batch.phase !== 'draft') {
      actions.append(action('核对交付清单', async () => {
        const preview = await api('batches/' + id + '/preview');
        const rows = Object.entries(preview.blocked).map(([unit, code]) => unit + ' · ' + code);
        if (version !== viewVersion) return;
        status.replaceChildren(...(rows.length ? rows.map(row => el('li', row)) : [el('li', '全部输入已通过检查')]));
        publish.disabled = rows.length > 0;
      }, true));
      const publish = action('冻结交付包', async () => {
        const bundle = await api('batches/' + id + '/publish', { method: 'POST' });
        show('交付包已冻结 · R' + bundle.revision);
        await list();
        await load(id);
      });
      publish.disabled = true;
      actions.append(publish);
    }
    const status = el('ul');
    header.append(actions, status);
    const panels = [header];
    if (can('operator') && batch.phase === 'published') {
      const bundles = await api('batches/' + id + '/bundles');
      if (version !== viewVersion) return;
      panels.push(bundlePanel(bundles));
    }
    for (const [unit, fields] of Object.entries(batch.frozen.units)) {
      const group = el('article', undefined, 'group');
      const size = fields.width + ' × ' + fields.height;
      group.append(el('h2', fields.variant + ' / ' + fields.channel), el('p', size + ' · 素材授权 ' + fields.right_id, 'muted'));
      const usage = batch.unit_usage[unit];
      if (usage.completed || usage.failed || usage.unknown) {
        group.append(el('p', `已完成 ${usage.completed} · 待查 ${usage.unknown} · 失败 ${usage.failed} · 返工 ${usage.reworks}`, 'muted'));
        group.append(el('p', `待审 ${usage.pending} · 通过 ${usage.approved} · 拒绝 ${usage.rejected}`, 'muted'));
        if (usage.replay_outputs) group.append(el('p', `回放 ${usage.replay_outputs} 张（无生成费用）`, 'muted'));
        if (usage.live_outputs) {
          const reported = usage.reported_micros ?? '缺记录';
          group.append(el('p', `真实生成 ${usage.live_outputs} 张 · 预算 ${usage.live_estimated_micros} · 供应方已报 ${reported} · 缺报价回执 ${usage.unpriced_live_outputs} 张`, 'muted'));
        }
      }
      const jobs = batch.tasks.filter(task => task.unit_key === unit);
      if (jobs.length) group.append(taskPanel(jobs));
      const cards = el('div', undefined, 'candidates');
      const selection = batch.selections.find(item => item.unit_key === unit);
      const candidates = batch.candidates.filter(candidate => candidate.unit_key === unit);
      for (const candidate of candidates) cards.append(candidateCard(candidate, selection, version));
      group.append(cards);
      panels.push(group);
    }
    target.replaceChildren(...panels);
  } catch (error) {
    if (version === viewVersion) target.replaceChildren(el('p', error.message, 'panel failed'));
    throw error;
  }
}

function taskPanel(jobs) {
  const details = el('details');
  details.append(el('summary', jobs.length + ' 项生产任务'));
  const ordinal = Math.max(...jobs.map(task => task.ordinal));
  for (const task of jobs) {
    const item = el('div');
    const row = el('p', '#' + (task.ordinal + 1) + ' · ' + task.phase + ' · ' + task.attempts + ' 次');
    if (task.error_code) row.append(el('small', ' · ' + task.error_code));
    if (task.rework_reason) row.append(el('small', ' · 返工原因 ' + task.rework_reason));
    item.append(row);
    if (can('operator') && task.phase === 'unknown') {
      item.append(action('读取原回执', () => mutate('tasks/' + task.id + '/resolve', {}), true),
        reasonAction('关闭未确认任务', '关闭原因', '确认关闭',
          reason => mutate('tasks/' + task.id + '/resolve', { close_reason: reason }), true));
    } else if (can('operator') && ['completed', 'failed'].includes(task.phase)) {
      item.append(reasonAction('返工', '返工理由', '提交返工',
        reason => mutate('tasks/' + task.id + '/rework', { expected_ordinal: ordinal, reason }), true));
    }
    details.append(item);
  }
  return details;
}

function candidateCard(asset, selection, version) {
  const card = el('section', undefined, 'card');
  const approved = selection?.candidate === asset.id;
  card.append(el('h3', approved ? '已选用' : asset.verdict));
  if (asset.eligible && asset.quality.source_check === 'inconclusive') {
    card.append(el('p', '可比对的商品像素太少，须对照原图完成复核', 'muted'));
  } else {
    card.append(el('p', asset.eligible ? '机器检查通过' : '机器或商用授权检查未通过', asset.eligible ? 'status' : 'failed'));
  }
  if (!asset.quality.dimensions && asset.quality.expected_size) {
    const { width, height } = asset.quality.expected_size;
    card.append(el('p', `渠道要求 ${width} × ${height} 像素`, 'failed'));
  }
  if (asset.review_reason) card.append(el('p', asset.review_reason, 'muted'));
  const pair = el('div', undefined, 'comparison');
  for (const [label, file] of [['原始产品','source'],['待审图稿','image']]) {
    const figure = el('figure');
    const picture = el('img');
    picture.alt = label;
    figure.append(picture, el('figcaption', label));
    pair.append(figure);
    const observer = new IntersectionObserver(entries => {
      if (!entries.some(entry => entry.isIntersecting)) return;
      observer.disconnect();
      api('candidates/' + asset.id + '/' + file, { blob: true }).then(({ data }) => {
        if (version !== viewVersion || !picture.isConnected) return;
        const url = URL.createObjectURL(data);
        state.images.add(url);
        picture.src = url;
      }).catch(() => { figure.append(el('small', '图片读取失败')); });
    });
    observer.observe(picture);
  }
  card.append(pair);
  card.append(el('p', asset.evidence.provider.layout + ' · ' + asset.evidence.provider.finish + ' · ' + asset.evidence.elapsed_ms + ' ms', 'muted'));
  if (can('reviewer')) {
    const form = el('form');
    const box = el('div', undefined, 'checks');
    for (const [key, label] of Object.entries(checks)) {
      const choice = el('input');
      choice.type = 'checkbox';
      choice.name = key;
      const field = el('label', label);
      field.prepend(choice);
      box.append(field);
    }
    const reason = el('textarea');
    reason.rows = 2;
    reason.maxLength = 600;
    reason.setAttribute('aria-label', '审核理由');
    reason.placeholder = '审核理由';
    const review = verdict => {
      if (!reason.value.trim()) throw new Error('填写审核理由');
      const decisions = Object.fromEntries(Object.keys(checks).map(key => [key, form.elements[key].checked]));
      return mutate('candidates/' + asset.id + '/review', { expected_revision: asset.review_revision, verdict, reason: reason.value.trim(), checks: decisions });
    };
    const submit = action('通过', () => review('approved'));
    submit.disabled = !asset.eligible;
    form.append(box, reason, submit, action('拒绝', () => review('rejected'), true));
    form.addEventListener('submit', event => event.preventDefault());
    card.append(form);
  }
  if (can('operator') && !approved && asset.verdict === 'approved') {
    card.append(reasonAction('选用此稿', '选用理由', '确认选用',
      reason => mutate('candidates/' + asset.id + '/select', { expected_revision: selection?.revision ?? 0, reason })));
  }
  return card;
}

function bundlePanel(rows) {
  const panel = el('article', undefined, 'group');
  panel.append(el('h2', '已冻结交付包'));
  for (const bundle of rows) {
    const button = action('下载 R' + bundle.revision, async () => {
      const { data, receipt } = await api('bundles/' + bundle.id + '/download', { blob: true });
      const url = URL.createObjectURL(data);
      const anchor = el('a');
      anchor.href = url;
      anchor.download = 'creative-' + bundle.id + '.zip';
      document.body.append(anchor);
      anchor.click();
      anchor.remove();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
      show('下载回执 ' + receipt);
    });
    const row = el('p', undefined, 'row');
    row.append(button, el('code', bundle.digest));
    panel.append(row);
  }
  if (!rows.length) panel.append(el('p', '当前没有可下载的交付包。'));
  return panel;
}

async function mutate(path, body) {
  const id = state.id;
  await api(path, { method: 'POST', body });
  show('操作已记录');
  await list();
  if (state.id === id) await load(id);
}

function choices(select, rows) {
  select.replaceChildren(...rows.map(row => new Option(row.id + ' · R' + row.revision, JSON.stringify({ id: row.id, revision: row.revision }))));
}

async function showOperations() {
  const version = ++viewVersion;
  state.id = '';
  markActive();
  clearImages();
  const target = get('#campaign');
  target.replaceChildren(el('p', '正在读取生产统计…', 'empty'));
  const data = await api('operations');
  if (version !== viewVersion) return;
  const panel = el('article', undefined, 'panel');
  panel.append(el('h1', '生产统计'), el('p', '最近 30 天创建的批次 · 自 ' + data.since + ' UTC', 'muted'));
  for (const backend of data.backends) {
    const block = el('section', undefined, 'group');
    const ratio = backend.tasks ? Math.round(100 * backend.reworked / backend.tasks) : 0;
    block.append(el('h2', backend.provider), el('p', `任务 ${backend.tasks} · 完成 ${backend.completed} · 失败 ${backend.failed} · 返工率 ${ratio}%`),
      el('p', `候选 ${backend.candidates} · 人审通过 ${backend.approved} · 当前采用 ${backend.selected} · 已产出预算 ${backend.estimated_micros} 微单位`, 'muted'));
    panel.append(block);
  }
  if (!data.backends.length) panel.append(el('p', '当前区间没有生产任务。'));
  if (data.recent_rework.length) panel.append(el('h2', '最近返工'));
  for (const task of data.recent_rework) {
    const record = el('div', undefined, 'row');
    record.append(action(task.campaign + ' · ' + task.unit_key, () => load(task.batch), true), el('span', task.reason));
    panel.append(record);
  }
  target.replaceChildren(panel);
}

function assetForm(label, input, buttonLabel, save) {
  const form = el('form');
  const field = el('label', label);
  field.append(input);
  input.required = true;
  form.append(field, el('button', buttonLabel));
  form.addEventListener('submit', event => {
    event.preventDefault();
    run(event.submitter, () => save(input));
  });
  return form;
}

async function showAssets() {
  const version = ++viewVersion;
  state.id = '';
  markActive();
  clearImages();
  const panel = el('article', undefined, 'panel');
  panel.append(el('h1', '素材与授权'));
  const target = get('#campaign');
  target.replaceChildren(panel);
  const image = el('input');
  image.type = 'file';
  image.accept = 'image/png';
  const uploadResult = el('code');
  panel.append(assetForm('原始素材 PNG', image, '上传素材', async input => {
    const saved = await api('images', { method: 'POST', body: input.files[0] });
    uploadResult.textContent = `${saved.width} × ${saved.height} · SHA-256 ${saved.digest}`;
  }), uploadResult);
  if (state.role !== 'admin') return;

  const rightsList = el('section', undefined, 'group');
  const importReport = el('ul');
  const json = el('textarea');
  json.rows = 5;
  panel.append(el('h2', '授权导入'), assetForm('授权记录 JSON 数组', json, '导入授权', async input => {
    const records = JSON.parse(input.value);
    if (!Array.isArray(records)) throw new Error('请提供 JSON 数组');
    const result = await api('catalog/rights/import', { method: 'POST', body: { items: records } });
    importReport.replaceChildren(...result.results.map(row => el('li', row.status === 'rejected'
      ? `第 ${row.row} 行 · ${row.message}` : `第 ${row.row} 行 · ${row.id} · ${row.status === 'created' ? '已创建' : '已存在'}`)));
    await loadRights();
  }), importReport, rightsList);

  async function loadRights(after = null) {
    const page = await api('rights' + (after ? '?after=' + encodeURIComponent(after) : ''));
    if (version !== viewVersion) return;
    rightsList.replaceChildren(el('h2', '素材授权'));
    if (!page.items.length) rightsList.append(el('p', '当前没有授权记录。'));
    for (const right of page.items) {
      const record = el('article', undefined, 'group');
      record.append(el('h3', right.id), el('p', `${right.grantor} · ${right.scope.join(' / ')} · R${right.revision}`),
        el('p', right.revoked_at ? '已撤销 · ' + right.revoked_at : '到期 · ' + right.valid_until + ' UTC', 'muted'));
      const impact = el('ul');
      const withdraw = reasonAction('撤销授权', '撤销原因', '确认撤销', async reason => {
        const result = await api('rights/' + right.id + '/revoke', { method: 'POST', body: { reason } });
        record.querySelector('.muted').textContent = '已撤销 · ' + result.rights.revoked_at;
        withdraw.remove();
        for (const row of result.affected) impact.append(el('li', `${row.bundle} · ${row.actor ?? '暂无下载回执'}`));
        if (!result.affected.length) impact.append(el('li', '没有受影响的交付包。'));
      }, true);
      if (!right.revoked_at) record.append(withdraw);
      record.append(impact);
      rightsList.append(record);
    }
    if (after) rightsList.append(action('返回首批授权', () => loadRights(), true));
    if (page.next_cursor) rightsList.append(action('下一批授权', () => loadRights(page.next_cursor), true));
  }
  await loadRights();
}

get('#login form').addEventListener('submit', event => {
  event.preventDefault();
  run(event.submitter, async () => {
    state.token = get('#token').value.trim();
    const session = await api('session');
    state.role = session.role;
    await list();
    if (can('operator')) {
      const catalog = await api('catalog');
      choices(get('#variants'), catalog.variants);
      choices(get('#templates'), catalog.templates);
      get('#new-batch').hidden = false;
      get('#operations').hidden = false;
    }
    get('#assets').hidden = !can('designer');
    get('#campaigns').hidden = !['admin', 'operator', 'reviewer', 'designer'].includes(state.role);
    get('#token').value = '';
    get('#identity').textContent = session.tenant + ' / ' + session.actor + ' · ' + session.role;
    get('#login').hidden = true;
    get('#workspace').hidden = false;
  });
});

get('#plan').addEventListener('submit', event => {
  event.preventDefault();
  run(event.submitter, async () => {
    const input = new FormData(event.currentTarget);
    const refs = select => Array.from(get(select).selectedOptions, option => JSON.parse(option.value));
    const date = field => new Date(input.get(field)).toISOString().replace('.000Z', 'Z');
    const batch = await api('batches', { method: 'POST', body: {
      campaign: input.get('campaign'), provider: input.get('provider'), candidates_per_unit: Number(input.get('count')),
      ceiling_micros: Number(input.get('budget')), starts_at: date('start'), ends_at: date('end'),
      variants: refs('#variants'), templates: refs('#templates')
    } });
    await list();
    await load(batch.id);
  });
});

get('#refresh').addEventListener('click', event => run(event.currentTarget, async () => {
  await list();
  if (state.id) await load(state.id);
}));

get('#operations').addEventListener('click', event => run(event.currentTarget, showOperations));
get('#next-batches').addEventListener('click', event => run(event.currentTarget, () => list(true)));
get('#assets').addEventListener('click', event => run(event.currentTarget, showAssets));
