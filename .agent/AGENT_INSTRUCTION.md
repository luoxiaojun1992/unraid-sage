# Unraid Sage — Agent Instruction

## 项目概述

**Unraid Sage** 是一个 Unraid 系统插件（Plugin），定时采集系统运行状态（CPU、内存、磁盘、Docker、网络等），通过 OpenAI 兼容 API 获取 AI 优化建议，并在 Unraid WebGUI 中展示。

---

## 1. 功能需求

### 1.1 系统状态采集

| 类别 | 具体采集项 | 采集命令 |
|------|-----------|---------|
| CPU | load average (1/5/15m)、核心数、温度 | `/proc/loadavg`, `/sys/class/thermal/*/temp`, `nproc` |
| 内存 | total / used / available / swap | `free -b` 或 `/proc/meminfo` |
| 磁盘 | 每块盘使用率、文件系统、挂载点、SMART 状态 | `df -B1`, `smartctl`（如可用） |
| 阵列 | 总容量 / 已用 / 可用、校验盘、缓存池 | `/var/local/emhttp/var.ini` |
| Docker | 容器总数、运行/停止数、各容器状态 | `docker ps -a --format json`, `docker stats --no-stream` |
| 网络 | 接口列表、IP、MTU、流量 | `ip -j addr`, `/proc/net/dev` |
| 系统 | 运行时间、Unraid 版本、内核版本 | `uptime`, `/etc/unraid-version`, `uname -r` |

### 1.2 AI 分析

- 采集的状态数据格式化为 JSON，通过 OpenAI Chat Completions API 发送
- API 格式兼容 Ollama、OpenRouter、Groq、vLLM 等
- 要求 AI 返回结构化建议，含以下字段：
  - `severity`: `high` / `medium` / `low`
  - `category`: `performance` / `storage` / `security` / `other`
  - `title`: 建议标题
  - `description`: 问题描述
  - `suggestion`: 具体优化建议
- `last-advice.json` 顶层字段包含 `collected_at`（采集时间戳）和 `advice_at`（AI 返回时间戳）
- 历史缓存文件 `advice-YYYY-MM-DD-HHMM.json` 文件体内也包含时间戳字段，文件名仅作排序和辨识用

### 1.3 WebGUI 展示

- **主页面** (`ai-advisor.page`):
  - AI 建议卡片列表（按严重程度排序），每条卡片显示：
    - 标题、描述、建议内容
    - 生成时间戳（`advice_at`）
    - 对应的采集时间戳（`collected_at`）
  - 最近一次采集时间
  - 当前锁状态（运行中/空闲/异常锁定），如果异常锁定显示"清除锁"按钮
- **设置页面** (`ai-advisor.settings.page`):
  - 配置 API endpoint / api_key / model / cron 表达式
  - **手动清除锁**按钮 + 当前锁状态指示（锁定中的 PID、进程名、进程是否存活）
  - 清除逻辑：读取锁中 PID → `ps -p $PID -o comm=` 确认进程名含 `ai-sage` → 只有 PID **和** 进程名都匹配时才 `kill` + 删除锁文件 → 否则只写 warning 日志不执行清除
  - 锁被清除时记录日志 `logger -t ai-advisor "lock manually cleared: killed pid $PID (ai-sage)"`

### 1.4 历史建议缓存与清理

AI 建议按次存档到 `/boot/config/plugins/ai-advisor/data/`（Unraid 持久存储，支持 USB 或 SSD），每次采集生成一个带时间戳的文件 `advice-YYYY-MM-DD-HHMM.json`。

**清理机制：**
- **上限淘汰**: 只保留最近 N 条历史记录，超出时删除最旧的
- **触发时机**: 每次新的建议写入后执行清理
- **低开销**: 清理操作只在添加新记录后触发一次，不额外产生独立写入周期
- **来源限定**: 只清理本插件 `data/` 目录下的 `advice-*.json` 文件

### 1.5 用户可配置项

- API Endpoint URL（默认 `http://localhost:11434/v1/chat/completions`）
- API Key（可选，非本地 API 需要）
- Model 名称（本地 Ollama 默认 `qwen2.5:7b`）
- Cron 定时表达式（默认 `0 */6 * * *` 每 6 小时）
- 历史建议保留条数（默认 `30`，设 `0` 表示不保留历史）
- 启用/禁用

---

## 2. 架构

```
┌──────────────────────────────────────────────────────────┐
│  WebGUI (PHP .page)                                       │
│  ├─ 主页面: 展示上次采集时间 + AI 建议列表（含时间戳）    │
│  └─ 设置页: API/key/model/cron/保留条数                  │
│       ├─ 锁状态指示: 锁定中(PID) / 空闲                  │
│       └─ [清除锁] 按钮 → exec(clear-lock.sh)             │
├──────────────────────────────────────────────────────────┤
│  后端脚本 (Shell + jq)                                    │
│  ├─ advisor-daemon.sh (锁管理: 获取/释放/检测)            │
│  ├─ collect-stats.sh → JSON (CPU/内存/磁盘/Docker)       │
│  ├─ query-ai.sh → POST AI API → 含时间戳的建议           │
│  └─ clear-lock.sh (设置页调用, 双重验证 PID+进程名后 kill + 删锁)│
├──────────────────────────────────────────────────────────┤
│  配置文件 /boot/config/plugins/ai-advisor/                │
│  ├─ ai-advisor.cfg (API 配置/定时表达式/保留条数)         │
│  └─ data/ (历史建议缓存, advice-*.json 含时间戳)          │
├──────────────────────────────────────────────────────────┤
│  运行时 /tmp/ai-advisor/                                  │
│  ├─ daemon.lock (PID 锁文件, 防重复执行)                 │
│  ├─ last-stats.json (最近一次采集数据, 原子写入)          │
│  └─ last-advice.json (最近一次 AI 建议, 原子写入, 含时间戳)│
└──────────────────────────────────────────────────────────┘
```

### 启动时序

Unraid 启动顺序：

```
driver_loaded → starting → array_started → disks_mounted
  → svcs_restarted → docker_started → libvirt_started → started
```

本插件在 `event/started`（系统完全就绪）时启动后台 daemon。Daemon 启动后按 cron 表达式定时调度采集/分析任务。

### 事件钩子

- `event/started`: 阵列启动完成 → 启动 advisor-daemon.sh

### 数据流

```
cron 触发
  │
  ├─ advisor-daemon.sh 检查 daemon.lock
  │   ├─ 锁存在 + PID 存活 + 进程名含 ai-sage → 跳过本轮（防重叠）
  │   └─ 锁不存在 / PID 已死 / 名不匹配 → 写锁 → 继续
  │
  ├─ timeout 30 collect-stats.sh --JSON--> last-stats.json (原子写入)
  │
  ├─ timeout 120 query-ai.sh --POST--> AI API → last-advice.json (含时间戳, 原子写入)
  │                                         + data/advice-*.json (历史存档, 含时间戳)
  │                                         + timeout 10 清理淘汰旧记录
  │
  ├─ 总护闸: 超时 180s 强制释放锁
  │
  └─ 释放锁 (删除 daemon.lock)

手动清除 (clear-lock.sh):
  1. 读 daemon.lock → PID
  2. ps -p $PID -o comm= → 进程名
  3. PID 存活 + 进程名含 ai-sage → kill + 删锁 + 记日志
  4. 否则 → 只记 warning，不执行

WebGUI PHP 读取 last-advice.json / data/ 目录展示
         + 读取 daemon.lock 状态展示锁状态
```

---

## 3. 技术方案

### 3.1 技术栈

| 组件 | 技术 | 理由 |
|------|------|------|
| 安装器 | XML `.plg` 格式 | Unraid 标准插件格式 |
| WebGUI | PHP `.page` 文件 | Unraid WebGUI 原生支持 |
| 前端交互 | JavaScript | Unraid 内置 jQuery |
| 数据采集 | Shell 脚本 | 零依赖，Unraid 原生支持 |
| JSON 处理 | `jq` | Unraid 自带 |
| HTTP 请求 | `curl` | Unraid 自带 |
| **无额外依赖** | 仅用 Unraid 自带工具 | 方便用户安装 |

### 3.2 AI API 兼容性

- 协议: OpenAI Chat Completions (`POST /v1/chat/completions`)
- 兼容服务: Ollama / OpenRouter / Groq / vLLM / 任何 OpenAI 兼容 API
- 认证: Bearer Token（API Key 非必填，本地 Ollama 不需要）

### 3.3 安全与持久化设计

- API Key 明文存储到 `/boot/config/plugins/ai-advisor/ai-advisor.cfg`（Unraid 插件标准做法）
- 采集脚本不写入源文件系统，只输出到 `/tmp/`（RAM）
- 历史建议缓存写入持久存储（`/boot/config/plugins/ai-advisor/data/`），每次新写入后清理淘汰旧记录，限制 N 条内
- 清理仅在添加新记录时触发，不产生额外独立写入周期
- **原子写入**: `last-stats.json` 和 `last-advice.json`（运行时文件，供 WebGUI 实时读取）必须使用临时文件 + mv 的方式写入 — 先写 `.tmp` 后缀文件，完成后 `mv` 覆盖目标文件，确保 WebGUI PHP 不会读到半截写的中间状态
- 历史缓存文件 `advice-*.json` 则直接写入，因为 WebGUI 不实时读取单个文件，而是通过 `ls -t | head -N` 读取列表
- 插件卸载时清理 `/boot/config/plugins/ai-advisor/` 和 `/usr/local/emhttp/plugins/ai-advisor/`

### 3.4 文件锁机制（防重复执行）

**目的**: 避免 cron 周期短于 AI API 响应时间时，前后两次执行重叠。

**实现方式**:
- 锁文件: `/tmp/ai-advisor/daemon.lock`
- 锁内容: 写入执行中的进程 PID
- 进程标识: 所有 daemon 进程（advisor-daemon.sh 及其子任务）的进程名必须包含 `ai-sage` 标识
  - `event/started` 通过 `exec -a ai-sage-advisor /bin/bash advisor-daemon.sh &` 启动
  - 子任务脚本在各自入口通过 `exec -a ai-sage-<task> sh` 设置进程名
- 获取锁:
  1. 检查 `daemon.lock` 是否存在
  2. 不存在 → 写入当前 PID → 获得锁
  3. 存在 → 读取 PID → 检查 `/proc/$PID/` 是否存活 **且** 进程名包含 `ai-sage`
  4. PID 存活 + 名匹配 → 跳过本轮，写 warning 日志
  5. PID 已死或名不匹配 → 覆盖写入当前 PID → 获得锁（自愈）
- 释放锁: 本轮执行完成后删除 `daemon.lock`

**执行超时保护**（防止命令阻塞整个循环）:
- 每个子步骤必须设置超时，使用 `timeout` 命令
  - `collect-stats.sh`: 30 秒超时
  - `query-ai.sh`: 120 秒超时（AI API 可能慢）
  - 历史清理: 10 秒超时
- 整个 cron 循环的总护闸: 180 秒后强制释放锁（避免锁永久残留）
- 超时后的步骤标记为"跳过"，不清除已成功的前序步骤结果

**手动清除锁（clear-lock.sh）**:
1. 读取 `daemon.lock` 中的 PID
2. 执行 `ps -p $PID -o comm=` 获取进程名
3. **双重验证**: 仅当 `PID 存活` **且** `进程名包含 ai-sage` 时继续
4. 通过 → `kill $PID` → 等待进程退出（最多 5 秒）→ 删除 `daemon.lock` → 记日志
5. 不通过 → `logger -t ai-advisor "clear-lock aborted: pid $PID name mismatch ($actual_name)"` → 不执行任何操作
6. 锁文件本身不存在 → `logger -t ai-advisor "clear-lock: no lock file"` → 退出

**异常处理**:
- 如果 daemon 在持有锁时崩溃（kill -9、系统重启等），锁文件残留含已死 PID
- 下次 cron 触发时，检测到 PID 不存活或名不匹配，自动覆盖锁继续执行（自愈）
- 极端情况用户通过设置页手动清除（含双重验证）

---

## 4. 目录结构

```
unraid-sage/
├── AGENT_INSTRUCTION.md         # 本文件
├── ai-advisor.plg               # ↑ 安装器（构建时生成到根目录）
├── source/                      # ↓ 源文件目录
│   ├── default.cfg              #   默认配置
│   ├── scripts/
│   │   ├── collect-stats.sh     #   系统状态采集
│   │   ├── query-ai.sh          #   AI API 调用
│   │   ├── advisor-daemon.sh    #   后台守护进程（含锁管理）
│   │   └── clear-lock.sh        #   手动清除锁（设置页调用）
│   ├── event/
│   │   └── started              #   阵列启动钩子
│   ├── pages/
│   │   ├── ai-advisor.page      #   WebGUI 主页面
│   │   └── ai-advisor.settings.page  # 设置页面
│   ├── javascript/
│   │   └── advisor.js           #   WebGUI JS
│   └── styles/
│       └── advisor.css          #   WebGUI CSS
└── tests/                       # 测试脚本
    ├── test_collect_stats.sh    #   采集脚本测试
    ├── test_query_ai.sh         #   AI 接口测试
    ├── test_daemon.sh           #   守护进程测试
    ├── test_atomic_write.sh     #   原子写入测试
    ├── test_lock.sh             #   文件锁测试
    ├── test_clear_lock.sh       #   手动清除锁测试
    ├── test_timeout.sh          #   超时保护测试
    ├── test_advice_timestamp.sh #   建议时间戳测试
    └── test_cleanup.sh          #   缓存清理测试
```

### 部署路径（安装后）

| 源路径 | 部署路径 |
|--------|---------|
| `ai-advisor.plg` | `/boot/config/plugins/ai-advisor.plg` |
| `source/default.cfg` | `/boot/config/plugins/ai-advisor/default.cfg` |
| `source/scripts/*` | `/usr/local/emhttp/plugins/ai-advisor/scripts/` |
| `source/event/*` | `/usr/local/emhttp/plugins/ai-advisor/event/` |
| `source/pages/*` | `/usr/local/emhttp/plugins/ai-advisor/` |
| `source/javascript/*` | `/usr/local/emhttp/plugins/ai-advisor/javascript/` |
| `source/styles/*` | `/usr/local/emhttp/plugins/ai-advisor/styles/` |

---

## 5. 编码规范

### 5.1 Shell 脚本

- **Shebang**: 所有脚本以 `#!/bin/bash` 开头
- **错误处理**: 每个可能失败的命令后检查 `$?`，失败时写日志并退出
- **变量命名**: 小写 + 下划线，如 `cpu_load`, `total_mem`
- **常量**: 大写 + 下划线，如 `CONFIG_DIR="/boot/config/plugins/ai-advisor"`
- **函数命名**: 小写 + 下划线，如 `collect_cpu_stats()`
- **引号**: 所有变量引用必须加双引号 `"$var"`
- **临时文件**: 统一写到 `/tmp/ai-advisor/`，用完清理
- **原子写入**: 运行时 JSON 文件（`last-stats.json`、`last-advice.json`）必须通过 `写 .tmp → mv` 的方式写入，禁止直接 overwrite
- **锁文件**: 所有获取锁的操作必须做 PID 存活检测（`kill -0 $pid` 或检查 `/proc/$pid/`），禁止仅因文件存在就阻塞
- **进程命名**: 所有 daemon 相关的进程名必须包含 `ai-sage` 前缀，通过 `exec -a ai-sage-xxx` 实现
- **超时保护**: 所有可能长时间执行的命令必须使用 `timeout N` 包起来，collect-stats 30s、query-ai 120s、清理 10s
- **日志**: 统一使用 `logger -t ai-advisor "message"` 写入系统日志

### 5.2 PHP (.page)

- **风格**: 遵循 Unraid WebGUI 的 PHP + HTML 混合风格
- **无外部 PHP 框架**: 只用 Unraid 内置的 PHP 函数
- **安全性**: 所有用户输入通过 `htmlspecialchars()` 输出
- **配置读取**: 通过 `parse_ini_file()` 读取 `.cfg` 文件
- **表单处理**: 提交后 `exec()` 调用后台脚本更新配置

### 5.3 JavaScript

- **使用 jQuery**: Unraid WebGUI 内置 jQuery
- **AJAX 请求**: 通过 `$.get()` 或 `$.post()` 读取 JSON 数据
- **DOM 操作**: 仅操作插件域内的元素（`.ai-advisor-*` 前缀）

### 5.4 CSS

- **命名空间**: 所有 class 以 `ai-advisor-` 前缀
- **Unraid 风格**: 参考 Unraid WebGUI 现有样式，保持一致

---

## 6. 测试用例

### 6.1 单元测试 (Shell)

| 测试用例 | 验证内容 | 命令 |
|---------|---------|------|
| `test_collect_stats.sh` | collect-stats.sh 输出合法 JSON | `jq . /tmp/ai-advisor/last-stats.json` |
| 同测试 | JSON 包含所有必要字段 | `jq 'has("cpu","memory","disk","docker","network","system")'` |
| 同测试 | 各字段值不为空 | 逐个字段验证非空 |
| `test_query_ai.sh` | query-ai.sh 输出合法 JSON | `jq . /tmp/ai-advisor/last-advice.json` |
| 同测试 | 建议包含 severity/category/title/description/suggestion | 逐个字段验证 |
| 同测试 | severity 值域正确 | `jq '.[].severity'` 验证 in (high,medium,low) |
| `test_atomic_write.sh` | 写入期间读取不会拿到半截数据 | 后台写大 JSON + 前台持续 `jq .` 不报错 |
| 同测试 | 写入完成后 .tmp 后缀文件已清除 | `ls /tmp/ai-advisor/*.tmp` 应为空 |
| 同测试 | mv 覆盖后目标文件 mtime 更新 | 验证时间戳正确 |
| `test_daemon.sh` | daemon start/stop/status 正常 | 验证 PID 文件 |
| 同测试 | daemon 不重复启动 | 二次 start 应返回已有 PID |
| `test_lock.sh` | 锁文件存在且 PID 存活时跳过执行 | 伪造锁文件 + 存活的 PID(cron)，验证跳过 |
| 同测试 | 锁文件存在但 PID 已死时自动覆盖 | 伪造锁文件 + 已死的 PID，验证重新执行 |
| 同测试 | PID 存活但进程名不含 ai-sage 时自动覆盖 | 伪造锁文件 + 非 ai-sage 进程，验证重新执行 |
| 同测试 | 锁文件被手动清除后能正常执行 | 删除锁文件后验证 cron 可正常触发 |
| `test_clear_lock.sh` | clear-lock.sh: PID+名都匹配 → kill + 删锁 | 启动 ai-sage 进程，验证清除成功 |
| 同测试 | clear-lock.sh: PID 匹配但名不匹配 → 不执行 | 启动非 ai-sage 进程，验证不清除 |
| 同测试 | clear-lock.sh: 无锁文件 → 正常退出 | 验证退出码 0 + 日志 |
| `test_timeout.sh` | collect-stats.sh 超时被 timeout 截断 | 模拟超时场景，验证退出码 124 且锁仍释放 |
| `test_advice_timestamp.sh` | last-advice.json 包含 collected_at 和 advice_at | `jq 'has("collected_at","advice_at")'` |
| 同测试 | 历史缓存文件体内也包含时间戳 | 随机检查一个 history 文件 |
| `test_cleanup.sh` | 历史缓存超过 N 条时正确淘汰 | 生成 N+1 条，验证只剩 N 条 |
| 同测试 | n=0 时不保留历史 | 生成后检查 data/ 为空 |
| 同测试 | 不误删非 advice 文件 | data/ 中放其他文件，验证未被删除 |

### 6.2 集成测试

| 测试场景 | 验证点 |
|---------|--------|
| 插件安装 | `.plg` 安装成功，文件部署到正确位置 |
| 阵列启动 | daemon 自动启动，PID 文件存在 |
| 定时采集 | 到 cron 时间点，`last-stats.json` 更新 |
| AI 分析 | 有建议输出，WebGUI 正常展示 |
| 配置修改 | 修改 API endpoint，下次采集生效 |
| 插件卸载 | 所有文件清理干净 |
| 重新安装 | 配置保留（`/boot/config/plugins/` 中的文件不删） |

### 6.3 异常场景

| 场景 | 预期行为 |
|------|---------|
| AI API 不可用 | `query-ai.sh` 超时退出（timeout 120s），写 error 日志，不清除上次建议，释放锁 |
| cron 重叠触发（上次还没跑完） | daemon 检测锁 + PID 存活 + 进程名匹配，跳过本轮，写 warning 日志 |
| daemon 崩溃后锁残留 | 下次 cron 触发检测 PID 已死或名不匹配，自动覆盖锁继续执行 |
| 锁被用户手动清除（合法进程） | kill 进程 + 删锁，记日志，下次 cron 正常触发 |
| 锁被用户手动清除（PID 名不匹配） | clear-lock.sh 拒绝执行，写 warning 日志，锁文件保留 |
| collect-stats.sh 卡死 | timeout 30s 截断，继续后续步骤（跳过已失败） |
| query-ai.sh 卡死 | timeout 120s 截断，释放锁，保留上次建议 |
| Docker 未运行 | `collect-stats.sh` 跳过 Docker 部分，正常输出其他指标 |
| 磁盘未挂载 | `collect-stats.sh` 跳过对应磁盘，不报错退出 |
| API Key 为空且 endpoint 非本地 | 设置页提示警告 |
| cron 表达式格式非法 | daemon 使用默认值，写 warning 日志 |
| `jq` 不可用 | 脚本检测依赖，输出友好错误 |

---

## 7. 验收标准

- [ ] 插件安装后，无需手动干预，daemon 随系统启动自动运行
- [ ] 每次 cron 触发后，`/tmp/ai-advisor/last-stats.json` 正确更新
- [ ] `last-stats.json` 包含 CPU/内存/磁盘/阵列/Docker/网络/系统 全部指标
- [ ] AI 分析后，`/tmp/ai-advisor/last-advice.json` 包含合法建议，含 `collected_at` 和 `advice_at` 时间戳
- [ ] 写入 `last-*.json` 时使用临时文件 + mv 原子写入，WebGUI 不会读到半截 JSON
- [ ] 写入 `last-*.json` 时使用临时文件 + mv 原子写入，WebGUI 不会读到半截 JSON
- [ ] 写入完成后 `/tmp/ai-advisor/` 下无 `.tmp` 残留文件
- [ ] cron 重叠触发时自动跳过，不并发执行
- [ ] daemon 崩溃后锁残留，下次 cron 自动恢复（检测 PID 已死或名不匹配 → 覆盖锁）
- [ ] 设置页可查看锁状态（PID / 进程名 / 是否存活），支持手动清除锁
- [ ] 清除锁时双重验证（PID + 进程名含 ai-sage），不匹配时拒绝执行
- [ ] 清除锁成功时同时终止进程和删除锁文件
- [ ] 每个子步骤有超时保护（collect 30s / query 120s / cleanup 10s），超过即跳过
- [ ] daemon 进程名包含 ai-sage 标识，可通过 `ps` 识别
- [ ] WebGUI 主页面正确显示采集时间和 AI 建议卡片，每条建议带时间戳
- [ ] 设置页面所有配置项保存后生效
- [ ] 修改 cron 表达式后，下次调度按新表达式执行
- [ ] AI API 不可用时，不清除已有的建议数据
- [ ] 历史建议缓存达到上限后自动淘汰最旧记录，保留条数与配置一致
- [ ] 设保留数为 0 时不写入任何历史缓存
- [ ] 插件卸载后 `/usr/local/emhttp/plugins/ai-advisor/` 完全清除
- [ ] 插件重装后配置文件保留（`/boot/config/plugins/ai-advisor/`）

---

## 8. 开发准则（重要）

1. **先调查，再动手** — 任何修改前，先阅读相关文档和现有代码，理解上下文
2. **最小修改原则** — 只改必须改的地方，不做非必要的重构或优化
3. **单一职责** — 每个脚本/函数只做一件事
4. **不引入额外依赖** — 只使用 Unraid 自带工具（bash, curl, jq, php）
5. **错误容错** — 局部失败不影响整体，不因一个指标采集失败而退出
6. **可回溯** — 所有操作记录日志，方便排查
7. **每次修改后验证** — 运行测试用例确认改动正确
8. **提交粒度** — 每个独立功能一次 commit，message 清晰写明做了什么

### 优先级

```
P0: 插件框架 + 采集脚本 → P1: AI 接口对接 → P2: 守护进程 → P3: WebGUI → P4: 测试
```
