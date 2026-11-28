# 多地区品牌活动

总部冻结母版，地区团队提交文案和排版，审核员检查许可，渠道运营跟踪每个版位的上线和撤回。活动母版、术语、排版、许可决定和交付清单均有可追踪的版本。 [完整需求与 A1–A14](requirements/08-多地区本地化-prd.md)

![Kite Coffee 阿拉伯语授权版预览](examples/kite-campaign-ar.png)

## 启动工作台

按根目录 README 初始化 PHP 8.2 和 MySQL 8.4。为地区工作台安装 Node.js 20 以上、锁定的 npm 依赖及 Chromium。

```sh
npm ci
npx --no-install playwright-core install chromium
export CREATIVE_LAYOUT_PORT=8126
export CREATIVE_LAYOUT_TOKEN="$(openssl rand -hex 24)"
export CREATIVE_LAYOUT_URL=http://127.0.0.1:8126
```

让 PHP 与布局进程共享上述环境。运行 `npm run layout` 和 README 中的 PHP Web 进程。可通过 `CREATIVE_CHROMIUM` 指定本机 Chromium 可执行文件。服务在回环接口渲染，从请求提供的字体中检查字形覆盖，再按固定母版边界生成 PNG。根目录 `examples/fonts/` 提供两套按 OFL 1.1 分发的本地字体与许可证。 [Noto Sans 源码](https://github.com/google/fonts/tree/main/ofl/notosans) · [Noto Sans Arabic 源码](https://github.com/google/fonts/tree/main/ofl/notosansarabic)

同一工作室按 README 建立管理员、运营与审核身份，再执行 `php bin/creative.php provision studio designer designer 8`。在工作台打开「地区活动」。用单独的进程注入运营令牌 `CREATIVE_TOKEN` 并运行 `php bin/creative.php campaign-worker` 消费投放队列。`--once` 可单步检查队列。

## 一次活动如何交付

1. 设计师从素材页上传商品与标识，记下 SHA-256。在「活动母版」填写两者摘要、卖点、字体与文案区域。图层坐标以画布的万分比表示。冻结后，地区团队可以替换获准的背景，商品与标识继续引用母版摘要。
2. 审核员建立对应语言的术语，录入必用表达、禁用词和已批准的免责声明。设计师在「字体登记」上传 TTF 或 OTF 文件，填写字体的英文名称、许可证和地址，把返回的摘要用于文案排版。可先保存模型建议，再逐句修订和渲染。
3. 管理员在「地区许可登记」为每个图层和字体录入许可，填写地区、渠道、有效时间与证明。新许可的当前修订号为 0。时间按浏览器本地时区填写并转换成 UTC。审核员核对排版预览，选择时间窗口和许可版本。许可缺失、地区不符、窗口越界和文案门槛都会列出可复核的原因。
4. 运营按地区、语种和渠道提交投放。重复提交同一版位幂等键返回原回执。渠道回执未知时，重试原操作并先查询回执。替换已上线素材时提交新版本并撤回原版位。
5. 管理员撤销一个源摘要后，在撤回单查看引用它的版位、负责人和渠道状态。每个渠道独立完成。在撤回已被部分接受时，使用原版位的重试按钮补办剩余回执。

支持从旧版已采用的成图派生母版。升级先备份 MySQL 和本地素材、渠道目录，保持渠道目标一致，再运行 `php bin/creative.php migrate`。界面保留之前的交付清单与成图。原采用图可以预览，补齐独立的地区许可和审批后才能投放。 [旧库恢复演练](results/campaign-upgrade-v2.json)

## 本地模型与渠道接入

翻译可以手工填写。启用本地 JSON Schema 聊天接口时设置 `CREATIVE_TRANSLATION_URL`、`CREATIVE_TRANSLATION_MODEL`、`CREATIVE_TRANSLATION_WEIGHTS_SHA256` 和可选的 `CREATIVE_TRANSLATION_TOKEN`。URL 为回环服务地址，服务会请求 `/v1/chat/completions`。实测用 llama.cpp b12686 CPU 两线程与 Qwen2.5-0.5B-Instruct Q8_0，权重 SHA-256 为 `ca59ca7f13d0e15a8cfa77bd17e65d24f6844b554a7b6c12e07a5f89ff76844e`。建议保留原始输出、耗时、token 用量和输入输出摘要，人工审校后才可送审。成本来源记录为本地计算。 [模型发布页](https://huggingface.co/Qwen/Qwen2.5-0.5B-Instruct-GGUF)

默认渠道写入 `CREATIVE_CHANNEL_ROOT` 下的本地目录，演示可用。实际接入时，在应用和发布进程中设置 `CREATIVE_CAMPAIGN_CHANNELS`，例如 `{"square":{"url":"https://channel.example.com","token":"..."}}`。服务对 `/v1/placements/{placement_id}/operations/{publish|withdraw}` 先 GET 再 PUT，请求带 `Idempotency-Key`。接收方按操作编号持久化回执，查询不存在返回 404，成功返回原 `operation_id`、`placement_id`、`operation`、`channel`、`manifest_digest`，以及 `remote_revision` 和 `state`。上线的 `state` 为 `online`，撤回为 `withdrawn`。投放清单锁定渠道 URL 的摘要。 [可运行的渠道契约夹具](../tests/channel-router.php)

## 复现验收

Windows 的隔离环境使用 `pwsh -File scripts/test-mysql.ps1 -MySQLBin <8.4-bin> -Scenario Campaign -Connector -CampaignBrowser`。启动布局服务后，将 `CREATIVE_TEST_FONTS` 指向 `examples/fonts`，可选 `CREATIVE_TEST_EVIDENCE` 指定输出目录。添加本地模型配置并不加 `-Connector` 可复跑 12 个地区版位的真实翻译。使用 `-Capacity` 复跑 400 个版位的本地渠道演练。`-Scenario Upgrade -PreviousTag v1.1.0` 从前一发行版执行备份升级。 [详细结果](validation.md#v200-地区活动)
