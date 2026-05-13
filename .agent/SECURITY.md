# Unraid Sage — 安全总览

本文档系统梳理项目所有安全措施，按领域分类。每个措施标注所在文档位置、风险等级和处理状态。

---

## 一、认证与授权

| # | 措施 | 来源 | 状态 |
|---|------|------|------|
| A1 | WebGUI 登录认证保护 — 未登录用户无法访问任何 .page 文件和表单接口 | §3.3 | ✅ 设计决定 |
| A2 | CSRF Token 保护 — 设置页每个表单必须包含 `<input type="hidden" name="csrf_token" value="<?= $var['csrf_token'] ?>">`，Unraid emhttp 服务端自动校验，缺失或不匹配时拒绝请求 | §5.2 | ✅ Unraid 内置 |
| A3 | PHP exec() 固定脚本路径，不拼接用户输入 — 配置值通过 update-config.sh 内部读取 | §5.2 | ✅ 已实现 |
| A4 | Shell 脚本内部对读取的配置值做合法性校验，防止 eval/exec 注入 | §5.2 | ✅ 已实现 |

**已接受风险**:
- API Key 明文存储到 `/boot/config/plugins/ai-advisor/ai-advisor.cfg`（Unraid 生态共同问题，推荐用户使用本地 Ollama 避免 Key 泄露）
- API Endpoint 无 URL 校验（设计需求，WebGUI 仅 admin 可访问）

---

## 二、数据隐私（采集脱敏）

发送给 AI API 的数据必须做最小化处理。以下字段在采集层直接剔除：

| 类别 | 发送给 AI 的内容 | 已剔除的敏感信息 |
|------|-----------------|-----------------|
| CPU | load average、核心数、温度 | —（无敏感信息） |
| 内存 | total / used / available / swap | —（无敏感信息） |
| 磁盘 | 每块盘使用率、文件系统、总容量 | 挂载点路径、UUID、序列号 |
| 阵列 | 总/已用/可用容量、校验盘类型、缓存池配置 | 磁盘序列号 |
| Docker | 容器总数、运行数/停止数、镜像名、CPU/内存占用 | **容器名称**、端口映射、环境变量、挂载卷路径、网络设置 |
| 网络 | 接口名称、MTU、是否 UP | **IP 地址**、MAC 地址、流量 |
| 系统 | 运行时间、Unraid 版本、内核版本 | —（无敏感信息） |

**用户提示**: 设置页标注 ⚠️ 建议使用可信任的主流模型（Ollama 官方模型、OpenAI、Claude 等），避免使用来源不明的第三方模型。

来源: §1.1, §1.5 | 状态: ✅ 已实现

---

## 三、XSS 防护（AI 建议防 XSS）

三层纵深防御，所有模式（file / html）强制生效：

```
层 1: AI Prompt
  └─ system prompt 明确禁止输出 HTML/JS/Markdown，规定纯文本 JSON
  来源: §1.2 | 状态: ✅ 已实现

层 2: 服务端剥离
  ├─ query-ai.sh: sed 's/<[^>]*>//g' 剥离所有 HTML 标签后写入 JSON
  └─ PHP: strip_tags() + htmlspecialchars() 输出前双重处理
  来源: §1.2, §5.2 | 状态: ✅ 已实现

层 3: 前端渲染
  └─ JS 使用 textContent（非 innerHTML），禁止 AI 输出直接插入 HTML
  来源: §5.3 | 状态: ✅ 已实现
```

**输出模式策略**: 默认 `file`（下载 JSON，零渲染风险），用户主动切换 `html` 后三道防线不退化。

来源: §3.3 | 状态: ✅ 已实现

---

## 四、文件操作安全

### 4.1 原子写入

运行时 JSON 文件和配置文件禁止直接 overwrite，必须使用 `.tmp + mv` 模式：

| 文件 | 写入方式 | 原因 |
|------|---------|------|
| `last-stats.json` | .tmp → mv | WebGUI 实时读取，防止读到半截 JSON |
| `last-advice.json` | .tmp → mv | WebGUI 实时读取，防止读到半截 JSON |
| `ai-advisor.cfg` | .tmp → mv | 防止写入中断导致 `parse_ini_file()` 解析失败 |
| `advice-*.json` 历史缓存 | 直接写入 | WebGUI 通过 `ls -t` 读取列表，不读单个文件内容 |

来源: §3.3, §5.1, §5.2 | 状态: ✅ 已实现

### 4.2 下载接口安全

**专用端点** `download-bundle.php`:
- 只读取 `last-stats.json` 和 `last-advice.json`，打包为 ZIP
- `Content-Disposition: attachment; filename="ai-sage-report-{timestamp}.zip"`
- 文件名时间戳从数据内部提取，不依赖用户输入
- 两文件都不存在时返回 404，不生成空 ZIP

**禁止的通用下载模式**:
- ❌ `download.php?file=xxx` / `download.php?path=xxx`
- ❌ 接受任何 `file` / `path` 参数（即使收到也忽略）
- ❌ 读取 `last-*.json` 之外的任何文件

来源: §3.4 | 状态: ✅ 已实现

### 4.3 临时文件清理

- 运行时文件统一写到 `/tmp/ai-advisor/`，用完清理
- 原子写入的 `.tmp` 文件在 `mv` 后不再残留
- 插件卸载时清理 `/tmp/ai-advisor/`

来源: §5.1, §3.3 | 状态: ✅ 已实现

---

## 五、锁与并发控制

### 5.1 文件锁机制

| 属性 | 设计 |
|------|------|
| 锁文件 | `/tmp/ai-advisor/daemon.lock` |
| 锁内容 | 持有锁的进程 PID |
| 进程标识 | 所有 daemon 进程名含 `ai-sage`（`exec -a ai-sage-xxx`） |
| 获取锁 | 检查存在 + PID 存活 + 进程名匹配 ai-sage |
| 释放锁 | 执行完成或超时后删除 |

**锁状态机**:
```
检查 daemon.lock
  ├─ 不存在 → 写锁（本进程 PID） → 执行
  ├─ 存在 + PID 存活 + 名匹配 → 跳过本轮（防重叠）
  └─ 存在 + PID 已死/名不匹配 → 覆盖锁 → 执行（自愈）
```

来源: §3.5 | 状态: ✅ 已实现

### 5.2 手动清除锁（clear-lock.sh）

```
读 daemon.lock → PID
  ├─ 锁不存在 → 退出
  ├─ PID 存活 + 进程名含 ai-sage → kill -TERM → 3s 等待
  │                                   └─ 仍存活 → kill -KILL
  │                                → 删锁 + 记日志
  ├─ PID 存活 + 名不匹配 → 只删锁不 kill + 记 warning
  └─ PID 已死 → 只删锁 + 记日志
```

**双重验证**: 仅当 PID 和进程名都匹配时才 `kill`，防止误杀其他进程。

来源: §1.3, §3.5 | 状态: ✅ 已实现

### 5.3 超时保护

| 步骤 | 超时 |
|------|------|
| collect-stats.sh | 30s |
| query-ai.sh | 120s |
| 历史清理 | 10s |
| 整个循环总护闸 | 180s（强制释放锁） |

来源: §3.5 | 状态: ✅ 已实现

### 5.4 Dry Run 模式

`advisor-daemon.sh dry-run` — 与 cron 相同锁检查逻辑，只采集 metrics 不调 AI。

来源: §1.3 | 状态: ✅ 已实现

---

## 六、启动安全

### 6.1 目录名冲突检测

`event/started` 中检查:
- `/boot/config/plugins/` 下是否存在其他 `ai-advisor-*` 目录
- `/usr/local/emhttp/plugins/` 下本插件目录是否被占用
- 冲突 → error 日志，阻止 daemon 启动

### 6.2 进程名冲突检测

`ps aux | grep ai-sage` 检查已有进程:
- 存在同名进程 → warning 日志，5s 重试 × 3
- 仍冲突 → 静默退出（由 cron 触发时自动恢复）

来源: §3.6 | 状态: ✅ 已实现

---

## 七、配置安全

| # | 措施 | 来源 | 状态 |
|---|------|------|------|
| C1 | 枚举值校验 — OUTPUT_MODE 仅接受 `file`/`html`，非法值回退 `file` | §1.5, §5.2 | ✅ 已实现 |
| C2 | Cron 表达式校验 — 预设下拉菜单（Dynamix Scheduler 风格）+ Custom 格式校验 | §1.3 | ✅ 已实现 |
| C3 | 配置文件原子写入 — 防止写中断导致配置损坏 | §3.3, §5.2 | ✅ 已实现 |
| C4 | Model 名称提示 — 建议使用可信任主流模型 | §1.5 | ✅ 已实现 |

---

## 八、卸载清理

插件卸载时清理以下路径：

| 路径 | 说明 |
|------|------|
| `/tmp/ai-advisor/` | 运行时数据（RAM） |
| `/boot/config/plugins/ai-advisor/` | 配置和持久数据（闪存/SSD） |
| `/usr/local/emhttp/plugins/ai-advisor/` | 插件运行时代码（RAM） |

来源: §3.3 | 状态: ✅ 已实现

---

## 九、安全测试覆盖

| 测试文件 | 覆盖的安全领域 |
|---------|---------------|
| `test_xss_prevention.sh` | XSS 三层防线、HTML 模式不退化、正常文本保留 |
| `test_desensitization.sh` | 数据脱敏：无容器名、无 IP 地址 |
| `test_atomic_write.sh` | 原子写入：半截读保护、.tmp 无残留、mtime 正确 |
| `test_lock.sh` | 锁冲突跳过、PID 已死覆盖、名不匹配覆盖、手动清除后恢复 |
| `test_clear_lock.sh` | 双重验证：匹配 kill + 删锁、名不匹配仅删锁、PID 已死仅删锁、无锁退出 |
| `test_timeout.sh` | 超时保护：命令截断、锁仍释放 |
| `test_config_validation.sh` | 枚举校验：非法值回退、合法值正常写入 |
| `test_download.sh` | 下载安全：ZIP 格式、文件名固定、参数忽略、404 处理 |
| `test_dry_run.sh` | 安全模式：不调 AI、锁检查一致 |
| `test_startup_conflict.sh` | 启动安全：目录冲突阻塞、进程冲突重试、无冲突正常 |

---

## 附录：风险登记册

| ID | 领域 | 风险描述 | 等级 | 状态 |
|----|------|---------|------|------|
| RISK-01 | 凭据 | API Key 明文存储于配置文件 | 中 | 接受 |
| RISK-02 | 网络 | AI API 端点可指向任意地址（SSRF 由 WebGUI 认证控制） | 低 | 接受 |
| RISK-03 | 系统 | `/tmp/ai-advisor/` 目录权限不可控（多 UID 场景） | 低 | 接受 |
| RISK-04 | 日志 | 日志中可能包含未经裁剪的命令输出 | 低 | 接受 |
| RISK-05 | 传输 | curl 未显式指定证书验证（依赖系统 CA） | 低 | 接受 |
