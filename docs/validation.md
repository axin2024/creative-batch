# 验收

Windows、PHP 8.2.30、MySQL 8.4.13、GD 与 ZipArchive。使用虚构 Kite Coffee 品牌，两种款式和三项渠道。包含从旧模式升级、重复提交、租户隔离、包装偏差、商用权缺失和旧包撤权。

| 指标 | 结果 |
| --- | --- |
| 首批任务 | 12，包含 2 条机器门禁拒绝 |
| 未知任务回执 | 1 条恢复，原任务仍只有一次尝试 |
| 返工 | 新增 1 条独立任务，计量记录合计 13 |
| 交付 | 6 张 PNG 和 1 份清单 |
| 重复下载 | 两个独立下载回执，ZIP 字节一致 |
| 渠道授权撤销 | 3 个受影响的单元被拦截，旧包停止签发 |
| 并发采用 | 两个客户端同时提交，收到一个 200 与一个 409 |
| 包装字人工退回 | 1 项通过机器规则的候选被退回并禁止采用 |
| 低信心样例 | 商品只占 2 个采样像素，转人审并提供原图与候选 |

## 编号用例

| 用例 | 可重跑的观察结果 | 脚本 |
| --- | --- | --- |
| AC-01 | 逐张读取各色款原图，用冻结摘要回读 | `tests/lifecycle.php` |
| AC-02 | 用已过期授权创建批次返回 422 | `tests/lifecycle.php` |
| AC-03 | 两种色款按主图、竖图和横幅合成，渠道修订保留旧画布 | `tests/lifecycle.php` |
| AC-04 | 把 320 × 420 交给横幅检查，返回所需 1200 × 628 | `tests/lifecycle.php` |
| AC-05 | 2 色 × 3 渠道 × 2 候选生成 12 项和 12 条用量记录 | `tests/lifecycle.php` |
| AC-06 | 对同一冻结摘要提交两次，最终仍只处理 12 项 | `tests/lifecycle.php` |
| AC-07 | 单独返工珊瑚色横幅，海岸色横幅新增任务为零 | `tests/lifecycle.php` |
| AC-08 | 供应端收到作业后返回 503，随后按原号查询并恢复，单个作业内只有一次提交 | `tests/provider.php` |
| AC-09 | 审核员以包装文字原因拒绝一张机器通过的候选，采用返回 409 | `tests/lifecycle.php` |
| AC-10 | 无头 Chrome 同屏成功加载原图和低信心候选，五项人审前采用返回 409 | `tests/lifecycle.php`、`tests/browser.js` |
| AC-11 | 改选珊瑚色横幅发布第二修订，海岸色横幅指向原候选 | `tests/lifecycle.php` |
| AC-12 | 两个独立 PHP 进程尝试采用同一单元，仅一个成功 | `tests/lifecycle.php` |
| AC-13 | ZIP 含六张图和清单，逐一校验宽高、图片和清单摘要 | `tests/lifecycle.php` |
| AC-14 | 五项已选时预览保留五项并标出缺项，发布返回 409 | `tests/lifecycle.php` |
| AC-15 | 采用项清单列出两条候选的预算 100 微单位与供应方合计 37 微单位 | `tests/provider.php` |
| AC-16 | 回放归档列出 3 条候选，真实生成预算和费用均无增加 | `tests/lifecycle.php` |

## 真实模型与后端夹具

模型通路使用本地 Qwen2.5-0.5B-Instruct Q4_K_M 与 llama.cpp b12686，CPU 使用 4 线程。6 个真实结果全部进入人审，尺寸和商品主体像素采样均通过。该模型 6 次选择了同一套布局与背景组合。渲染含模型调用耗时 336–612 毫秒，用量为 498 输入 Token、60 输出 Token。[推理记录](results/live-layout.json) · [模型来源](https://huggingface.co/Qwen/Qwen2.5-0.5B-Instruct-GGUF)

异步图像接口使用合成 HTTP 夹具和一次性 MySQL 库。夹具接受两个作业，分别模拟已受理后的响应丢失和排队，均从原编号回读两次。一个背景授权不足，另一个合成结果通过 22,286 个商品像素采样。费用来自夹具回执。

## 自托管、恢复与公开夹具

浏览器使用单独的 PHP HTTP 和 MySQL 实例，实测运营查回原任务、审核员加载两张对照图、运营选稿发布、渠道领取和管理员撤权。跨租户下载返回 404，撤权后原渠道账号收到 409。浏览器同时校验批次游标、生产统计和授权导入的逐行结果。数据库测试在 v1.0.0 源码上建立真实旧包，再用 `mysqldump` 备份到独立库。新版应用第 4 项迁移，读回六张图、历史审计和字节一致的 ZIP，随后发布第二版清单的新包。单独验证索引建立后中断时可以补齐迁移记录。[验收记录](results/release-validation.json)

公开离线数据用 Kite Coffee 包装袋和 Bracken 瓶两组原创商品。不做模型训练。两款 Kite 变体同属开发集，两款 Bracken 变体同属验证集，检查跨组 SHA-256 和感知指纹。每组各有一条主体保持和一条包装被改写的用例。[离线交付包](examples/kite-catalog.zip)

```powershell
pwsh -File scripts/test-mysql.ps1 -MySQLBin C:/mysql-8.4/bin -Scenario Lifecycle
pwsh -File scripts/test-mysql.ps1 -MySQLBin C:/mysql-8.4/bin -Scenario Provider
pwsh -File scripts/test-mysql.ps1 -MySQLBin C:/mysql-8.4/bin -Scenario Upgrade
pwsh -File scripts/test-mysql.ps1 -MySQLBin C:/mysql-8.4/bin -Scenario Browser -PlaywrightCli C:/tools/playwright-cli.js
pwsh -File scripts/test-mysql.ps1 -MySQLBin C:/mysql-8.4/bin -Scenario Live
php tests/evaluation.php
```

`Upgrade` 使用本地 `v1.0.0` 标签，`Browser` 需要 Playwright CLI 和 Chrome，`Live` 需要模型环境变量。[运行说明](operations.md)
