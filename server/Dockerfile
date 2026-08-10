# Kefu 服务 Dockerfile
# 适配生产 Linux 环境

FROM php:8.1-cli-alpine

# 安装基础工具和 PHP 扩展
RUN apk add --no-cache \
    curl \
    git \
    unzip \
    libzip-dev \
    $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_mysql opcache bcmath

# 安装 Redis 扩展（生产用）
RUN apk add --no-cache redis || true

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 工作目录
WORKDIR /app

# 复制 composer 文件先安装依赖（缓存优化）
COPY composer.json ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# 复制应用代码
COPY . .

# 完整安装（生成 autoload）
RUN composer dump-autoload --optimize

# 创建运行时目录
RUN mkdir -p runtime/{logs,cache,sessions}

# 默认环境
ENV APP_ENV=production \
    WEBMAN_PORT=8787

EXPOSE 8787

# 健康检查
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s \
  CMD curl -f http://127.0.0.1:8787/api/health || exit 1

# 启动
CMD ["php", "start.php", "start"]
