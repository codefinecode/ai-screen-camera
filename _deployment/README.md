# Deployment Guide

This directory contains all necessary files for deploying the Edge AI Intermediate Service.

## 📁 Структура

```
_deployment/
├── README.md                    # Этот файл
├── scripts/
│   └── deploy.sh               # Скрипт автоматического деплоя
├── systemd/
│   ├── intermediate-app.service    # Systemd unit для HTTP API
│   ├── intermediate-queue.service  # Systemd unit для queue worker
│   └── intermediate-ws.service     # Systemd unit для WebSocket
├── nginx/
│   └── nginx.conf              # Конфигурация Nginx
└── docker/
    ├── docker-compose.yml      # Docker Compose конфигурация
    ├── Dockerfile              # Docker образ
    └── entrypoint.sh           # Docker entrypoint скрипт
```

---

## 🚀 Варианты deployment

### Вариант 1: Systemd (рекомендуется для production)

**Требования**:
- Ubuntu 20.04+ / Debian 11+
- PHP 8.1+
- Redis
- Nginx
- Composer

**Установка**:

```bash
# 1. Клонируйте репозиторий
cd /var/www/html
git clone <your-repo-url> intermediate-service
cd intermediate-service

# 2. Установите зависимости
sudo apt update && sudo apt install -y \
    php8.1 php8.1-fpm php8.1-xml php8.1-mbstring \
    php8.1-curl php8.1-redis php8.1-zip \
    redis-server nginx composer

# 3. Настройте окружение
cp .env.example .env
nano .env  # Отредактируйте конфигурацию

# 4. Запустите скрипт деплоя
chmod +x _deployment/scripts/deploy.sh
sudo ./_deployment/scripts/deploy.sh

# 5. Настройте Nginx
sudo cp _deployment/nginx/nginx.conf /etc/nginx/sites-available/intermediate-service
sudo ln -s /etc/nginx/sites-available/intermediate-service /etc/nginx/sites-enabled/
sudo nano /etc/nginx/sites-available/intermediate-service  # Измените server_name
sudo nginx -t && sudo systemctl reload nginx

# 6. Установите systemd services
sudo cp _deployment/systemd/*.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable intermediate-app intermediate-queue intermediate-ws
sudo systemctl start intermediate-app intermediate-queue intermediate-ws

# 7. Проверьте статус
sudo systemctl status intermediate-app
sudo systemctl status intermediate-queue
sudo systemctl status intermediate-ws
curl http://localhost/api/health
```

**Управление сервисами**:

```bash
# Перезапуск
sudo systemctl restart intermediate-app
sudo systemctl restart intermediate-queue
sudo systemctl restart intermediate-ws

# Остановка
sudo systemctl stop intermediate-app
sudo systemctl stop intermediate-queue
sudo systemctl stop intermediate-ws

# Логи
sudo journalctl -u intermediate-app -f
sudo journalctl -u intermediate-queue -f
sudo journalctl -u intermediate-ws -f
```

---

### Вариант 2: Docker (для development и testing)

**Требования**:
- Docker 20.10+
- Docker Compose 2.0+

**Установка**:

```bash
# 1. Клонируйте репозиторий
git clone <your-repo-url> intermediate-service
cd intermediate-service

# 2. Настройте окружение
cp .env.example .env
nano .env  # Отредактируйте конфигурацию

# 3. Запустите Docker Compose
cd _deployment/docker
docker-compose up -d

# 4. Проверьте статус
docker-compose ps
curl http://localhost:8080/api/health
```

**Управление контейнерами**:

```bash
# Логи
docker-compose logs -f app
docker-compose logs -f queue
docker-compose logs -f redis

# Перезапуск
docker-compose restart

# Остановка
docker-compose down

# Пересборка
docker-compose up -d --build
```

---

### Вариант 3: Manual (для local development)

**Требования**:
- PHP 8.1+
- Redis
- Composer

**Установка**:

```bash
# 1. Клонируйте репозиторий
git clone <your-repo-url> intermediate-service
cd intermediate-service

# 2. Установите зависимости
composer install

# 3. Настройте окружение
cp .env.example .env
nano .env  # Отредактируйте конфигурацию

# 4. Сгенерируйте ключ
php artisan key:generate

# 5. Запустите миграции
php artisan migrate --force

# 6. Запустите сервисы (в разных терминалах)
php artisan serve --host=0.0.0.0 --port=8080
php artisan queue:work --tries=10
php artisan ws:serve --host=0.0.0.0 --port=8081
```

---

## 🔧 Конфигурация

### Обязательные переменные окружения

```bash
# Application
APP_NAME="Edge AI Intermediate Service"
APP_ENV=production
APP_DEBUG=false
APP_KEY=  # Сгенерируется автоматически

# Redis
REDIS_HOST=127.0.0.1  # или redis для Docker
REDIS_PORT=6379
REDIS_PASSWORD=null

# Queue
QUEUE_CONNECTION=redis

# AWS Integration
AWS_INGEST_URL=https://your-aws-api.com/ingest/frames
AWS_QUERY_URL=https://your-aws-api.com/query/frames
AWS_BEARER_TOKEN=your-aws-token-here

# Security (опционально для production)
API_BEARER_TOKEN=your-api-token-here
API_FRAMES_RATE_LIMIT=1000
API_MAX_PAYLOAD_SIZE_MB=10
```

### Опциональные переменные

```bash
# Trigger Engine
TRIGGER_THROTTLE_MS=300
TRIGGER_ACTIVE_TTL=3600

# Aggregation
IMPRESSION_GAP_SEC=5
AGGREGATION_CACHE_TTL=300
AGGREGATION_MAX_FRAMES=10000

# CORS
CORS_ALLOWED_ORIGINS=https://dashboard.example.com
```

---

## ✅ Проверка deployment

### 1. Health Check

```bash
curl http://localhost/api/health
# Ожидается: {"status":"healthy", ...}
```

### 2. Отправка тестового кадра

```bash
curl -X POST http://localhost/api/v1/frames \
  -H "Content-Type: application/json" \
  -d '{"timestamp":1741709337,"playerUUID":"screen-1","faceDetections":[]}'
# Ожидается: {"status":"ok","accepted":1}
```

### 3. Проверка очереди

```bash
# Для Systemd
sudo journalctl -u intermediate-queue -n 50

# Для Docker
docker-compose logs queue
```

### 4. Проверка WebSocket

Откройте `examples/player-sim.html` в браузере и подключитесь к `ws://your-server:8081`

---

## 🔍 Troubleshooting

### Проблема: Health check возвращает "unhealthy"

**Решение**:
1. Проверьте Redis: `redis-cli ping`
2. Проверьте очередь: `php artisan queue:work --once`
3. Проверьте логи: `tail -f storage/logs/laravel.log`

### Проблема: Queue worker не обрабатывает задачи

**Решение**:
1. Проверьте статус: `sudo systemctl status intermediate-queue`
2. Проверьте Redis: `redis-cli llen queues:default`
3. Перезапустите: `sudo systemctl restart intermediate-queue`

### Проблема: WebSocket не подключается

**Решение**:
1. Проверьте статус: `sudo systemctl status intermediate-ws`
2. Проверьте порт: `netstat -tulpn | grep 8081`
3. Проверьте firewall: `sudo ufw status`

### Проблема: Nginx 502 Bad Gateway

**Решение**:
1. Проверьте PHP-FPM: `sudo systemctl status php8.1-fpm`
2. Проверьте права: `sudo chown -R www-data:www-data storage bootstrap/cache`
3. Проверьте логи Nginx: `sudo tail -f /var/log/nginx/error.log`

---

## 📊 Мониторинг

### Логи

```bash
# Application logs
tail -f storage/logs/laravel.log

# Systemd logs
sudo journalctl -u intermediate-app -f
sudo journalctl -u intermediate-queue -f
sudo journalctl -u intermediate-ws -f

# Nginx logs
sudo tail -f /var/log/nginx/access.log
sudo tail -f /var/log/nginx/error.log
```

### Метрики

```bash
# Health check
curl http://localhost/api/health

# Metrics
curl http://localhost/api/metrics

# Queue size
redis-cli llen queues:default
```

---

## 🔒 Безопасность

### Рекомендации для production

1. **Настройте firewall**:
   ```bash
   sudo ufw allow 80/tcp
   sudo ufw allow 443/tcp
   sudo ufw allow 8081/tcp  # WebSocket
   sudo ufw enable
   ```

2. **Настройте SSL/TLS**:
   - Используйте Let's Encrypt: `sudo certbot --nginx`
   - Или установите свои сертификаты в Nginx конфигурацию

3. **Настройте Redis password**:
   ```bash
   # В /etc/redis/redis.conf
   requirepass your-strong-password
   
   # В .env
   REDIS_PASSWORD=your-strong-password
   ```

4. **Настройте API authentication**:
   ```bash
   # В .env
   API_BEARER_TOKEN=$(openssl rand -base64 32)
   ```

5. **Ограничьте доступ к Redis**:
   ```bash
   # В /etc/redis/redis.conf
   bind 127.0.0.1
   ```

---

## 📝 Обновление

### Обновление кода

```bash
# 1. Остановите сервисы
sudo systemctl stop intermediate-app intermediate-queue intermediate-ws

# 2. Обновите код
cd /var/www/html/intermediate-service
git pull origin main

# 3. Обновите зависимости
composer install --no-dev --optimize-autoloader

# 4. Запустите миграции
php artisan migrate --force

# 5. Очистите кэш
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 6. Запустите сервисы
sudo systemctl start intermediate-app intermediate-queue intermediate-ws
```

---

## 🎯 Checklist перед production

- [ ] `.env` настроен с production значениями
- [ ] `APP_ENV=production` и `APP_DEBUG=false`
- [ ] `APP_KEY` сгенерирован
- [ ] Redis настроен с password
- [ ] AWS credentials настроены
- [ ] API_BEARER_TOKEN установлен
- [ ] SSL/TLS сертификаты установлены
- [ ] Firewall настроен
- [ ] Systemd services установлены и запущены
- [ ] Nginx настроен и запущен
- [ ] Health check возвращает "healthy"
- [ ] Тесты проходят: `php artisan test`
- [ ] Логи ротируются
- [ ] Мониторинг настроен

---

**Версия**: 1.0.0  
**Дата**: 10 ноября 2025  
**Статус**: ✅ Production Ready
