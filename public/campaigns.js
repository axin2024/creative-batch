'use strict';

{
  const json = value => JSON.stringify(value, null, 2);
  const post = (path, body, headers) => api(path, { method: 'POST', body, headers });
  const date = value => new Date(value).toISOString().replace(/\.\d{3}Z$/, 'Z');
  const receipt = (node, value) => { node.textContent = json(value); };
  const query = values => new URLSearchParams(values).toString();

  async function optional(path) {
    try { return await api(path); }
    catch (error) {
      if (error.status === 404) return null;
      throw error;
    }
  }

  function field(parent, title, value = '', { type = 'text', rows = 0, required = true } = {}) {
    const label = el('label', title);
    const input = el(rows ? 'textarea' : 'input');
    if (rows) input.rows = rows;
    else input.type = type;
    input.value = value;
    input.required = required;
    label.append(input);
    parent.append(label);
    return input;
  }

  function card(parent, name) {
    const section = el('article', undefined, 'panel');
    section.append(el('h3', name));
    parent.append(section);
    return section;
  }

  function submit(form, label, work) {
    const button = el('button', label);
    form.append(button);
    form.addEventListener('submit', event => {
      event.preventDefault();
      run(button, work);
    });
  }

  function revisionEditor(parent, title, example, save) {
    const section = card(parent, title);
    const form = el('form');
    const editor = field(form, '版本内容（JSON）', json(example), { rows: 8 });
    const output = el('pre');
    submit(form, '保存版本', async () => receipt(output, await save(JSON.parse(editor.value))));
    section.append(form, output);
    return { section, editor, output };
  }

  async function workspaces(selected) {
    const version = ++viewVersion;
    state.id = '';
    clearImages();
    const target = get('#campaign');
    const workspace = el('div');
    workspace.append(el('h2', '地区活动'));
    target.replaceChildren(workspace);
    const list = await api('campaigns');
    if (version !== viewVersion) return;
    const menu = card(workspace, '活动工作区');
    for (const row of list) menu.append(action(row.name, () => workspaces(row.id), row.id !== selected));
    if (can('designer')) {
      const form = el('form');
      const name = field(form, '新活动名称');
      submit(form, '创建工作区', async () => {
        const created = await post('campaigns', { name: name.value });
        await workspaces(created.id);
      });
      menu.append(form);
    }
    if (can('designer') || can('reviewer')) addFontRegistration(workspace);
    if (state.role === 'admin') addRightsRegistration(workspace);
    if (selected) await showCampaign(workspace, selected, version);
  }

  function addFontRegistration(workspace) {
    const section = card(workspace, '字体登记');
    const form = el('form');
    const file = field(form, '字体文件', '', { type: 'file' });
    file.accept = '.ttf,.otf';
    const family = field(form, '字体名称');
    const license = field(form, '字体许可证');
    const evidence = field(form, '许可证地址');
    const output = el('pre');
    submit(form, '登记字体', async () => {
      receipt(output, await post('campaign-fonts', file.files[0], {
        'Content-Type': 'application/octet-stream', 'X-Font-Family': family.value.trim(),
        'X-Font-License': license.value.trim(), 'X-Font-Evidence': evidence.value.trim()
      }));
    });
    section.append(form, output);
  }

  function addRightsRegistration(workspace) {
    const section = card(workspace, '地区许可登记');
    const form = el('form');
    const id = field(form, '许可编号');
    const version = field(form, '当前许可修订号', '0', { type: 'number' });
    const type = field(form, '素材类型（image 或 font）', 'image');
    const digest = field(form, '许可素材摘要');
    const territories = field(form, '授权地区（逗号分隔）');
    const channels = field(form, '授权渠道（逗号分隔）');
    const validFrom = field(form, '许可生效时间', '', { type: 'datetime-local' });
    const validUntil = field(form, '许可到期时间', '', { type: 'datetime-local' });
    const licensor = field(form, '许可方');
    const proof = field(form, '许可证明');
    const commercial = field(form, '允许商业使用', '', { type: 'checkbox', required: false });
    const split = value => value.split(/[,，]/).map(part => part.trim()).filter(Boolean);
    const output = el('pre');
    submit(form, '保存地区许可', async () => {
      const saved = await post('campaign-grants/' + encodeURIComponent(id.value.trim()), {
        expected_revision: Number(version.value), asset_type: type.value.trim(), source_digest: digest.value.trim(),
        territories: split(territories.value.toUpperCase()), channels: split(channels.value), valid_from: date(validFrom.value),
        valid_until: date(validUntil.value), licensor: licensor.value.trim(), evidence: proof.value.trim(), commercial: commercial.checked
      });
      version.value = saved.revision;
      receipt(output, saved);
    });
    section.append(form, output);
  }

  async function showCampaign(workspace, id, version) {
    const base = 'campaigns/' + id;
    const [campaign, initialMaster] = await Promise.all([api(base), optional(base + '/master')]);
    if (version !== viewVersion) return;
    workspace.append(el('h2', campaign.name));
    let master = initialMaster;
    const masters = card(workspace, '冻结母版');
    const masterRevision = field(masters, '读取修订号（留空读取当前）', '', { type: 'number', required: false });
    const masterOutput = el('pre');
    if (master) receipt(masterOutput, master);
    masters.append(action('读取母版版本', async () => {
      const suffix = masterRevision.value ? '?' + query({ revision: masterRevision.value }) : '';
      receipt(masterOutput, await api(base + '/master' + suffix));
    }, true), masterOutput);
    if (can('designer')) {
      const example = {
        source_locale: 'en', selling_points: ['Slow roast'],
        product: { source: '', rect: [5700, 1200, 4000, 7500] },
        logo: { source: '', rect: [350, 300, 1700, 1700] },
        background: { color: '#eae1ce' }, allow_background: true, ink: '#20384c',
        text_area: [450, 2500, 4800, 6700], segments: [
          { id: 'title', kind: 'headline', text: 'Kite Coffee' },
          { id: 'legal', kind: 'disclaimer', text: 'Available while stocks last.' }
        ]
      };
      const edit = revisionEditor(masters, '提交新母版', { ...(master?.snapshot ?? example), expected_revision: master?.revision ?? 0 },
        async body => {
          master = await post(base + '/master', body);
          receipt(masterOutput, master);
          edit.editor.value = json({ ...master.snapshot, expected_revision: master.revision });
          return { revision: master.revision, digest: master.digest };
        });
    }

    const localeCard = card(workspace, '地区版本与术语');
    const territory = field(localeCard, '地区代码', 'AE');
    const locale = field(localeCard, '语言标签', 'ar');
    const selection = () => ({ territory: territory.value.trim().toUpperCase(), locale: locale.value.trim().toLowerCase() });
    const font = field(localeCard, '字体摘要', '', { required: false });
    const bg = field(localeCard, '替换背景颜色', '', { required: false });
    const bgSource = field(localeCard, '替换背景素材摘要', '', { required: false });
    const copy = el('div');
    localeCard.append(copy);
    const reason = field(localeCard, '人工修订理由', '', { required: false });
    const localeOutput = el('pre');
    const segmentFields = new Map();
    let localized;
    let loadedSelection = '';
    const currentKey = () => query(selection());
    let terms;
    let termEditor;
    if (can('reviewer')) {
      termEditor = revisionEditor(localeCard, '登记审核后的术语', {
        expected_revision: 0, required: [], banned: [], disclaimers: [{ segment: 'legal', text: '' }]
      }, async body => post(base + '/terms', { ...body, locale: selection().locale }));
    }
    function showLocale() {
      copy.replaceChildren();
      segmentFields.clear();
      if (!master) return;
      for (const segment of master.snapshot.segments) {
        const label = segment.kind === 'disclaimer' ? '免责声明' : segment.kind === 'headline' ? '标题' : '正文';
        const block = el('div');
        block.append(el('p', '母版原文 · ' + segment.text));
        segmentFields.set(segment.id, field(block, label + ' · ' + segment.id, localized?.snapshot.copy[segment.id] ?? '', { rows: 2 }));
        copy.append(block);
      }
      if (localized) font.value = localized.snapshot.font;
      bg.value = localized && localized.snapshot.background.color !== master.snapshot.background.color ? localized.snapshot.background.color : '';
      bgSource.value = localized && localized.snapshot.background.source !== master.snapshot.background.source ? localized.snapshot.background.source : '';
      receipt(localeOutput, { master_revision: master.revision, term_revision: terms?.revision ?? null,
        regional_revision: localized?.revision ?? null, language_flags: localized?.snapshot.language_flags, provenance: localized?.provenance });
    }
    async function readLocale() {
      const selected = selection();
      const key = currentKey();
      [terms, localized] = await Promise.all([
        optional(base + '/terms?' + query({ locale: selected.locale })),
        optional(base + '/localizations?' + key)
      ]);
      if (key !== currentKey()) return;
      loadedSelection = key;
      if (termEditor && terms) {
        const { required, banned, disclaimers } = terms.snapshot;
        termEditor.editor.value = json({ expected_revision: terms.revision, required, banned,
          disclaimers: Object.entries(disclaimers).map(([segment, text]) => ({ segment, text })) });
      }
      showLocale();
    }
    async function saveLocale(model) {
      if (loadedSelection !== currentKey()) throw new Error('请先读取当前地区与语言版本。');
      if (!master || !terms) throw new Error('请先冻结母版并登记获审术语。');
      const body = { ...selection(), expected_revision: localized?.revision ?? 0, master_rev: master.revision,
        term_rev: terms.revision, font: font.value.trim() };
      if (bg.value || bgSource.value) {
        body.background = { color: bg.value || master.snapshot.background.color };
        if (bgSource.value) body.background.source = bgSource.value.trim();
      }
      if (!model) {
        body.reason = reason.value.trim();
        body.copy = Object.fromEntries(Array.from(segmentFields, ([key, input]) => [key, input.value]));
      }
      localized = await post(base + (model ? '/translate' : '/localizations'), body);
      showLocale();
    }
    localeCard.append(action('读取地区版本', readLocale, true));
    if (can('designer') || can('reviewer')) {
      localeCard.append(action('生成待审译稿', () => saveLocale(true), true), action('保存人工修订', () => saveLocale(false)));
    }
    localeCard.append(localeOutput);

    const layouts = card(workspace, '排版与授权');
    const channel = field(layouts, '渠道标识', 'square');
    const renderList = el('div');
    const renderDetail = el('div');
    if (can('designer') || can('reviewer')) layouts.append(action('渲染渠道预览', async () => {
      if (loadedSelection !== currentKey() || !localized) throw new Error('请先读取或保存地区版本。');
      const render = await post(base + '/renders', { ...selection(), channel: channel.value, segment_rev: localized.revision });
      await refreshRenders();
      await renderView(render.id, renderDetail, base, version, refreshPlacements);
    }));
    async function refreshRenders() {
      const renders = await api(base + '/renders');
      if (version !== viewVersion) return;
      renderList.replaceChildren(...renders.map(render => action(
        [render.territory, render.locale, render.channel, 'v' + render.localization_revision].join(' · '),
        () => renderView(render.id, renderDetail, base, version, refreshPlacements), true)));
    }
    layouts.append(action('刷新预览列表', refreshRenders, true), renderList, renderDetail);
    const placementCard = card(workspace, '渠道投放');
    const deliveries = el('div');
    const deliveryDetail = el('div');
    async function refreshPlacements() {
      const placements = await api(base + '/placements');
      if (version !== viewVersion) return;
      deliveries.replaceChildren(...placements.map(row => action(
        [row.territory, row.locale, row.channel, row.delivery_state].join(' · '),
        () => placementView(row.placement_id, deliveryDetail, refreshPlacements), true)));
    }
    placementCard.append(action('刷新渠道回执', refreshPlacements, true), deliveries, deliveryDetail);
    addRecall(workspace);
    await Promise.all([refreshRenders(), refreshPlacements()]);
  }

  async function renderView(id, target, base, version, refreshPlacements) {
    const path = 'campaign-renders/' + id;
    const [render, image, approvalResponse, grants] = await Promise.all([
      api(path), api(path + '/image', { blob: true }), api(path + '/approve'), api('campaign-grants')
    ]);
    if (version !== viewVersion || !target.isConnected) return;
    let approval = approvalResponse.approval;
    for (const previous of target.querySelectorAll('img')) {
      state.images.delete(previous.src);
      URL.revokeObjectURL(previous.src);
    }
    const picture = el('img');
    picture.alt = [render.territory, render.locale, render.channel, '地区投放预览'].join(' ');
    picture.src = URL.createObjectURL(image.data);
    state.images.add(picture.src);
    const output = el('pre');
    const print = value => receipt(output, value);
    print({ asset_rev: id, language_flags: render.evidence.language_flags, layout_flags: render.evidence.layout_flags, approval });
    target.replaceChildren(picture);
    const starts = field(target, '投放开始', '', { type: 'datetime-local' });
    const ends = field(target, '投放结束', '', { type: 'datetime-local' });
    const selectors = new Map();
    for (const [layer, source] of Object.entries(render.evidence.sources)) {
      const label = el('label', layer + ' · ' + source.digest.slice(0, 12));
      const select = el('select');
      select.append(el('option', '请选择许可'));
      select.firstChild.value = '';
      for (const grant of grants.filter(grant => grant.asset_type === source.type && grant.source_digest === source.digest)) {
        const option = el('option', grant.id + ' v' + grant.revision + ' · ' + grant.valid_until);
        option.value = json({ id: grant.id, revision: grant.revision });
        select.append(option);
      }
      label.append(select);
      target.append(label);
      selectors.set(source.digest, select);
    }
    const schedule = () => {
      if (!starts.value || !ends.value) throw new Error('请填写投放开始与结束时间。');
      return { starts_at: date(starts.value), ends_at: date(ends.value),
        grants: Array.from(selectors.values()).filter(select => select.value).map(select => JSON.parse(select.value)) };
    };
    target.append(action('检查授权范围', async () => print(await post(path + '/rights-preview', schedule())), true));
    if (can('reviewer')) {
      const reviewReason = field(target, '审核理由');
      for (const [decision, label] of [['approved', '批准地区投放'], ['rejected', '退回修改']]) {
        target.append(action(label, async () => {
          approval = await post(path + '/approve', { ...(decision === 'approved' ? schedule() : {}),
            expected_revision: approval?.revision ?? 0, decision, reason: reviewReason.value.trim() });
          print(approval);
        }, decision === 'rejected'));
      }
    }
    if (can('operator')) {
      const request = field(target, '投放请求键', crypto.randomUUID());
      const replaces = field(target, '替换的已撤回投放 ID', '', { required: false });
      target.append(action('提交渠道投放', async () => {
        if (!approval?.rights_rev) throw new Error('该预览需要先获得地区授权审批。');
        const body = { asset_rev: id, rights_rev: approval.rights_rev, channel: render.channel, locale: render.locale, territory: render.territory };
        if (replaces.value) body.replaces = replaces.value.trim();
        print(await post(base + '/placements', body, { 'Idempotency-Key': request.value.trim() }));
        await refreshPlacements();
      }));
    }
    target.append(output);
  }

  async function placementView(id, target, refresh) {
    const path = 'campaign-placements/' + id;
    const placement = await api(path);
    if (!target.isConnected) return;
    const output = el('pre', json(placement));
    target.replaceChildren(output);
    if (!can('operator')) return;
    for (const operation of placement.operations.filter(entry => ['failed', 'uncertain'].includes(entry.phase))) {
      target.append(action('重试 ' + operation.operation, async () => {
        receipt(output, await post(path + '/retry', { operation: operation.operation }));
        await refresh();
      }, true));
    }
    if (!['withdrawn', 'cancelled'].includes(placement.delivery_state)) {
      target.append(reasonAction('撤回该投放', '撤回理由', '确认撤回', async reason => {
        receipt(output, await post(path + '/withdraw', { reason }));
        await refresh();
      }, true));
    }
  }

  function addRecall(workspace) {
    const recall = card(workspace, '授权撤回与渠道回执');
    const id = field(recall, '撤回编号');
    const output = el('pre');
    recall.append(action('读取撤回进度', async () => receipt(output, await api('campaign-recalls/' + id.value.trim())), true));
    if (state.role === 'admin') {
      const type = field(recall, '授权素材类型（image 或 font）', 'image');
      const digest = field(recall, '授权素材摘要');
      const grant = field(recall, '指定许可 ID（留空撤回素材）', '', { required: false });
      const rev = field(recall, '许可修订号', '', { type: 'number', required: false });
      recall.append(reasonAction('发起授权撤回', '撤回理由', '确认撤回授权', async reason => {
        const body = { asset_type: type.value.trim(), source_digest: digest.value.trim(), reason };
        if (grant.value) Object.assign(body, { grant_id: grant.value.trim(), grant_revision: Number(rev.value) });
        const result = await post('campaign-recalls', body);
        id.value = result.recall_id;
        receipt(output, result);
      }));
    }
    recall.append(output);
  }

  get('#campaigns').addEventListener('click', event => run(event.currentTarget, () => workspaces()));
}
