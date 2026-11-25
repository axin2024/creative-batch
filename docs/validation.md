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
| 耗时 | 3.745 秒 |

模型通路使用本地 Qwen2.5-0.5B-Instruct Q4_K_M 与 llama.cpp b12686。6 个真实结果全部进入人审，尺寸和商品主体像素采样均通过。该模型 6 次选择了同一套布局与背景组合，本轮数据可用来核实模型接入、素材保护和人工门禁。渲染含模型调用耗时 541–983 毫秒，用量为 500 输入 Token、60 输出 Token。

```powershell
pwsh -File scripts/test-mysql.ps1 -MySQLBin C:/mysql-8.4/bin -Scenario Lifecycle
pwsh -File scripts/test-mysql.ps1 -MySQLBin C:/mysql-8.4/bin -Scenario Live
```

第二条命令需要模型环境变量。 [运行说明](operations.md)
