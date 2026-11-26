# 维护与接口

仅暴露 `public/`，Web 入口与后台 worker 使用专用数据库账号。限制本地临时目录和内存，管理进程单独保管账号签发权限。令牌通过 Bearer 请求头传输，按工作室及角色绑定并支持撤销。生产反向代理必须配置 TLS 并传递 Authorization 头，使用 `public/index.php` 作为前端控制器。示例开发服务器监听回环地址。

## 模型

`live` 模式连接 OpenAI 兼容服务。配置 `CREATIVE_MODEL_URL`（完整 chat completions 地址）、`CREATIVE_MODEL_NAME`、`CREATIVE_LIVE_COST_MICROS`（每个候选的预算）和可选的 `CREATIVE_MODEL_TOKEN`。模型名随批次冻结，请求使用原任务号发送 `Idempotency-Key`。服务只接收渠道、尺寸、色款标识和种子。工作室保持原图不变，在本地由 GD 合成。支持在响应的 `usage.cost_micros` 记录供应方提供的整数微单位。

`image` 模式从异步图像后端取得背景。配置 `CREATIVE_IMAGE_URL`（后端根地址）、`CREATIVE_IMAGE_MODEL`、`CREATIVE_IMAGE_COST_MICROS` 和可选的 `CREATIVE_IMAGE_TOKEN`。工作室冻结后端地址摘要与模型名，只发送目标宽高、配色、模型和种子。外部连接使用 HTTPS，本机回环允许 HTTP。

| 异步后端请求 | 约定 |
| --- | --- |
| `PUT /v1/jobs/{id}` | 同一 `id` 和输入至多创建一个作业。输入为 `model`、`seed`、`width`、`height`、`background`、`accent`。相同标识与不同输入冲突。 |
| `GET /v1/jobs/{id}` | 使用原 `id` 查回作业，查不到返回 404。调用方保留待查状态，运营确认后可写入关闭原因。 |
| 作业结果 | `job_id`、`model`、`state`。状态为 `queued`、`running`、`completed` 或 `failed`。完成时返回 `png_base64`、`png_sha256`、布尔商用授权 `commercial` 及可选整数 `cost_micros`。 |

背景先校验摘要再合成。缺商用权的候选保留在审核工作台，不能采用。若提交响应丢失或后端尚在运行，工作台通过原任务号发起查询。供应方已报费用、计划费用、缺报价回执和离线回放在单元统计中分别显示。

新活动冻结第二版交付清单。每个采用项的 `candidate_costs` 汇总同一色款和渠道的全部已落库候选，包括被拒和返工产生的候选。`reported_micros` 为供应方已报费用之和。`null` 表示没有报价，`0` 表示已报费用为零，`unpriced_live_outputs` 记录未能计入实际费用的候选数。第一版活动继续生成第一版清单，原交付摘要可复核。

## HTTP 操作

| 操作 | 路径 | 角色 |
| --- | --- | --- |
| 会话与活动 | `GET /api/session`, `GET /api/batches` | 有效工作室凭据 |
| 素材 | `POST /api/images`，请求体为 PNG | 设计或管理 |
| 目录读取 | `GET /api/catalog` | 设计、审核或运营 |
| 目录版本 | `PUT /api/{brands,variants,templates,channels}/{id}` | 管理 |
| 授权 | `GET /api/rights`，`POST /api/rights/{id}`，`POST /api/catalog/rights/import`，`POST /api/rights/{id}/revoke` | 管理 |
| 生产 | `POST /api/batches`, `POST /api/batches/{id}/submit` | 运营 |
| 恢复及返工 | `POST /api/tasks/{id}/{resolve,rework}` | 运营 |
| 核图及选图 | `GET /api/candidates/{id}/{source,image}`, `POST /api/candidates/{id}/{review,select}` | 对应审核及运营权限 |
| 交付 | `GET /api/batches/{id}/preview`, `POST /api/batches/{id}/publish`, `GET /api/batches/{id}/bundles`, `GET /api/bundles/{id}/download` | 运营及渠道 |
| 审计 | `GET /api/audit/{object}` | 工作室运营权按实现核验 |
| 生产统计 | `GET /api/operations` | 运营或管理 |

修改操作要求匹配当前版本、任务序号或冻结摘要。完整请求示例请参照 `src/Demo.php` 与 `tests/lifecycle.php`。

模板可选 `composition` 字段。`mask` 使用 `source-alpha`，按源 PNG 透明通道合成商品。`product_box` 和 `headline_box` 各使用从画布左上角起算的 `[左, 上, 宽, 高]` 整数百分比，两区须位于渠道留白内且互不重叠。`font` 可用 `gd-bitmap-2` 或 `gd-bitmap-5`，随 PHP GD 提供。标题需为 ASCII 并完整放入文案区。新增和已有模板可按渠道规格分别修订，旧批次保留创建时的样式。

模板也可通过 `generation` 限定后端与模型。`backend` 取 `replay`、`live` 或 `image`，外部后端需要填 `model`。`seed_mode` 为 `derived`，由冻结摘要与候选身份派生种子；外部供应方是否保证同种子像素一致取决于其实现。模板版不匹配当前后端或模型时，批次规划被拒绝。

授权导入接受 `items` 数组，每行包括 `id`、`image_digest`、`grantor`、`commercial`、`channels`、`valid_from` 和 `valid_until`。时间为秒精度 UTC。工作台的导入表单直接接收数组，最多 100 行。相同内容重试返回原记录，冲突行单独返回原因。已撤销的授权仍保持撤销。授权列表按到期时间分页，下一页将 `next_cursor` 传入 `after`。

生产列表支持 `GET /api/batches?limit=25`，可带 `phase`，后续页用返回的 `next_cursor` 作为 `before`。渠道账号仅列已发布批次。生产统计按最近 30 天内创建的批次汇总当前人工采用、审批和返工；返工率为返工任务数除以全部任务数，最近返工保留原因和原批次入口。

## 运行与恢复

预算采用整数微单位。任务领取和阶段推进由工作室行锁及领取租约保护，渲染在事务外，生成回执落库后再独立记账。未知任务根据原任务号查询，回执存在时重放记账，原回执缺失则由运营在核对供应方后填写关闭原因，再决定是否创建返工任务。

所有原图、成图、清单及审计行存于专用 MySQL 库。停止写入进程，执行 `mysqldump --single-transaction --hex-blob`，恢复到隔离数据库后验证目录和版本、按已知候选摘要回读图片。对已撤销的素材或已作废的人工审核版本重复请求下载，确认门禁。新发包时保留源码版本、预算快照和外部模型版本说明。

从 v1.0.0 升到 v1.1.0 时先备份，再运行 `php bin/creative.php migrate`。第 4 项迁移添加批次创建时间和阶段索引。服务仍能读取既有批次及第一版清单。PHP 的内置 Web 服务器用于开发；同一工作室内的关键写入通过数据库行锁串行化。单个批次最多 96 个初始候选，当前实测记录见 [验收](validation.md)。
