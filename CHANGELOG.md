# Changelog

本项目遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## v1.2.1 - 2026-09-22

### 修复

- `lang=zh` 时菜单 `[0]: AI packaging` 仍显示英文: 补全 `Lang` 词典。
- 同时补齐 AI 打包其余未翻译文案: 缺少 `lib/` 的提示、压缩包校验错误(缺少必需/期望文件、
  绝对路径或越界成员、内容与工作区不一致等)以及 HTTP/接口错误。

## v1.2.0 - 2026-09-22

### 新增

- **AI 打包(模式 0)**: 与 DeepSeek 多轮对话, AI 先读仓库(分支/提交/变更文件/diff),
  给出打包计划, **人工确认后**才执行; 执行完由 AI 做确定性校验并说明做了什么、下一步怎么部署。
- 新增 `lib/`: `PackRunner.php`(打包流程可调用化)、`HttpClient.php`、`AiClient.php`(含 SSE 流式与
  tool_calls 累加)、`AiAgent.php`(对话循环/工具派发/计划确认/校验/总结)。
- `packme.ini` 新增 AI 配置: `ai_api_key` / `ai_base_url` / `ai_model` / `ai_send_diff` /
  `ai_max_diff_bytes` / `ai_max_turns` / `ai_stream` / `ai_temperature` / `ai_session_log`;
  密钥优先读取环境变量 `DEEPSEEK_API_KEY`。
- `PackmeRunner::verifyArchive()`: 压缩包确定性校验(必需文件、期望文件、可疑成员路径、内容比对)。
- `composer build-phar` 与 Release 附件现在包含 `lib/`, phar 内同样可用 AI 打包。

### 变更

- 打包流程由顶层 `switch` 重构为 `PackmeRunner::run(mode, opts)`, 交互菜单与 AI 共用同一实现;
  失败时返回结构化结果而非直接 `exit()`。
- `packPath()` / `fullPack()` 支持参数化(不再只能交互输入), 便于程序化调用与测试。
- 菜单新增 `[0]: AI packaging`; `lang=zh` 时 AI 对话与提示均为中文。

### 测试

- 单元测试新增: AI 客户端非流式解析、流式解析(内容与 tool_calls 跨块累加)、API 错误处理、
  `verifyArchive` 缺失文件检测。
- 集成测试新增: 本地 DeepSeek 桩服务驱动的端到端 AI 打包(工具调用 → 计划 → 确认 → 打包 → 校验 → 总结)。

## v1.1.2 - 2026-09-22

### 新增

- 新增 `build-phar.php` 与 `composer build-phar`, 可构建独立运行的 `packme.phar`
  (内置 `replaceme` / `replaceme5`, 无需 composer 与任何第三方依赖)。
- GitHub Release 工作流在打 tag 时自动构建并附带 `packme.phar`。
- `packme` 支持从 phar 运行: phar 内的 `replaceme` / `replaceme5` 会先释放为临时文件
  (保留文件名), 再交给 `git archive --add-file`, 保证压缩包内文件名正确。

## v1.1.1 - 2026-09-22

### 新增

- `packme.ini` 新增 `lang` 配置, 可在 `en`(默认, 英文)与 `zh`(中文, 兼容 `cn` / `zh-cn`)之间切换
  运行时提示; 覆盖菜单、交互提示、进度条、状态与错误信息, 未识别的值回退英文。

## v1.1.0 - 2026-09-22

### 修复

- **模式 2/4(未提交变更)打包的是 HEAD 旧内容**: `git archive HEAD <pathspec>` 只能归档
  commit 里的内容。现在改为先把变更文件写入临时索引并 `git write-tree`, 归档临时 tree,
  保证打包工作区当前内容。
- **新增文件导致打包失败**: 已 `git add` 的新文件不在 HEAD 中, 作为 pathspec 会报
  `fatal: pathspec 'xxx' did not match any files` 并生成空压缩包; 现在会被正确归档并保留完整相对路径。
- **未跟踪文件被静默漏掉**: 现在会以警告形式列出未跟踪文件, 提示先 `git add`。
- **`changes.txt` / `<压缩包名>.txt` 未进入压缩包**: 说明文件在 `prepare()` 之后才加入
  打包列表, 导致 `--add-file` 未生效; 现在会在组装命令前生成并加入, 打包进压缩包根目录。
- **模式 9 绝对路径**: 归档成员名不再包含 Windows 盘符与前导 `/`, 压缩包内为纯相对路径。
- **`ignore_dir_prefix` 误伤项目根目录**: 由整串 `str_replace` 改为只去除相对路径开头的
  前缀, 避免目标根目录中恰好包含相同片段时替换到错误位置(`replaceme` 与 `replaceme5`)。
- **失败时退出码为 0**: 所有打包分支现在在失败(无变更、git 报错、压缩失败)时以非 0 退出,
  便于 CI/脚本判断。
- **命令拼接未转义**: `git archive` 的分支名、输出路径、commit id 统一 `escapeshellarg`;
  `git reflog show` 同样处理。
- **模式 3/7 输入未校验**: 新增 `git rev-parse --verify` 校验, 非法 commit id 立即报错退出;
  模式 3 两次询问支持直接回车(默认 `HEAD` / `HEAD~1`)。
- **PHP 8.1+ 弃用告警**: `ProgressBar` 的时间戳属性类型由 `int` 改为 `float`, 百分比显式取整,
  不再输出 `Implicit conversion from float ... loses precision`。
- **`replaceme.ini` 读写不安全**: 值包含空格、`;`、`=`、引号等特殊字符时加双引号并转义,
  换行符改用 `PHP_EOL`, 避免 `parse_ini_file` 读回错误。
- **运行时零第三方依赖**: 移除 `alchemy/zippy`(改用 PHP 内置 `PharData`, 保留文件权限, 支持目录/追加/压缩)
  与 `luguohuakai/func`(日志与字符串工具内联到 `packme`), `composer.json` 只保留 `php` 与 `ext-phar`;
  PHP 8.4 下不再有第三方弃用告警, 未 `composer install` 也能运行。
- **使用量上报可关闭且有超时**: 新增 `packme.ini` 的 `report_usage` 与环境变量
  `PACKME_REPORT_USAGE`, 并改为带 2 秒连接/读取超时的请求, 网络异常不再拖慢打包。

### 新增

- `packme --help` / `packme --version`(无需 `vendor/autoload.php`)。
- `replaceme --help` / `replaceme --dry-run`(预演: 只打印将备份/替换/创建的文件)。
- `packme.ini` 新增 `report_usage` 与 `object_path_map`(用 JSON 覆盖/扩展内置项目路径映射)。
- 未 `composer install` 时也能直接运行 `packme`(不再强制依赖 `vendor/autoload.php`)。

### 仓库与测试

- 重写 README: 修正默认模式说明, 补充 `packme.ini` / `version.ini` / `replaceme.ini`
  完整配置、产物说明、FAQ、已知问题与版本兼容性。
- 补充 `LICENSE`(MIT)、`CHANGELOG.md`、`.gitattributes`(export-ignore),
  以及 GitHub Actions 测试工作流(含 Windows 冒烟)与 tag 发布工作流。
- 取消跟踪 IDE 配置目录 `.idea/`, `.gitignore` 增加 `.idea/` 与 `.DS_Store`。
- 测试拆分为 `tests/unit.php`(快速, 无需 git/composer)与 `tests/integration.php`;
  集成测试的 `composer install` 只执行一次并复用模版, 明显缩短运行时间。
- 新增用例: 工作区内容打包、新增文件路径与内容、未跟踪文件告警、无变更时非 0 退出、
  绝对路径规范化、`generate_change_txt=0`、`ignore_dir_prefix` 前缀语义、模式 3 默认 commit、
  非法 commit id、`object_path_map`, 以及 CLI/ini 读写的单元测试。

## v1.0.43 - 2026-05-26

### 修复

- **`backup_suffix` 未写入 `replaceme.ini`**: `Config::set()` 写入文件后没有同步内存缓存,
  紧随其后的 `set('install_time', ...)` 会用陈旧的缓存重写整个文件, 把 `backup_suffix`
  删除, 导致 `php ./replaceme --rollback` 报「备份不存在, 不可回滚!!!」。
- 写入 `replaceme.ini` 失败时不再静默: 输出
  `写入replaceme.ini失败, 请检查当前目录和replaceme.ini权限` 并以非 0 退出, 避免出现
  「文件已替换但备份后缀没记录」的半成品状态。
- 打包时避免同一个 `replaceme.ini` 在压缩包内出现两次。
