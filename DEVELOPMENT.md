# 开发指南

## 环境搭建

Matrix 框架采用核心库 + 应用脚手架分离架构，开发时需要将两个仓库并排克隆：

```bash
git clone https://github.com/taochangle/matrix-fragework.git
git clone https://github.com/taochangle/matrix-app.git
```

目录结构：

```
matrix/
├── matrix-framework/    # 核心框架（当前仓库）
└── matrix-app/          # 应用脚手架（用于验证框架改动）
```

## 配置本地软链接

确保 `matrix-app/composer.json` 使用 `path` 仓库指向本地的 framework：

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../matrix-framework"
        }
    ],
    "require": {
        "php": ">=8.1",
        "taochangle/matrix-framework": "*@dev"
    }
}
```

```bash
cd matrix-app
composer install
```

`vendor/taochangle/matrix-framework` 会被创建为指向 `../../../matrix-framework/` 的软链接，修改 framework 代码立即在 app 端生效，无需重复 `composer update`。

## 目录结构

```
matrix-framework/
├── src/
│   ├── Application.php
│   ├── Container.php
│   ├── Pipeline.php
│   ├── Router.php
│   └── Http/
│       ├── Request.php
│       └── Response.php
├── composer.json
└── README.md

matrix-app/
├── app/
│   ├── Controllers/
│   ├── Middlewares/
│   └── Services/
├── public/
│   └── index.php
├── routes/
│   └── web.php
├── composer.json
└── README.md
```

## 开发流程

```bash
# 1. 在 framework 中修改源码

# 2. 重新生成 autoload（如果新增了类）
composer dump-autoload -d matrix-framework

# 3. 启动测试服务器
php -S 0.0.0.0:8000 -t matrix-app/public

# 4. 验证
curl http://localhost:8000/api/user
```

## 代码规范

- PSR-12 编码风格
- `declare(strict_types=1)` 所有文件
- 属性/参数使用 PHP 8.1+ 类型声明
- 返回值类型声明完整
