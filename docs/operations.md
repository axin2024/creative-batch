# 维护与接口

仅暴露 `public/`，Web 入口与后台 worker 使用专用数据库账号。限制本地临时目录和内存，管理进程单独保管账号签发权限。令牌通过 Bearer 请求头传输，按工作室及角色绑定并支持撤销。生产反向代理必须配置 TLS 并传递 Authorization 头，使用 `public/index.php` 作为前端控制器。示例开发服务器监听回环地址。

## 模型

连接 OpenAI 兼容服务时配置 `CREATIVE_MODEL_URL`（完整 chat completions 地址）、`CREATIVE_MODEL_NAME`、`CREATIVE_LIVE_COST_MICROS`（每个候选的配置预算）以及可选 `CREATIVE_MODEL_TOKEN`。远端使用 HTTPS，本机回环可用 HTTP。服务只发送渠道、尺寸、色款标识和种子，批准的商品原图由本地 GD 合成。当前版本的模型接口负责艺术指导，外部扩散渲染及第三方商用素材供应商集成列在产品路线图。

## HTTP 操作

| 操作 | 路径 | 角色 |
| --- | --- | --- |
| 会话与活动 | `GET /api/session`, `GET /api/batches` | 有效工作室凭据 |
| 素材 | `POST /api/images`，请求体为 PNG/JPEG | 设计 |
| 目录版本 | `GET /api/catalog`, `PUT /api/{brands,variants,templates,channels}/{id}` | 设计 |
| 授权 | `POST /api/rights/{id}`, `POST /api/rights/{id}/revoke` | 管理 |
| 生产 | `POST /api/batches`, `POST /api/batches/{id}/submit` | 运营 |
| 恢复及返工 | `POST /api/tasks/{id}/{resolve,rework}` | 运营 |
| 核图及选图 | `GET /api/candidates/{id}/{source,image}`, `POST /api/candidates/{id}/{review,select}` | 对应审核及运营权限 |
| 交付 | `GET /api/batches/{id}/preview`, `POST /api/batches/{id}/publish`, `GET /api/batches/{id}/bundles`, `GET /api/bundles/{id}/download` | 运营及渠道 |
| 审计 | `GET /api/audit/{object}` | 工作室运营权按实现核验 |

修改操作要求匹配当前版本、任务序号或冻结摘要。完整请求示例请参照 `src/Demo.php` 与 `tests/lifecycle.php`。

## 运行与恢复

预算采用整数微单位。任务领取和阶段推进由工作室行锁及领取租约保护，渲染在事务外，生成回执落库后再独立记账。未知任务根据原任务号查询，回执存在时重放记账，原回执缺失则由运营在核对供应方后填写关闭原因，再决定是否创建返工任务。

所有原图、成图、清单及审计行存于专用 MySQL 库。停止写入进程，执行 `mysqldump --single-transaction --hex-blob`，恢复到隔离数据库后验证目录和版本、按已知候选摘要回读图片。对已撤销的素材或已作废的人工审核版本重复请求下载，确认门禁。新发包时保留源码版本、预算快照和外部模型版本说明。
