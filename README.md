# PACKME 使用说明

> composer require luguohuakai/packme:~1.0.0
>
> 环境依赖: php>=7.4/git命令行/zippy

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
      [2]: Packaging Last commit and current Not commit files
      # 打包某两次提交的差异文件(需要输入两次提交的commit id 短hash)
      [3]: Packaging Two commits different files
      # 打包当前已修改但未提交的文件(注意: 如果当前没有修改的文件会进行全量打包)
      [4]: Packaging Current Not commit files
 Your choice (default [1]): 
```

* 支持在dist目录下编写`change.txt`说明文档<br>
  `change.txt`会被自动打包, 打包时会自动向文档追加本次变更的文件路径

## replaceme

> 请知悉: 如果被替换的文件存在, 会先备份原来的文件再进行替换<br>
> 如果文件不存在, 则会进行自动创建

* 将安装包直接上传到服务器任意目录
* 解压缩安装包, 并进入解压后的目录
* 执行: `php replaceme`

```shell
php ./replaceme
  # 这里需要指定当前项目的根目录, 且项目根目录必须存在
  请指定项目根目录(默认:/srun3/www/srun4-mgr/): 
```
