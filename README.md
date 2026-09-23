# PACKME 使用说明

> 作为研发: 你是否开发完一个功能还在将变更文件一个一个地找出来打包, 然后写一个更新文档, 告诉一线人员该如何替换文件, 升级了哪些功能
>
> 作为一线: 你是否还在一个一个地往服务器上替换研发给你的文件, 有时候文件太多还很容易出错, 做完这些事情之后还没有文档记录, 而一旦出错还要去一个文件一个文件的去恢复
>
> PACKME 就是为了解决这些恼人问题, 提升开发愉悦感的小工具, 无论研发还是一线都只需一键执行即可完成打包和部署

## 目录

- [环境依赖](#环境依赖)
- [快速开始](#快速开始)
- [packme](#packme)
  - [运行方式](#运行方式)
  - [打包模式](#打包模式)
  - [产物说明](#产物说明)
  - [packme.ini 配置](#packmeini-配置)
  - [目标路径与项目名映射](#目标路径与项目名映射)
  - [version.ini 说明](#versionini-说明)
- [测试](#测试)
- [常见问题 FAQ](#常见问题-faq)
- [更新迭代计划](#更新迭代计划)
- [已知问题](#已知问题)
- [版本与兼容性](#版本与兼容性)
- [License](#license)
- [replaceme](#replaceme)

## 环境依赖

| 依赖 | 版本/说明 |
| --- | --- |
| PHP | `>= 7.4`(`replaceme5` 用于 PHP5 环境, 见 [replaceme](#replaceme)) |
| Git | 命令行可用, 且**项目必须使用 Git 管理** |
| Composer | 仅用于安装/更新本工具, 运行时无第三方依赖 |
| phar 扩展 | PHP 内置, 打包使用 `PharData` |
| tar / gzip | 仅运行集成测试时需要 |

> 中文环境补充: 项目中尽量避免中文路径/文件名; 如果确实需要, 请执行
> `git config --global core.quotepath false`, 详见 [已知问题](#已知问题)。

## 快速开始

```bash
# 1. 安装(开发依赖)
composer require --dev luguohuakai/packme:^1.0.43

# 2. 打包: 在项目根目录执行
#    Windows: .\vendor\bin\packme.bat
#    Linux/macOS:
php vendor/bin/packme

# 3. 部署: 将 dist/ 下的 tar.gz 上传到服务器, 解压到空目录后执行
tar -zxf XXX.tar.gz -C ./test
cd test && php ./replaceme

# 4. 回滚(只回滚当前安装包内的文件)
php ./replaceme --rollback
```

> 不想使用 composer 时, 可从 [Releases](https://github.com/luguohuakai/packme/releases) 下载独立运行的
> `packme.phar`, 在项目根目录执行 `php packme.phar`(用法与 `vendor/bin/packme` 一致, 已内置
> `replaceme` / `replaceme5`, 无需任何第三方依赖)。

> 版本要求: `backup_suffix` 丢失问题在 **v1.0.43** 修复, 请勿使用更低版本。

---

## packme

### 运行方式

请在**需要打包的项目根目录**执行:

- Windows: `.\vendor\bin\packme.bat`
- Linux/macOS: `php vendor/bin/packme`

命令行参数:

```bash
php vendor/bin/packme --help      # 查看帮助
php vendor/bin/packme --version   # 查看版本(无需 vendor/autoload.php)
```

首次运行会在项目根目录生成 `dist/version.ini` 与 `replaceme.ini`(已存在则不覆盖)。

### 打包模式

```text
Please select packaging method:
      [1]: Full packaging
      [2]: Pack the most recently commit and currently not commit files
      [3]: Pack the difference files commit between two times
      [4]: Pack files that have been modified but not yet commit
      [5]: Pack the most recently commit files
      [6]: Pack the last two commit files
      [7]: Pack the specified one-time commit
      [8]: Pack specified branch
      [9]: Pack specified path
      [0]: AI packaging (chat with AI, confirm, then pack)
 Your choice (default [5]):
```

| 模式 | 说明 | 备注 |
| --- | --- | --- |
| 1 全量打包 | 打包当前分支全部文件 | 会询问是否打包 `vendor` 目录, 选 `y` 耗时较长 |
| 2 最近提交 + 未提交 | 打包 `HEAD~1` 到工作区的全部变更 | 未提交部分取**工作区内容** |
| 3 两次提交差异 | 输入两次 commit id 短 hash | 先输入**新** commit(`<=`), 再输入**旧** commit(`>`); 旧 commit 的文件不包含 |
| 4 未提交变更 | 打包当前已修改但未提交的文件 | **无变更时直接终止**, 不会退化为全量打包 |
| 5 最近一次提交(默认) | 打包 `HEAD` 相对 `HEAD~1` 的变更 | 直接回车即选此项 |
| 6 最近两次提交 | 打包 `HEAD` 相对 `HEAD~2` 的变更 | |
| 7 指定一次提交 | 输入 commit id | |
| 8 指定分支 | 输入分支名(默认当前分支) | 打包分支创建到最新的变更 |
| 9 指定目录 | 输入要打包的目录或文件 | 支持相对路径与绝对路径; 不生成版本信息 |

模式 2/4 的额外约定:

- 已修改文件打包的是**工作区当前内容**, 不是 HEAD 里的旧内容。
- 新增文件需要先 `git add`, 否则会以警告形式列出且**不会**被包含。
- 模式 4 依赖 `git diff HEAD`, 未跟踪文件天然不在差异里, 这是 Git 的行为而非工具缺陷。

### AI 打包 (模式 0)

> 需要 PHP 具备 `curl` 或开启 `allow_url_fopen`, 以及一个 DeepSeek API Key。缺少 `lib/` 时该选项不显示。

选择 `[0]` 进入与 AI 的对话: 用自然语言描述要打包的内容, AI 会先读仓库(branch/HEAD/提交/变更文件/diff),
给出**打包计划**, 经你确认后才真正执行; 执行完由 AI 做**确定性校验**, 并说明做了什么、下一步怎么部署。

```text
Your choice (default [5]): 0
you> 把昨天那个修复打包一下
AI calls tool: git_recent_commits
AI calls tool: git_changed_files
Packaging plan
  Mode: 7 - one commit
  new: 1a2b3c4
  Reason: 匹配"昨天的修复"
Execute this plan? (y/n) [default n]: y
... 本地执行打包 ...
Done
  Archive: /path/to/project/dist/XXX_ONE_COMMIT_....tar.gz
  Verify: OK  members: 12
  Next: 解压后进入目录执行 php ./replaceme
Tokens: prompt=..., completion=..., total=...
```

配置(`packme.ini`):

```ini
; 建议使用环境变量 DEEPSEEK_API_KEY, 优先级高于这里
ai_api_key  = sk-xxxxxxxx
ai_base_url = https://api.deepseek.com
ai_model    = deepseek-flash
ai_send_diff = 1          ; 1: 允许把 diff 发给模型(默认)  0: 只发文件名/提交信息
ai_max_diff_bytes = 200000
ai_max_turns = 12
ai_stream = 1             ; 流式输出
ai_temperature = 0.2
ai_session_log = 1        ; 会话记录写入 ./dist/packme-ai-*.log
```

安全与边界:

- **代码外发**: 默认 `ai_send_diff=1`, 会把变更 diff 发送给模型; 敏感项目请设为 `0`, 此时只发送文件名、提交信息与仓库元数据。
- **执行权**: 模型不能执行 shell, 只能调用内置的只读工具(git 状态/日志/变更/目录/diff); 真正打包由本地 `PackmeRunner` 完成, 且必须人工 `y` 确认。
- **校验**: 打包后由 `PackmeRunner::verifyArchive()` 做确定性检查(必需文件、期望文件、可疑路径、内容比对), 不是让模型"目测"。
- **密钥**: 优先环境变量 `DEEPSEEK_API_KEY`; 若 `packme.ini` 被 git 跟踪且含 key 会给出告警, key 不会写入日志或压缩包。
- **提示注入**: 提交信息/文件名/diff 一律按数据处理, 不会作为指令执行。
- **失败兜底**: 网络或 API 异常会提示并退出 AI 模式, 不影响模式 1-9 的正常使用。

### 产物说明

产物统一生成于 `./dist/`:

| 文件 | 说明 |
| --- | --- |
| `<项目名>_<模式>_<时间>_<短hash>.tar.gz` | 差异/提交类模式的压缩包, 如 `SRUN4-MGR_LAST1COMMIT_250417062440_ABC1234.tar.gz` |
| `<项目名>_PATH_<时间>.tar.gz` | 模式 9 的压缩包 |
| `dist/changes.txt` | 变更说明, 会被打包进压缩包**根目录** |
| `<压缩包名>.txt` | 与压缩包同名的说明文件, 便于不解压查看 |
| `dist/version.ini` | 版本信息, 会被打包进压缩包**根目录** |

压缩包根目录同时包含 `replaceme`、`replaceme5`、`replaceme.ini`, 开箱即可部署(模式 9 会放在目标路径前缀下)。

### packme.ini 配置

在项目根目录新建 `packme.ini`(可选), 支持以下配置:

```ini
; 界面语言 en:英文(默认)  zh:中文(也接受 cn / zh-cn)
lang = en

; 是否生成 ./dist/changes.txt   1:生成(默认)  0:不生成
generate_change_txt = 1

; 是否上报使用量信息(项目名/目标路径, 用于统计)  1:上报(默认)  0:关闭
; 也可用环境变量临时关闭: PACKME_REPORT_USAGE=0
report_usage = 1

; 覆盖/扩展内置的项目名 -> 服务器路径映射(JSON, 键不区分大小写)
; 非 srun 项目可以在这里一次性配好, 不必每次手改 replaceme.ini
object_path_map = '{"my-project":"/srv/www/my-project/","another":"/srv/www/another/"}'
```

- `lang` 默认 `en`(英文); 设为 `zh`(或 `cn` / `zh-cn`)后菜单、交互提示、进度与状态信息切换为中文。未识别的值一律回退英文。
- `generate_change_txt = 0` 时不再生成/更新 `changes.txt`; 如果该文件已存在, 仍会照常打包。
- `report_usage = 0` 或 `PACKME_REPORT_USAGE=0` 时跳过使用量上报(上报失败或超时都不会影响打包)。
- `object_path_map` 的值包含 `{`、`"` 等字符, 必须用引号包裹(单引号或双引号均可)。

### 目标路径与项目名映射

`replaceme.ini` 用于告诉 `replaceme` 目标项目在服务器上的绝对路径。首次运行 `packme` 时会在项目根目录自动创建, 并按**项目名**写入 `object_root`, 例如:

| 项目名 | object_root |
| --- | --- |
| `srun4-mgr` / `srun4k-managent` | `/srun3/www/srun4-mgr/` |
| `srun4-api` / `srun4-api-74` | `/srun3/www/srun4-api/` |
| `zabbixFromGithub` | `/usr/share/zabbix/` |
| `packme`(本仓库自身) | `/packme/` |

> 内置映射是 srun 内部约定, 完整列表见 `packme` 源码中的 `PACKME_OBJECT_PATH_MAP`。
> 可以用 `packme.ini` 的 `object_path_map` 覆盖或扩展(优先级高于内置映射);
> **非 srun 系项目也可以直接手动修改 `replaceme.ini` 的 `object_root`**, 否则会落到默认值 `/srun3/www/srun4-mgr/`。

### version.ini 说明

`genVersion()` 写入的字段:

| 字段 | 含义 |
| --- | --- |
| `author` | `git config user.name` |
| `version` | `V<主>.<次>.<ymdHis>.<短hash>` |
| `git_branch_name` | 当前分支 |
| `git_version_hash_short` / `git_version_hash_long` | 短/长 commit hash |
| `update_time` | 生成时间 |

大版本更新方式: 打包前手动修改 `dist/version.ini` 中的 `version` 主次版本号, 如把 `V1.0.xxx` 改为 `V2.1`, 下次打包会以 `V2.1.` 开头。

---

## 测试

```bash
composer test
```

测试分两层:

- `tests/unit.php`: 快速单元测试(CLI、`replaceme.ini` 读写、`--dry-run`), 不需要 `git` 和 `composer install`。
- `tests/integration.php`: 真实创建临时 Git 项目并执行 `packme` / `replaceme` 主流程, 依赖 `composer` `git` `tar` `gzip` `php`。

> 集成测试的 `composer install` 只执行一次并复用模版, 后续用例直接复制, 减少网络与耗时。
> 保留失败时的临时目录: `PACKME_KEEP_TEST_TMP=1 composer test`

构建独立运行的 phar(发布时由 CI 自动构建并附带在 Release 上):

```bash
composer build-phar                                  # 生成 ./packme.phar
php -d phar.readonly=0 build-phar.php /tmp/custom.phar   # 指定输出路径
```

> 打包 phar 需要关闭 `phar.readonly`(上面的命令已带 `-d phar.readonly=0`); 运行 phar 无此要求。

---

## 常见问题 FAQ

**Q: 新增文件没被打进包?**

A: 新增文件先 `git add` 再打包。未跟踪文件会以警告形式列出, 不会静默漏掉。

**Q: 选择模式 4 后直接结束, 提示 `No changed files found, packaging stopped`?**

A: 当前工作区确实没有已跟踪的变更。这是预期行为, 不会再退化成全量打包; 此时命令以非 0 退出码结束, 方便脚本判断。

**Q: 想先看看会替换哪些文件, 不实际改动服务器?**

A: `php ./replaceme --dry-run` 只打印将备份/替换/创建的文件, 不修改任何文件, 也不写 `replaceme.ini`。

**Q: 文件名/路径包含中文时报错?**

A: 执行:

```bash
git config --global core.quotepath false
git config --global core.assumeunicode true
```

**Q: 模式 3 的 commit id 怎么填?**

A: 两次询问都支持直接回车: 第一次默认 `HEAD`(最新提交), 第二次默认 `HEAD~1`。也可以手动输入 commit 短 hash, 输入不存在的 id 会报错并以非 0 退出码结束, 不会生成压缩包。

**Q: 回滚报 `备份不存在, 不可回滚!!!`?**

A: 说明 `replaceme.ini` 里没有 `backup_suffix`。旧版本(≤ v1.0.42)存在该缺陷, 请升级到 `>= v1.0.43` 后重新打包。已经用旧包装过的服务器, 可从备份文件名(`原文件名+时间戳`)推断后缀, 手工写入 `replaceme.ini` 的 `backup_suffix` 后再回滚。

**Q: 执行 `replaceme` 报 `写入replaceme.ini失败, 请检查当前目录和replaceme.ini权限`?**

A: `replaceme.ini` 所在目录不可写。请给该目录/文件写权限后重试; 写入失败时不会替换任何目标文件。

---

## 更新迭代计划

| 计划 | 状态 |
| --- | --- |
| 模式 9 支持打包绝对路径(如 `D:\PhpstormProjects\path\to\project` 或 `/Users/xx/path/to/project`) | ✅ 已完成, 自动去掉盘符与前导 `/`, 压缩包内为纯相对路径 |
| 模式 3 直接回车时默认取最新提交 | ✅ 已完成, 第一次默认 `HEAD`, 第二次默认 `HEAD~1` |
| Windows 全量集成测试 | ⬜ 待实现, 目前仅做语法与 CLI 冒烟 |

## 已知问题

| 问题 | 状态 |
| --- | --- |
| 文件名含中文时打包出错(多为本地 Git 配置导致) | 提供解决方案, 见 [FAQ](#常见问题-faq) |
| 回滚缺少预演 | 安装已支持 `--dry-run`; 回滚仍只有 y/n 确认 |

> 历史问题「无文件变更时会进行全量打包」「新增文件导致 `pathspec did not match`」「模式 2/4 打包 HEAD 旧内容」「`changes.txt` 未进包」均已修复, 见 `CHANGELOG.md`。

## 版本与兼容性

- PHP `>= 7.4`(`replaceme` 使用类型属性); PHP5 环境请使用 `replaceme5`。
- **请使用 `>= v1.0.43`**: 该版本修复了 `backup_suffix` 未持久化导致无法回滚的问题。
- v1.1.2: 发布产物新增独立运行的 `packme.phar`(Release 自动附带), 也可用 `composer build-phar` 自行构建。
- v1.1.1: `packme.ini` 新增 `lang`, 运行时提示可在英文(默认)与中文之间切换。
- v1.1.0: 修复模式 2/4 打包旧内容、新增文件失败、`changes.txt` 未进包、`ignore_dir_prefix` 误替换、PHP 8.1+ 弃用告警; 移除 `alchemy/zippy` 与 `luguohuakai/func` 依赖, 运行时零第三方依赖; 新增 `--help` / `--version` / `--dry-run` 与 `packme.ini` 配置项。
- 变更记录见 [`CHANGELOG.md`](./CHANGELOG.md)。

## License

[MIT](./LICENSE) © DM

## replaceme

> 部署工具, 随压缩包一起分发, 用于把包内文件替换到服务器目标项目。

### 部署

1. 将安装包上传到服务器任意目录。
2. 解压到**任意空目录**, 该目录下不能有任何其它文件(包括隐藏文件):
   `tar -zxf xxx.tar.gz -C ./test`
3. 进入**含 `replaceme` 的目录**(模式 9 可能带有子目录前缀, 如 `test/dist/`):
   `cd test`
4. 执行: `php ./replaceme`(老版本 PHP5 请执行 `php ./replaceme5`)
5. 如需记录更新日志: `php ./replaceme | tee zzz_exec.log`

常用命令:

```bash
php ./replaceme --help             # 查看帮助
php ./replaceme --dry-run          # 预演: 只打印将备份/替换/创建的文件, 不做任何修改
php ./replaceme --backup=-v2       # 自定义备份后缀(追加在时间戳之后)
php ./replaceme --rollback         # 回滚当前安装包内的文件
```

> `replaceme5` 支持同样的参数, 只是把命令名换成 `replaceme5`。

```text
# 执行时会要求确认项目根目录, 默认值来自 replaceme.ini 的 object_root
请指定项目根目录(默认:/srun3/www/xxx/):
```

> 如果被替换的文件已存在, 会先备份原文件再替换; 如果不存在, 则自动创建。

### 部署配置 (replaceme.ini)

```ini
# 要替换的项目在服务器上的绝对路径(自动生成)(可以更改)
object_root = /srun3/www/srun4-mgr/
# 备份文件后缀名(安装时自动生成, 每次安装都会覆盖为新的时间戳)
backup_suffix = 20230906011724
# 安装时间(安装时自动生成)
install_time = 2023-09-06 01:17:24
# 回滚时间(执行回滚后自动生成, 已回滚过会拒绝重复回滚)
rollback_time = 2023-09-06 01:20:00
# 安装包中需要忽略的目录前缀(按需手动配置)(默认为空)
# 只去除相对路径开头的这个前缀, 不会影响项目根目录
# 如: 安装包内为 `ui/assets/img/icon-sprite.svg`
#     服务器上为 `/usr/share/zabbix/assets/img/icon-sprite.svg`
#     则配置为: ignore_dir_prefix = ui/
ignore_dir_prefix =
```

### 自定义备份后缀

```bash
# 在时间戳后追加自定义标识, 如生成 xxx.php20230906011724-before-v2
php ./replaceme --backup=-before-v2
```

### 支持回滚操作

- 只能回滚当前安装包内的文件。
- 回滚命令: `php ./replaceme --rollback`

> ⚠️ 回滚是破坏性操作: 包里没有对应备份、但目标机上存在的文件会被**直接删除**; `vendor` 目录会被整体删除后恢复。回滚前请确认 `backup_suffix` 正确, 并做好数据备份。

### 常见问题

| 现象 | 原因/处理 |
| --- | --- |
| `备份不存在, 不可回滚!!!` | `replaceme.ini` 中没有 `backup_suffix`, 升级到 `>= v1.0.43` 重新打包 |
| `写入replaceme.ini失败, 请检查当前目录和replaceme.ini权限` | 目录/文件不可写, 修正权限后重试 |
| `此目录不存在, 请重新输入!` | 输入的 `object_root` 在服务器上不存在 |
| 替换后文件位置不对 | 检查 `ignore_dir_prefix` 是否配置正确(只去掉相对路径开头的该前缀) |
