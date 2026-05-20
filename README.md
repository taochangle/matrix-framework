# Matrix Framework

A modern PHP micro-framework with IoC container, pipeline middleware, and simple routing.

## Features

- **IoC Container** — Reflection-based auto-wiring, singleton/bind registration
- **Pipeline Middleware** — Onion model request/response flow
- **Simple Router** — Static routes + `{param}` dynamic matching, closure and controller support
- **JSON Ready** — Built-in JSON request parsing and response factory

## Requirements

PHP >= 8.1

## Installation

```bash
composer require taochangle/matrix-framework
```

## Quick Start

```php
use Matrix\Application;
use Matrix\Http\Request;

require 'vendor/autoload.php';

$app = new Application();

$app->addGlobalMiddleware([
    new App\Middlewares\GlobalLogger(),
]);

$app->loadRoutes(__DIR__ . '/routes/web.php');

$app->handle(new Request());
```

## License

MIT
