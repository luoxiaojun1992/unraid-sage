# Unraid Sage — 安全审计报告

审计日期: 2026-05-11
审计范围: `.agent/AGENT_INSTRUCTION.md` 全部设计规范
审计角色: SecurityEngineer

---

## 风险分级

| 等级 | 定义 | 数量 |
|------|------|------|
| P0 | 可被远程/本地攻击者利用的漏洞 | 3 |
| P1 | 敏感数据泄露或权限提升风险 | 4 |
| P2 | 防御性编码缺失，可被利用但攻击面有限 | 5 |

---

## P0 — 必须修复

### P0-1: PHP exec() 存在命令注入风险

**位置**: §5.2 "表单处理: 提交后 `exec()` 调用后台脚本更新配置"
**危害**: API Endpoint URL、Cron 表达式、Model 名称等用户输入直接拼接到 shell 命令中。攻击者可通过精心构造的输入执行任意命令。
**攻击面**: 网络 → WebGUI 设置页 → root 权限命令执行

**修复建议**:
1. `exec()` 的所有参数必须经过 `escapeshellarg()` 转义
2. 不要将用户输入直接拼接到命令字符串中，改用环境变量传递：
   ```php
   // ❌ 危险
   exec("/usr/local/emhttp/plugins/ai-advisor/scripts/update-config.sh " . $_POST['api_endpoint']);

   // ✅ 安全
   putenv("SAGE_API_ENDPOINT=" . escapeshellarg($_POST['api_endpoint']));
   exec("/usr/local/emhttp/plugins/ai-advisor/scripts/update-config.sh");
   ```
3. 配置写入由 Shell 脚本从环境变量读取，脚本内部也做参数校验

### P0-2: API Endpoint 无 SSRF 防护

**位置**: §1.5 用户可配置 API Endpoint URL
**危害**: 用户可以配置任意 URL，包括 `http://127.0.0.1:9200`（Elasticsearch）、`http://127.0.0.1:8080`（内部服务）等内网地址。恶意插件或脚本可通过修改配置实现 SSRF。

**修复建议**:
1. 在 PHP 设置页和后端脚本中校验 URL schema 只允许 `http://` 和 `https://`
2. 禁止指向私有 IP 段（`127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`）
3. 在 `query-ai.sh` 中用 curl `--resolve` 或显式 IP 检查做二次防御
4. 添加可选的"允许内联 endpoint"开关，默认关闭

### P0-3: /tmp/ai-advisor/ 目录权限过松

**位置**: §3.4 §2 架构图
**危害**: `/tmp/ai-advisor/` 创建在全局可读的 tmpfs 上。`daemon.lock` 可被任意本地用户篡改（写入恶意 PID 诱导 clear-lock.sh 误杀进程），`last-stats.json` 泄露系统敏感信息（IP、目录结构、Docker 配置）。

**修复建议**:
1. daemon 启动时显式创建目录并设置权限：
   ```bash
   mkdir -p /tmp/ai-advisor
   chmod 700 /tmp/ai-advisor  # 仅 owner 可读写执行
   ```
2. 关键文件进一步限制权限：
   ```bash
   umask 077  # 在写入 daemon.lock 和 last-*.json 前设置
   ```
3. 如果 plugin 始终以 root 运行：`chown root:root /tmp/ai-advisor && chmod 700 /tmp/ai-advisor`

---

## P1 — 建议修复

### P1-1: API Key 明文存储

**位置**: §3.3 "API Key 明文存储到 /boot/config/plugins/ai-advisor/ai-advisor.cfg"
**风险**: Unraid 的 `/boot/config/plugins/` 下所有插件配置文件都是明文。任何能 SSH 登录的人、或受害插件（被攻陷的其他插件）都能读取 API Key。

**缓解建议**:
1. 至少限制配置文件权限 `chmod 600 ai-advisor.cfg`
2. 提供选项让用户使用环境变量注入 Key（通过 Unraid 的 Docker 风格的变量机制），而非写入文件
3. 在 WebGUI 显示 Key 时默认 masked（`********`），仅通过单独的"显示"按钮操作
4. 文档注明这是 Unraid 生态的通用问题，建议用户使用本地 Ollama 以完全避免 Key 泄露

### P1-2: 远程 API 发送的数据包含敏感信息

**位置**: §1.1 采集的系统状态
**风险**: 系统状态包含 IP 地址（`ip -j addr`）、目录挂载路径、Docker 容器名/镜像名。如果用户配置的是远程 AI API（如 OpenRouter、vLLM 公有实例），这些数据会离开内网。

**缓解建议**:
1. 在设置页添加"发送前审查"预览面板，展示即将发送的 JSON 数据
2. 添加可配置的数据脱敏开关，默认模糊化 IP 地址（`192.168.x.x` → `192.168.*.*`）
3. 在文档/WebGUI 中明确提示远程 API 的数据隐私风险
4. 推荐使用本地 Ollama 作为首选方案

### P1-3: 配置文件非原子写入

**位置**: §5.2 "表单处理: 提交后 `exec()` 调用后台脚本更新配置"
**风险**: PHP 写入 `ai-advisor.cfg` 时如果进程被中断（OOM、掉电），配置文件可能处于半写状态，`parse_ini_file()` 解析失败导致整个插件异常。

**缓解建议**:
1. 对 `ai-advisor.cfg` 同样使用原子写入模式：先写 `.tmp` 再 `mv`
2. `update-config.sh` 脚本也应用此规则

### P1-4: 插件卸载未彻底清理 /tmp 残留

**位置**: §3.3 "插件卸载时清理 /boot/config/plugins/ai-advisor/ 和 /usr/local/emhttp/plugins/ai-advisor/"
**风险**: `/tmp/ai-advisor/` 在 RAM 中，重启会自动清理。但如果用户在关机前卸载插件再重装，`/tmp/ai-advisor/` 中的敏感数据仍可被新实例或其他进程读取。

**缓解建议**:
1. 卸载脚本中补充 `rm -rf /tmp/ai-advisor/`
2. 卸载脚本也应记录日志

---

## P2 — 防守性编码建议

### P2-1: curl TLS 验证未指定

**位置**: §3.2 AI API 兼容性
**建议**: 在 `query-ai.sh` 中显式使用 `curl --cacert` 或确保系统 CA 证书可用。对自签名证书场景，提供可配置的 `CURL_INSECURE` 开关（默认关）。

### P2-2: smartctl 权限假设

**位置**: §1.1 磁盘采集使用 smartctl
**建议**: 在 `collect-stats.sh` 中检测 smartctl 是否可执行并返回合法数据，不可用时优雅降级（跳过 SMART、采集其他磁盘指标），而不是报错退出。

### P2-3: cron 表达式注入风险

**位置**: §1.5 用户可配置 Cron 表达式
**建议**: Shell 脚本在设置 cron 前校验表达式格式（至少有 5 个字段、字段值域合法），拒绝非法表达式而不只是"使用默认值"。

### P2-4: kill 信号可配置

**位置**: §3.4 clear-lock.sh 使用 `kill $PID`
**建议**: 优先使用 `kill -TERM $PID`（显式指定 SIGTERM），给进程优雅退出的机会。只有 SIGTERM 无效时才考虑 SIGKILL。当前文档只写了 `kill`，行为依赖系统默认信号。

### P2-5: 日志中不应包含 shell 命令输出

**位置**: §5.1 "日志: 统一使用 `logger -t ai-advisor "message"`"
**建议**: 日志消息中避免直接拼接未经裁剪的命令输出。例如 `ps -p $PID -o comm=` 的输出可能包含用户可控的进程名（如容器名），攻击者可构造包含换行符的进程名伪造日志条目。

---

## 修复优先级建议

| 优先级 | 编号 | 内容 | 影响面 |
|--------|------|------|--------|
| 紧急 | P0-1 | PHP exec() 命令注入 | 远程代码执行 |
| 紧急 | P0-2 | API Endpoint SSRF | 内网横向移动 |
| 紧急 | P0-3 | /tmp 目录权限过松 | 本地提权/信息泄露 |
| 高 | P1-1 | API Key 明文 | 凭据泄露 |
| 高 | P1-2 | 系统数据外发 | 隐私泄露 |
| 中 | P1-3 | 配置非原子写入 | 插件异常 |
| 中 | P1-4 | 卸载残留 | 信息泄露 |
| 低 | P2-1~5 | 防守性编码 | 纵深防御 |
