# PACKME 使用说明

> composer require luguohuakai/packme:~1.0.0
>
> 环境依赖: php>=7.4/git/zippy

## packme

> 注意: 项目必须使用Git进行管理

请在需要打包的项目根目录执行

* Windows: `.\vendor\luguohuakai\packme\packme.bat`
* Linux: `php vendor/luguohuakai/packme/packme`

```shell
Please select packaging method:
      # 全量打包(默认) 可选是否打包vendor目录
      # 全量打包首次运行会在项目根目录下生成dist/version.ini
      # 全量打包时version.ini会自动打包进项目根目录
      [1]: Full packaging
      # 打包最近一次提交和当前未提交的文件
      [2]: Package Last commit and current Not commit files
      # 打包某两次提交的差异文件(需要输入两次提交的commit id 短hash)
      # 注意: 打包差异文件不包括old_commit_id的文件, 包括new_commit_id的文件
      [3]: Package Two commits different files
      # 打包当前已修改但未提交的文件(注意: 如果当前没有修改的文件会进行全量打包)
      [4]: Package Current Not commit files
      # 打包最近一次提交的文件
      [5]: Package Last commit files
      # 打包最近两次提交的文件
      [6]: Package the last two commit files
 Your choice (default [1]): 
```

* 打包产物将生成于`./dist/`目录下
* 注意: 全量打包时, 若选择打包vendor目录将花费较长时间, 请耐心等待

* 同时还可能生成`./dist/version.ini`, 用于记录版本信息
* 大版本更新方式: 只需打包前手动修改`./dist/version.ini`中大版本就行, 如:将V1.0修改为V2.1

* 支持在`./dist/`目录下编写`change.txt`说明文档, `change.txt`会被自动打包, 打包时会自动向文档追加提交信息和变更的文件路径
* `./dist/change.txt`如果没有, 需要自行创建

## replaceme

* 将安装包直接上传到服务器任意目录
* 解压缩安装包到`任意新的目录`, 新目录下不能有其它文件
* 如: `tar -zxf xxx.tar.gz -C ./test`
* 进入解压后的目录
* 如: `cd test`
* 执行: `php replaceme`
* 如需记录更新日志则这样执行: `php replaceme | tee zzz_exec.log`

```shell
# 执行过程解释
php ./replaceme
  # 这里需要指定当前项目的根目录, 且项目根目录必须存在, 如果默认目录正确则直接回车
  请指定项目根目录(默认:/srun3/www/srun4-mgr/): 
```

> 请知悉: 如果被替换的文件存在, 会先备份原来的文件再进行替换, 如果文件不存在, 则会进行自动创建
