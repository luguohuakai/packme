# PACKME 使用说明

> 序言
>
> 作为研发: 你是否开发完一个功能还在将变更文件一个一个地找出来打包, 然后写一个更新文档, 告诉一线人员该如何替换文件, 升级了哪些功能
>
> 作为一线: 你是否还在一个一个地往服务器上替换研发给你的文件, 有时候文件太多还很容易出错, 做完这些事情之后还没有文档记录, 而一旦出错还要去一个文件一个文件的去恢复
>
> PACKME就是为了解决这些恼人问题, 提升开发愉悦感的小工具, 无论研发还是一线都只需一键执行即可完成打包和部署

> composer require luguohuakai/packme:~1.0.0
>
> 环境依赖: php>=7.4/git/zippy

## packme

> 注意: 项目必须使用Git进行管理<br>
> 注意: 项目中尽量不要出现中文路径和文件名, 否则打包时可能会出现问题

请在需要打包的项目根目录执行

* Windows: `.\vendor\bin\packme.bat`
* Linux: `php vendor/bin/packme`

```shell
Please select packaging method:
      # 全量打包(默认) 可选是否打包vendor目录
      # 全量打包首次运行会在项目根目录下生成dist/version.ini
      # 全量打包时version.ini会自动打包进项目根目录
      [1]: Full packaging
      # 打包最近一次提交和当前未提交的文件
      [2]: Pack the most recently commit and currently not commit files
      # 打包某两次提交的差异文件(需要输入两次提交的commit id 短hash)
      # 注意: 打包差异文件不包括old_commit_id的文件, 包括new_commit_id的文件
      [3]: Pack the difference files commit between two times
      # 打包当前已修改但未提交的文件(注意: 如果当前没有修改的文件会进行全量打包)
      [4]: Pack files that have been modified but not yet commit
      # 打包最近一次提交的文件
      [5]: Pack the most recently commit files
      # 打包最近两次提交的文件
      [6]: Pack the last two commit files
      # 打包指定的一次提交
      [7]: Pack the specified one-time commit
      # 打包指定分支 默认为当前分支 (打包从分支创建到当前最新commit的变更文件)
      [8]: Pack specified branch
      # 打包指定目录(注意:不要出现中文路径或文件名)
      [9]: Pack specified path
 Your choice (default [5]): 
```

* 打包产物将生成于`./dist/`目录下
* 注意: 全量打包时, 若选择打包vendor目录将花费较长时间, 请耐心等待

* 同时还可能生成`./dist/version.ini`, 用于记录版本信息
* 大版本更新方式: 只需打包前手动修改`./dist/version.ini`中大版本就行, 如:将V1.0修改为V2.1

* 支持在`./dist/`目录下编写`changes.txt`说明文档, `changes.txt`会被自动打包, 打包时会自动向文档追加提交信息和变更的文件路径
* `./dist/changes.txt`如果没有, 需要自行创建

## 更新迭代计划

### 已知问题

```
1. 文件名存在中文时打包出错,可能是本地环境配置问题
执行以下命令:
git config --global core.quotepath false
git config --global core.assumeunicode true

2. 打包没有文件变更的提交(只有文件删除)时会进行全量打包
修复方案 无文件变更时停止打包

3. 新增文件,然后选择[4]进行打包会报错 如:
Please select packaging method:
      [1]: Full packaging
      [2]: Pack the most recently commit and currently not commit files
      [3]: Pack the difference files commit between two times
      [4]: Pack files that have been modified but not yet commit
      [5]: Pack the most recently commit files
      [6]: Pack the last two commit files
      [7]: Pack the specified one-time commit
      [8]: Pack specified branch
 Your choice (default [5]): 4
Change file exists: ./dist/changes.txt
fatal: pathspec '测试中文文件能否打包和安装.md' did not match any files
```

# replaceme

> replaceme.ini配置说明

```shell
# 要替换的项目在服务器上的绝对路径(自动生成)(可以更改)
object_root = /srun3/www/srun4-mgr/
# 备份文件后缀名(自动生成)(不可更改)
backup_suffix = 20230906011724
# 安装包中需要忽略的目录前缀(按需手动配置)(默认为空)
# 如: 
# 安装包中目录为: `ui/assets/img/icon-sprite.svg`
# 服务器中目录为: `/usr/share/zabbix/assets/img/icon-sprite.svg`
# 则需要配置为: 忽略安装包中 `ui/` 
ignore_dir_prefix = ui/
```

## replaceme

### 部署

* 将安装包直接上传到服务器任意目录
* 解压缩安装包到`任意空目录`, 空目录下不能有任何其它文件
* 如: `tar -zxf xxx.tar.gz -C ./test`
* 进入解压后的目录
* 如: `cd test`
* 执行: `php ./replaceme` (老版本PHP5请执行`php ./replaceme5`, 下面不再赘述)
* 如需记录更新日志则这样执行: `php ./replaceme | tee zzz_exec.log`

* 支持自定义备份后缀: `php ./replaceme --backup=xxx`

```shell
# 执行过程解释
php ./replaceme
  # 这里需要指定当前项目的根目录, 且项目根目录必须存在, 如果默认目录正确则直接回车
  请指定项目根目录(默认:/srun3/www/xxx/): 
```

> 请知悉: 如果被替换的文件存在, 会先备份原来的文件再进行替换, 如果文件不存在, 则会进行自动创建

### 支持回滚操作

* 只能回滚当前安装包内的文件
* 回滚命令: `php ./replaceme --rollback`

