# Unraid Sage — Agent Instruction

## 项目概述

**Unraid Sage** 是一个 Unraid 系统插件（Plugin），定时采集系统运行状态（CPU、内存、磁盘、Docker、网络等），通过 OpenAI 兼容 API 获取 AI 优化建议，并在 Unraid WebGUI 中展示。

---

## 1. 功能需求

### 1.1 系统状态采集

| 类别 | 具体采集项 | 采集命令 | 脱敏处理 |
|------|-----------|---------|---------|
| CPU | load average (1/5/15m)、核心数、温度 | `/proc/loadavg`, `/sys/class/thermal/*/temp`, `nproc` | 无敏感信息 |
| 内存 | total / used / available / swap | `free -b` 或 `/proc/meminfo` | 无敏感信息 |
| 磁盘 | 每块盘使用率、文件系统、总容量 | `df -B1` | **不包含**挂载点路径和 UUID、序列号；跳过 SMART 原始值 |
| 阵列 | 总容量 / 已用 / 可用、校验盘类型、缓存池配置 | `/var/local/emhttp/var.ini` | 不包含磁盘序列号 |
| Docker | 容器总数、运行数/停止数、各容器镜像名和 CPU/内存占用 | `docker ps -a --format json`, `docker stats --no-stream` | **不发送**容器名称、端口映射、环境变量、挂载卷路径、网络设置 |
| 网络 | 接口名称、MTU、是否 UP | `ip -j addr` | **不发送** IP 地址和 MAC 地址，仅接口名和状态 |
| 系统 | 运行时间、Unraid 版本、内核版本 | `uptime`, `/etc/unraid-version`, `uname -r` | 无敏感信息 |

> ⚠️ **隐私说明**: 采集的数据发送到远程 AI API 时会离开内网。所有 IP 地址、容器名称、系统路径、磁盘序列号、Docker 端口映射和环境变量已在采集层剔除。推荐优先使用本地 Ollama 以避免数据外发。

### 1.2 AI 分析

- 采集的状态数据格式化为 JSON，通过 OpenAI Chat Completions API 发送
- API 格式兼容 Ollama、OpenRouter、Groq、vLLM 等
- **AI Prompt 安全要求**: system prompt 中严格限制 AI 输出纯文本 JSON，**禁止输出任何 HTML 标签、Markdown 标记、JavaScript 代码**。所有文本字段（title/description/suggestion）必须为纯文本字符串
- 要求 AI 返回结构化建议，含以下字段：
  - `severity`: `high` / `medium` / `low`
  - `category`: `performance` / `storage` / `security` / `other`
  - `title`: 建议标题（纯文本，禁止 HTML/JS）
  - `description`: 问题描述（纯文本，禁止 HTML/JS）
  - `suggestion`: 具体优化建议（纯文本，禁止 HTML/JS）
- **服务端二次防御**: `query-ai.sh` 在写入 JSON 前对 title/description/suggestion 做 `strip_tags()`（PHP）或 `sed 's/<[^>]*>//g'`（Shell）剥离残余 HTML 标签
- `last-advice.json` 顶层字段包含 `collected_at`（采集时间戳）和 `advice_at`（AI 返回时间戳）
- 历史缓存文件 `advice-YYYY-MM-DD-HHMM.json` 文件体内也包含时间戳字段，文件名仅作排序和辨识用

### 1.3 WebGUI 展示

- **主页面** (`ai-advisor.page`):
  - **默认文件模式**: 采集的 metrics（`last-stats.json`）和 AI 建议（`last-advice.json`）一起打包为 ZIP 文件下载。下载通过专用端点 `download-bundle.php` 实现，后端构造 ZIP 包（内含两个 JSON 文件），通过 `Content-Disposition` header 指定文件名（如 `ai-sage-report-2026-05-11-1748.zip`），**不提供通用文件下载接口**
  - **可选 HTML 模式**: 用户可在设置页开启此模式，主页面分两栏展示：
    - 左栏/上栏：系统状态总览（CPU/内存/磁盘/阵列概要卡片）
    - 右栏/下栏：AI 建议卡片列表（按严重程度排序），每条卡片显示：
  - **可选 HTML 模式**: 用户可在设置页开启此模式，AI 建议渲染为 HTML 卡片列表（按严重程度排序），每条卡片显示：
    - 标题、描述、建议内容
    - 生成时间戳（`advice_at`）
    - 对应的采集时间戳（`collected_at`）
  - 两种模式均展示最近一次采集时间和锁状态
  - HTML 模式下底部显示警告条："AI 建议已安全转义，纯文本渲染"
- **设置页面** (`ai-advisor.settings.page`):
  - 配置 API endpoint / api_key / model
  - 计划任务：采用 Dynamix Scheduler 风格的下拉菜单（Disabled / Hourly / Daily / Weekly / Monthly / Custom）
  - Custom 模式提供自由输入框，提交时做基本格式校验（5 字段、值域在合法范围内）
  - **手动清除锁**按钮 + 当前锁状态指示（锁定中的 PID、进程名、进程是否存活）
  - 清除逻辑：读取锁中 PID → `ps -p $PID -o comm=` 确认进程名含 `ai-sage` → 只有 PID **和** 进程名都匹配时才 `kill` + 删除锁文件 → 否则只写 warning 日志不执行清除
  - 锁被清除时记录日志 `logger -t ai-advisor "lock manually cleared: killed pid $PID (ai-sage)"`
  - **[立即运行] 按钮**: 调用 `advisor-daemon.sh run-once` 模拟一次 cron 触发。执行前检查 daemon.lock（与 cron 锁策略完全一致）：锁存在 + PID 存活 + 进程名含 ai-sage → 跳过并提示"已有一个任务在运行"；否则正常执行一轮采集和分析
  - **[Dry Run] 按钮**: 调用 `advisor-daemon.sh dry-run`。**只采集 metrics 不调用 AI API**，锁检查策略与 run-once 相同。用于验证采集配置和网络连通性，不消耗 AI API 额度

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
- Model 名称（本地 Ollama 默认 `qwen2.5:7b`）— ⚠️ 建议使用可信任的主流模型（Ollama 官方模型、OpenAI、Claude 等），避免使用来源不明的第三方模型，后者可能返回恶意构造的建议内容
- Cron 定时表达式（默认 `0 */6 * * *` 每 6 小时）
- 历史建议保留条数（默认 `30`，设 `0` 表示不保留历史）
- 输出模式: `file`（默认，下载 JSON 文件）/ `html`（在 WebGUI 中渲染卡片。开启时 XSS 防护仍然有效，但用户需知悉网页渲染的残余风险）。后端校验枚举值，仅接受 `file` 或 `html`，非法值回退到 `file`
- 启用/禁用

---

## 2. 架构

```
┌──────────────────────────────────────────────────────────┐
│  WebGUI (PHP .page)                                       │
│  ├─ 主页面: 上次采集 + AI 建议 / metrics 面板          │
│  ├─ download-bundle.php (专用 ZIP 下载, 含 metrics + advice)│
│  └─ 设置页: API/key/model/cron/保留条数/输出模式        │
│       ├─ 输出模式: file(默认下载) / html(可选渲染)      │
│       ├─ 锁状态指示: 锁定中(PID) / 空闲                  │
│       └─ [清除锁] 按钮 → exec(clear-lock.sh)             │
│       └─ [立即运行] 按钮 → exec advisor-daemon.sh run-once│
│       └─ [Dry Run] 按钮 → exec advisor-daemon.sh dry-run  │
├──────────────────────────────────────────────────────────┤
│  后端脚本 (Shell + jq)                                    │
│  ├─ advisor-daemon.sh (锁管理: 获取/释放/检测, run-once 模式)│
│  ├─ collect-stats.sh → JSON (CPU/内存/磁盘/Docker)       │
│  ├─ query-ai.sh → POST AI API → 含时间戳的建议           │
│  └─ clear-lock.sh (设置页调用, 匹配才 kill, 不匹配也删锁)│
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

- `event/started`: 阵列启动完成 → 执行启动冲突检测（目录名 + 进程名）→ 无冲突则启动 advisor-daemon.sh

### 数据流

```
cron 触发 / run-once / dry-run
  │
  ├─ advisor-daemon.sh 检查 daemon.lock
  │   ├─ 锁存在 + PID 存活 + 进程名含 ai-sage → 跳过本轮（防重叠）
  │   └─ 锁不存在 / PID 已死 / 名不匹配 → 写锁 → 继续
  │
  ├─ timeout 30 collect-stats.sh --JSON--> last-stats.json (原子写入)
  │
  ├─ [dry-run] → 跳过 AI 调用，直接释放锁
  │
  ├─ [run-once / cron] timeout 120 query-ai.sh --POST--> AI API
  │                                         → last-advice.json (含时间戳, 原子写入)
  │                                         + data/advice-*.json (历史存档, 含时间戳)
  │                                         + timeout 10 清理淘汰旧记录
  │
  ├─ 总护闸: 超时 180s 强制释放锁
  │
  └─ 释放锁 (删除 daemon.lock)

手动清除 (clear-lock.sh):
  1. 读 daemon.lock → PID, 锁不存在则退出
  2. ps -p $PID -o comm= → 进程名
  3. PID 存活 + 名含 ai-sage → kill + 删锁 + 记日志
  4. PID 存活 + 名不匹配   → 只删锁，不 kill
  5. PID 已死              → 只删锁

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

- **认证边界**: Unraid WebGUI 本身已有登录认证。所有 `.page` 文件运行在已认证的 HTTP 上下文中，未登录用户无法访问任何插件页面。Unraid 没有内置的 `is_admin()` 函数——WebGUI 的登录即等价于 admin 权限。表单提交（`exec()` 调用后台脚本）继承了同一个认证上下文。
- API Key 明文存储到 `/boot/config/plugins/ai-advisor/ai-advisor.cfg`（Unraid 插件标准做法）
- 采集脚本不写入源文件系统，只输出到 `/tmp/`（RAM）
- 历史建议缓存写入持久存储（`/boot/config/plugins/ai-advisor/data/`），每次新写入后清理淘汰旧记录，限制 N 条内
- 清理仅在添加新记录时触发，不产生额外独立写入周期
- **原子写入**: `last-stats.json` 和 `last-advice.json`（运行时文件，供 WebGUI 实时读取）必须使用临时文件 + mv 的方式写入 — 先写 `.tmp` 后缀文件，完成后 `mv` 覆盖目标文件，确保 WebGUI PHP 不会读到半截写的中间状态
- **AI 建议防 XSS**（三道防线）:
  1. **AI Prompt 层**: system prompt 明确禁止输出 HTML/JS，规定纯文本返回
  2. **服务端剥离层**: `query-ai.sh` 写入 JSON 前用 `sed 's/<[^>]*>//g'` 剥离所有 HTML 标签；PHP 输出时再做 `htmlspecialchars()` 双保险
  3. **前端渲染层**: JS 用 `textContent`（而非 `innerHTML`）渲染建议内容
- **输出模式安全策略**: 默认输出模式为 `file`（下载 JSON），用户主动切换到 `html` 模式时才在 WebGUI 渲染卡片。即使 HTML 模式也强制使用 `textContent` + `htmlspecialchars()` + `sed` 剥离的三道防线，不因模式切换而跳过任何安全步骤
- **配置文件同样原子写入**: `ai-advisor.cfg` 也使用 `.tmp + mv` 模式写入，避免 PHP 写入中断导致 `parse_ini_file()` 解析失败
- 历史缓存文件 `advice-*.json` 则直接写入，因为 WebGUI 不实时读取单个文件，而是通过 `ls -t | head -N` 读取列表
- 插件卸载时清理 `/tmp/ai-advisor/`、`/boot/config/plugins/ai-advisor/` 和 `/usr/local/emhttp/plugins/ai-advisor/`

### 3.4 下载接口安全设计

**专用端点** `download-bundle.php`:
- 读取 `last-stats.json` 和 `last-advice.json`，打包为 ZIP
- ZIP 包内含两个文件：
  - `metrics.json` — 采集的系统状态数据
  - `advice.json` — AI 优化建议（如存在）
- 设置 `Content-Type: application/zip` 和 `Content-Disposition: attachment; filename="ai-sage-report-{timestamp}.zip"`
- 文件名中的时间戳从 `last-stats.json` 的 `collected_at` 字段提取，不依赖用户输入
- 打包使用 PHP 内置的 `ZipArchive` 类，确保关闭句柄后 exit
- 两个文件都不存在时返回 404，不生成空 ZIP

**安全约束**（不提供通用下载接口）:
- 禁止 `download.php?file=xxx` 或 `download.php?path=xxx` 模式
- 禁止读取 `last-*.json` 之外的任何文件
- 禁止接受用户传入的文件路径或名称参数
- PHP 层面的过滤：即使收到 `file` 或 `path` 参数也忽略

### 3.5 文件锁机制（防重复执行）

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
- **手动触发** (`advisor-daemon.sh run-once`): 支持 `run-once` 参数，执行与 cron 相同的锁检查逻辑，获取锁后运行一轮完整的采集→AI→保存流程，流程结束后释放锁。用于设置页"立即运行"按钮

**执行超时保护**（防止命令阻塞整个循环）:
- 每个子步骤必须设置超时，使用 `timeout` 命令
  - `collect-stats.sh`: 30 秒超时
  - `query-ai.sh`: 120 秒超时（AI API 可能慢）
  - 历史清理: 10 秒超时
- 整个 cron 循环的总护闸: 180 秒后强制释放锁（避免锁永久残留）
- 超时后的步骤标记为"跳过"，不清除已成功的前序步骤结果

**手动清除锁（clear-lock.sh）**:
1. 读取 `daemon.lock` 中的 PID
2. 锁文件不存在 → `logger -t ai-advisor "clear-lock: no lock file"` → 退出
3. 锁存在 → 执行 `ps -p $PID -o comm=` 获取进程名
4. **PID 存活 + 进程名包含 ai-sage** → `kill -TERM $PID` → 等待 3 秒 → 检查进程是否仍存活 → 存活则 `kill -KILL $PID` → 删除 `daemon.lock` → 记日志
5. **PID 存活但进程名不含 ai-sage** → 只删除 `daemon.lock`，不 kill → `logger -t ai-advisor "clear-lock: removed stale lock, pid $PID belongs to $actual_name, not killed"`
6. **PID 已死** → 直接删除 `daemon.lock` → 记日志

**异常处理**:
- 如果 daemon 在持有锁时崩溃（kill -9、系统重启等），锁文件残留含已死 PID
- 下次 cron 触发时，检测到 PID 不存活或名不匹配，自动覆盖锁继续执行（自愈）
- 极端情况用户通过设置页手动清除（含双重验证）

### 3.6 启动冲突检测

`event/started` 启动 daemon 前执行以下检查：

**目录名冲突**:
- 检查 `/boot/config/plugins/` 下是否存在其他以 `ai-advisor` 命名的目录（如 `ai-advisor-xxx` 或 `ai-advisor_backup`）
- 检查 `/usr/local/emhttp/plugins/` 下本插件目录是否被其他插件占用
- 发现冲突时写 error 日志 `logger -t ai-advisor "startup conflict: directory collision detected ($conflict_path)"`，阻止 daemon 启动

**进程名冲突**:
- 执行 `ps aux | grep ai-sage | grep -v grep` 检查是否有同名进程已在运行
- 如果存在且进程来自不同的 PID 命名空间（非本插件之前残留的锁），写 warning 日志并等待 5 秒后重试
- 重试 3 次后仍冲突，退出静默（由 cron 触发时自动恢复）

---

## 4. 目录结构

```
unraid-sage/
├── Dockerfile.test              # 测试镜像构建文件
├── docker-compose-test.yml      # 测试编排（mock API + test runner）
├── source/                      # 插件源文件目录
│   ├── default.cfg              #   默认配置
│   ├── scripts/
│   │   ├── collect-stats.sh     #   系统状态采集
│   │   ├── query-ai.sh          #   AI API 调用
│   │   ├── advisor-daemon.sh    #   后台守护进程（含锁管理）
│   │   └── clear-lock.sh        #   手动清除锁（设置页调用）
│   ├── event/
│   │   └── started              #   阵列启动钩子
│   ├── pages/
│   │   ├── ai-advisor.page          #   WebGUI 主页面（metrics 面板 + AI 建议）
│   │   ├── download-bundle.php      #   专用 ZIP 下载端点（metrics + advice）
│   │   └── ai-advisor.settings.page #   设置页面（含 Dry Run 按钮）
│   ├── javascript/
│   │   └── advisor.js           #   WebGUI JS
│   └── styles/
│       └── advisor.css          #   WebGUI CSS
├── tests/                       # 测试脚本
│   ├── mock/                    # Unraid 命令 + AI API Mock
│   │   ├── api-server.php       #   Mock AI API (PHP 内置服务器)
│   │   ├── docker               #   PATH 劫持脚本
│   │   ├── docker-ps.json       #   docker ps 模拟输出
│   │   ├── docker-stats.json    #   docker stats 模拟输出
│   │   ├── smartctl             #   SMART 数据 Mock
│   │   └── var.ini              #   阵列状态 Mock
│   ├── test_collect_stats.sh   #   采集脚本测试
│   ├── test_query_ai.sh        #   AI 接口测试
│   ├── test_daemon.sh          #   守护进程测试
│   ├── test_atomic_write.sh    #   原子写入测试
│   ├── test_lock.sh            #   文件锁测试
│   ├── test_clear_lock.sh      #   手动清除锁测试
│   ├── test_timeout.sh         #   超时保护测试
│   ├── test_advice_timestamp.sh#   建议时间戳测试
│   ├── test_xss_prevention.sh  #   XSS 防护测试
│   ├── test_desensitization.sh #   数据脱敏测试
│   └── test_cleanup.sh         #   缓存清理测试
├── .agent/                     # AI 辅助指令
│   ├── AI_AGENT_COMMON_INSTRUCTIONS.md  # 本文件
│   ├── SECURITY.md             #   安全总览
│   └── SECURITY_AUDIT.md       #   安全审计报告
├── .workbuddy/
│   └── CODEBUDDY.md            #   CodeBuddy 入口（引用 .agent/）
└── .github/
    └── copilot-instructions.md  #   Copilot 入口（引用 .agent/）
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
- **HTML 剥离**: `query-ai.sh` 处理 AI 返回的文本字段时，必须用 `sed 's/<[^>]*>//g'` 剥离所有 HTML 标签，之后再写入 JSON
- **日志**: 统一使用 `logger -t ai-advisor "message"` 写入系统日志

### 5.2 PHP (.page)

- **风格**: 遵循 Unraid WebGUI 的 PHP + HTML 混合风格
- **无外部 PHP 框架**: 只用 Unraid 内置的 PHP 函数
- **安全性**: 所有用户输入通过 `htmlspecialchars()` 输出。AI 建议内容视为不可信输入：PHP 输出前做 `strip_tags()` + `htmlspecialchars()` 双重处理
- **配置读取**: 通过 `parse_ini_file()` 读取 `.cfg` 文件
- **配置写入**: 先写 `.tmp` 再 `mv` 覆盖原文件，与 Shell 端原子写规则一致
- **配置校验**: 所有枚举值配置（如 OUTPUT_MODE）在写入前做严格校验，仅接受白名单内的值，非法值回退到安全默认值
- **表单处理**: 提交后 `exec()` 调用固定路径的 update-config.sh 脚本，不拼接用户输入
- **CSRF 防护**: 所有表单必须包含 `<input type="hidden" name="csrf_token" value="<?= $var['csrf_token'] ?>">`，Unraid emhttp 服务端自动校验

### 5.3 JavaScript

- **使用 jQuery**: Unraid WebGUI 内置 jQuery
- **AJAX 请求**: 通过 `$.get()` 或 `$.post()` 读取 JSON 数据
- **DOM 操作**: 仅操作插件域内的元素（`.ai-advisor-*` 前缀）
- **防 XSS**: 渲染 AI 建议内容时使用 `textContent` 而非 `innerHTML`，禁止将 AI 输出直接插入 HTML

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
| `test_xss_prevention.sh` | AI 建议中的 HTML 标签被正确剥离 | 注入 `<script>alert(1)</script>`，验证输出为空文本 |
| 同测试 | 正常文本中的 `<` 和 `>` 被正确保留 | 验证 `5 < 10` 不被误删 |
| 同测试 | PHP `htmlspecialchars()` 双编码测试 | 验证 `&` 不被二次转义 |
| 同测试 | HTML 模式下 XSS 防线不退化 | file/html 模式切换后测试同上 |
| `test_desensitization.sh` | 发送给 AI 的 JSON 不含容器名称 | `jq '..|.name? // empty'` 应只有镜像名 |
| 同测试 | 发送给 AI 的 JSON 不含 IP 地址 | `jq '..|strings' | grep -vE '^\d+\.\d+'` |
| `test_config_validation.sh` | OUTPUT_MODE 设为非法值（如 `xxx`）自动回退 `file` | 验证配置写入后读取仍为 `file` |
| 同测试 | OUTPUT_MODE 设为合法值 `html` 正常写入 | 验证配置读取为 `html` |
| `test_download.sh` | download-bundle.php 返回合法 ZIP | 验证 HTTP 200 + Content-Type application/zip |
| 同测试 | ZIP 内含 metrics.json 和 advice.json | `unzip -l` 验证文件列表 |
| 同测试 | 响应头含 Content-Disposition attachment | 验证 `grep -i attachment` |
| 同测试 | 文件名包含时间戳 | 验证 filename 匹配 `ai-sage-report-` 前缀 |
| 同测试 | 两个数据文件都不存在时返回 404 | 清空运行时文件后验证 404 |
| 同测试 | 传入 file=xxx 参数时被忽略 | 验证仍返回正确 ZIP |
| `test_atomic_write.sh` | 写入期间读取不会拿到半截数据 | 后台写大 JSON + 前台持续 `jq .` 不报错 |
| 同测试 | 写入完成后 .tmp 后缀文件已清除 | `ls /tmp/ai-advisor/*.tmp` 应为空 |
| 同测试 | mv 覆盖后目标文件 mtime 更新 | 验证时间戳正确 |
| `test_daemon.sh` | daemon start/stop/status 正常 | 验证 PID 文件 |
| 同测试 | daemon 不重复启动 | 二次 start 应返回已有 PID |
| 同测试 | daemon run-once 执行完整一轮 | 验证 last-stats.json 和 last-advice.json 更新 |
| 同测试 | run-once 被锁拦截 | 伪造锁 + 存活 PID，验证 run-once 跳过 |
| `test_dry_run.sh` | dry-run 只采集 metrics 不调 AI | 验证 last-stats.json 更新但 last-advice.json 不变 |
| 同测试 | dry-run 锁检查与 cron 一致 | 验证锁定状态时跳过 |
| `test_startup_conflict.sh` | 启动时检测目录名冲突 | 伪造冲突目录，验证 daemon 不启动 + 写 error 日志 |
| 同测试 | 启动时检测进程名冲突 | 启动同名进程，验证 daemon 等待重试后退出 |
| 同测试 | 无冲突时正常启动 | 验证 daemon 正常运行 |
| `test_lock.sh` | 锁文件存在且 PID 存活时跳过执行 | 伪造锁文件 + 存活的 PID(cron)，验证跳过 |
| 同测试 | 锁文件存在但 PID 已死时自动覆盖 | 伪造锁文件 + 已死的 PID，验证重新执行 |
| 同测试 | PID 存活但进程名不含 ai-sage 时自动覆盖 | 伪造锁文件 + 非 ai-sage 进程，验证重新执行 |
| 同测试 | 锁文件被手动清除后能正常执行 | 删除锁文件后验证 cron 可正常触发 |
| `test_clear_lock.sh` | clear-lock.sh: PID+名都匹配 → kill + 删锁 | 启动 ai-sage 进程，验证清除成功 |
| 同测试 | clear-lock.sh: PID 存活但名不匹配 → 只删锁不 kill | 启动非 ai-sage 进程，验证删锁但进程存活 |
| 同测试 | clear-lock.sh: PID 已死 → 只删锁 | 伪造含已死 PID 的锁文件，验证删锁成功 |
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
| 手动触发 | 点击"立即运行"，锁空闲时正常运行并被锁拦截时提示 |
| Dry Run | 点击"Dry Run"，只采集 metrics 不调 AI，锁空闲/锁定两种状态 |
| 启动冲突 | 存在冲突目录/进程时 daemon 不启动，无冲突时正常 |
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
| 锁被用户手动清除（PID 名不匹配） | clear-lock.sh 只删锁不 kill，记 warning 日志 |
| collect-stats.sh 卡死 | timeout 30s 截断，继续后续步骤（跳过已失败） |
| query-ai.sh 卡死 | timeout 120s 截断，释放锁，保留上次建议 |
| Docker 未运行 | `collect-stats.sh` 跳过 Docker 部分，正常输出其他指标 |
| 磁盘未挂载 | `collect-stats.sh` 跳过对应磁盘，不报错退出 |
| API Key 为空且 endpoint 非本地 | 设置页提示警告 |
| cron 表达式格式非法 | daemon 使用默认值，写 warning 日志 |
| `jq` 不可用 | 脚本检测依赖，输出友好错误 |
| 启动时目录名冲突 | error 日志，daemon 不启动 |
| 启动时进程名冲突 | warning 日志，等待重试 3 次后静默退出 |

---

### 6.4 安全测试环境（Docker 沙盒）

#### 可行性分析

| 测试类型 | 能否在 Docker 中运行 | 说明 |
|---------|-------------------|------|
| XSS 剥离、脱敏、原子写入、锁机制、超时、清理 | ✅ 完全可测 | 纯 Shell/PHP 逻辑，不依赖 Unraid |
| config 校验、download-bundle | ✅ 完全可测 | 需要 PHP 环境 |
| collect-stats（CPU/内存/网络/系统） | ✅ 可测 | Linux 容器有 /proc、/sys，数据真实 |
| collect-stats（Docker 部分） | ✅ 可测 | PATH 劫持 mock docker 命令 |
| collect-stats（阵列/磁盘） | ✅ 可测 | mock var.ini + mock smartctl |
| query-ai （AI API 调用） | ✅ 可测 | mock-api 容器模拟 OpenAI 端点 |
| daemon 启动、锁冲突、进程冲突 | ✅ 完全可测 | 进程管理和文件操作在任何 Linux 上都一致 |
| .plg 安装、WebGUI 集成 | ❌ 无法测 | 需要实际的 Unraid emhttp 环境 |

#### 推荐方案：Docker 沙盒 + Mock API + PATH 劫持

测试依赖（bash、curl、jq、php-cli 等）提前写入 `Dockerfile.test` 并构建成镜像。`docker compose` 启动两个服务：

```
mock-api 容器                    test-runner 容器
┌─────────────────────┐          ┌─────────────────────┐
│  php -S :11434       │          │  bash test_*.sh     │
│  /mock/api-server.php│◀────────│  curl POST           │
│                      │  mock    │  /v1/chat/completions│
│  /health             │  API    │                      │
│                      │          │  SAGE_API_ENDPOINT= │
│  返回预设的 AI 建议  │          │  http://mock-api:   │
│  (3 条, 含 perf/     │          │  11434/v1/chat/     │
│   storage/security)  │          │  completions         │
└─────────────────────┘          └─────────────────────┘
```

```yaml
# docker-compose-test.yml
services:
  mock-api:
    build: .
    command: php -S 0.0.0.0:11434 -t /mock /mock/api-server.php

  test-runner:
    build: .
    depends_on: [mock-api]
    environment:
      - SAGE_API_ENDPOINT=http://mock-api:11434/v1/chat/completions
      - SAGE_TEST_ENV=1
```

**使用方式**:
```bash
docker compose -f docker-compose-test.yml up --build
# mock-api 先启动，test-runner 等待健康检查通过后执行测试
```

**Mock API 行为**:
- 接收 POST `/v1/chat/completions`，返回预设的 3 条建议（high/performance、medium/storage、low/security）
- 暴露 GET `/health` 端点用于健康检查
- 监听 11434 端口，与默认的 Ollama 端口一致
- `query-ai.sh` 通过 `SAGE_API_ENDPOINT` 环境变量切换端点，无需改代码

**Mock 原理**（以 `docker` 为例）：

```
collect-stats.sh 调用:
  docker ps -a --format json
       ↓
  系统查找 PATH:
  /mock/docker  → 找到! 执行 mock
       ↓
  mock/docker 解析参数:
  "ps -a --format json" → 返回 mock/docker-ps.json
  "stats --no-stream"   → 返回 mock/docker-stats.json
```

**已实现的 Mock 文件**:
```
tests/mock/
├── docker              # PATH 劫持脚本，根据参数返回不同 mock 数据
├── docker-ps.json      # docker ps --format json 的模拟输出（2 个容器）
├── docker-stats.json   # docker stats --no-stream 的模拟输出
├── smartctl            # PATH 劫持脚本，返回模拟 SMART 健康数据
└── var.ini             # 模拟 /var/local/emhttp/var.ini（4 盘阵列 + 缓存）
```

#### 测试流程

```
开发者: git push → GitHub Actions / make test
                        ↓
              启动 Docker 容器
         (alpine + bash/curl/jq/php)
                        ↓
          挂载 source/scripts (ro)
          挂载 tests/         (ro)
          挂载 tests/mock     (ro)
                        ↓
              逐个执行 test_*.sh
                        ↓
              输出测试报告 PASS/FAIL
```

**本地运行**:
```bash
docker compose -f docker-compose-test.yml up --abort-on-container-exit
```

**只跑安全测试**:
```bash
docker compose -f docker-compose-test.yml run --rm test-runner sh -c "
  for t in /tests/test_xss_prevention.sh /tests/test_desensitization.sh \
           /tests/test_lock.sh /tests/test_clear_lock.sh \
           /tests/test_timeout.sh /tests/test_atomic_write.sh \
           /tests/test_config_validation.sh; do
    bash \$t || exit 1
  done
"
```

#### Docker 可测的安全测试清单

| 测试 | 依赖 |
|------|------|
| XSS 剥离、正常文本保留、双编码 | bash, sed |
| 脱敏字段过滤 | bash, jq |
| 原子写入：防半截读、.tmp 残留、mtime | bash, coreutils |
| 锁跳过、PID 覆盖、名不匹配覆盖 | bash, procps |
| clear-lock 四种场景 | bash, procps, coreutils |
| 超时截断、锁释放 | bash, coreutils (timeout) |
| 配置枚举校验 | bash, php-cli |
| 下载 ZIP 打包、Content-Disposition、404 | php-cli, php-zip, curl |
| daemon run-once / dry-run | bash, procps |
| 启动冲突检测 | bash, procps |

**不可测的部分**（需在真实 Unraid 上验收）:
- `.plg` 安装流程
- WebGUI PHP `.page` 与 emhttp 集成（CSRF、会话认证）
- 真实 Docker 守护进程通信
- 阵列/磁盘 SMART 数据采集

## 7. 验收标准

- [ ] 插件安装后，无需手动干预，daemon 随系统启动自动运行
- [ ] 每次 cron 触发后，`/tmp/ai-advisor/last-stats.json` 正确更新
- [ ] `last-stats.json` 包含 CPU/内存/磁盘/阵列/Docker/网络/系统 全部指标
- [ ] AI 分析后，`/tmp/ai-advisor/last-advice.json` 包含合法建议，含 `collected_at` 和 `advice_at` 时间戳
- [ ] AI 建议中的 `title`/`description`/`suggestion` 经过 HTML 标签剥离 + 转义，不会因 AI 返回内容触发 XSS
- [ ] AI Prompt 中明确要求纯文本 JSON 输出、禁止 HTML/JS
- [ ] 默认输出模式为 `file`（下载 JSON），切换到 `html` 后 WebGUI 渲染卡片
- [ ] HTML 模式下 XSS 防线与 file 模式一致，不因模式切换退化
- [ ] OUTPUT_MODE 后端校验枚举值，非法值自动回退 `file`
- [ ] 下载接口为专用端点 `download-advice.php`，无通用文件下载接口
- [ ] 下载时 Content-Disposition 指定文件名，不可通过参数篡改路径
- [ ] 设置页"立即运行"按钮使用与 cron 相同的锁策略，锁定状态跳过并提示
- [ ] 设置页"Dry Run"按钮只采集 metrics 不调 AI，锁策略与 cron 一致
- [ ] HTML 模式下主页面分 metrics 面板和 AI 建议两栏展示
- [ ] 下载接口打包为 ZIP 文件（内含 metrics.json + advice.json），文件名为 `ai-sage-report-{timestamp}.zip`
- [ ] 启动时检测目录名冲突和进程名冲突，存在冲突时阻止 daemon 启动
- [ ] 发送给 AI 的数据不含容器名称、系统路径、IP 地址
- [ ] 写入 `last-*.json` 时使用临时文件 + mv 原子写入，WebGUI 不会读到半截 JSON
- [ ] 写入 `last-*.json` 时使用临时文件 + mv 原子写入，WebGUI 不会读到半截 JSON
- [ ] 写入完成后 `/tmp/ai-advisor/` 下无 `.tmp` 残留文件
- [ ] cron 重叠触发时自动跳过，不并发执行
- [ ] daemon 崩溃后锁残留，下次 cron 自动恢复（检测 PID 已死或名不匹配 → 覆盖锁）
- [ ] 设置页可查看锁状态（PID / 进程名 / 是否存活），支持手动清除锁
- [ ] 清除锁时 PID+名匹配 → kill 进程 + 删锁；名不匹配/PID 已死 → 仅删锁不清进程
- [ ] 每个子步骤有超时保护（collect 30s / query 120s / cleanup 10s），超过即跳过
- [ ] daemon 进程名包含 ai-sage 标识，可通过 `ps` 识别
- [ ] WebGUI 主页面正确显示采集时间和 AI 建议卡片，每条建议带时间戳
- [ ] 设置页面所有配置项保存后生效，CSRF Token 缺失时请求被拒绝
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
