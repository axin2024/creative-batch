'use strict';

const get = selector => document.querySelector(selector);
const checks = { color: '色彩', packaging: '包装', form: '外形', background: '背景', marks: '标识' };
const state = { token: '', role: '', id: '', images: new Set() };
let noticeTimer;
let viewVersion = 0;

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

async function api(path, { method = 'GET', body, blob = false } = {}) {
  const headers = { Authorization: 'Bearer ' + state.token };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  const res = await fetch('/api/' + path, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) });
  if (!res.ok) {
    const problem = await res.json();
    throw new Error(problem.message ?? '请求失败');
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

function can(role) { return state.role === 'admin' || state.role === role; }

async function list() {
  const rows = await api('batches');
  const items = rows.map(row => {
    const button = action(row.campaign, () => load(row.id), true);
    button.className = 'batch';
    button.dataset.batch = row.id;
    button.append(el('small', ' · ' + row.phase));
    return button;
  });
  get('#batches').replaceChildren(...items);
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
      const jobs = batch.tasks.filter(task => task.unit_key === unit);
      if (jobs.length) group.append(taskPanel(jobs, unit, version));
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

function taskPanel(jobs, unit) {
  const details = el('details');
  details.append(el('summary', jobs.length + ' 项生产任务'));
  const ordinal = Math.max(...jobs.map(task => task.ordinal));
  for (const task of jobs) {
    const row = el('p', '#' + (task.ordinal + 1) + ' · ' + task.phase + ' · ' + task.attempts + ' 次');
    if (task.error_code) row.append(el('small', ' · ' + task.error_code));
    if (can('operator') && task.phase === 'unknown') {
      row.append(action('读取原回执', () => mutate('tasks/' + task.id + '/resolve', {}), true));
    }
    if (can('operator') && task.ordinal === ordinal && ['completed','failed'].includes(task.phase)) {
      row.append(action('返工', async () => {
        const reason = window.prompt('填写返工理由 ' + unit);
        if (reason?.trim()) await mutate('tasks/' + task.id + '/rework', { expected_ordinal: ordinal, reason });
      }, true));
    }
    details.append(row);
  }
  return details;
}

function candidateCard(asset, selection, version) {
  const card = el('section', undefined, 'card');
  const approved = selection?.candidate === asset.id;
  card.append(el('h3', approved ? '已选用' : asset.verdict));
  card.append(el('p', asset.eligible ? '机器检查通过' : '机器或商用授权检查未通过', asset.eligible ? 'status' : 'failed'));
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
    card.append(action('选用此稿', async () => {
      const reason = window.prompt('填写选用理由');
      if (reason?.trim()) await mutate('candidates/' + asset.id + '/select', { expected_revision: selection?.revision ?? 0, reason });
    }));
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
    }
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
