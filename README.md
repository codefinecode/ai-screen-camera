# Edge AI Intermediate Service

Laravel-based intermediate service for Edge AI metadata collection system. Receives anonymous demographic/behavioral metadata from edge devices, triggers real-time player content changes, and forwards data to AWS.

## Features

- **Frame Ingestion**: JSON/NDJSON format support with gzip compression
- **GDPR Compliant**: Automatic removal of image data before logging/storage
- **Real-time Triggers**: SSE and WebSocket support for instant player reactions
- **AWS Integration**: Reliable forwarding with retry mechanism and offline buffering
- **Data Aggregation**: Configurable bucketing (hourly8, day, week, month, year)
- **Production Ready**: Rate limiting, authentication, comprehensive error handling

## Requirements

- PHP 8.1+
- Redis 6.0+
- Composer 2.0+
- Laravel 12

## Quick Start

```bash
# Install dependencies
composer install

# Configure environment
cp .env.example .env
php artisan key:generate

# Set up Redis connection in .env
REDIS_HOST=127.0.0.1
QUEUE_CONNECTION=redis

# Run migrations
php artisan migrate

# Start services
php artisan serve --host=0.0.0.0 --port=8080
php artisan queue:work --tries=10
php artisan ws:serve --host=0.0.0.0 --port=8081
```

## API Endpoints

### Frame Ingestion
```bash
POST /api/v1/frames
Content-Type: application/json

{
  "timestamp": 1741709337,
  "playerUUID": "screen-1",
  "faceDetections": [...]
}
```

### Player State
```bash
POST /api/player/state
Content-Type: application/json

{
  "type": "player.state",
  "data": {
    "playerId": "player-1",
    "content": [{"contentId": "123", "contentType": "media"}]
  }
}
```

### Dashboard Analytics
```bash
GET /api/dashboards/frames?filter[start]=2025-01-01T00:00:00Z&filter[end]=2025-01-01T12:00:00Z&filter[screenIds]=screen-1
```

### Health Check
```bash
GET /api/health
```

## WebSocket Protocol

Connect to `ws://localhost:8081`

**Client → Server:**
- `player.hello` - Initial handshake
- `player.state` - Content state updates
- `player.triggers` - Trigger rule configuration

**Server → Client:**
- `event.ack` - Acknowledgment
- `event.triggerStart` - Trigger activated
- `event.triggerEnd` - Trigger deactivated

## Configuration

### Required Environment Variables

```bash
# Application
APP_ENV=production
APP_DEBUG=false

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# Queue
QUEUE_CONNECTION=redis

# AWS Integration
AWS_INGEST_URL=https://your-aws-api.com/ingest/frames
AWS_QUERY_URL=https://your-aws-api.com/query/frames
AWS_BEARER_TOKEN=your-token-here
```

### Optional Configuration

```bash
# Security
API_BEARER_TOKEN=your-api-token
API_FRAMES_RATE_LIMIT=1000
API_MAX_PAYLOAD_SIZE_MB=10

# Trigger Engine
TRIGGER_THROTTLE_MS=300
TRIGGER_ACTIVE_TTL=3600

# Aggregation
IMPRESSION_GAP_SEC=5
AGGREGATION_CACHE_TTL=300
AGGREGATION_MAX_FRAMES=10000
```

## Deployment

### Docker

```bash
cd _deployment/docker
docker-compose up -d
```

### Systemd (Production)

```bash
# Run deployment script
sudo ./_deployment/scripts/deploy.sh

# Install systemd services
sudo cp _deployment/systemd/*.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable intermediate-app intermediate-queue intermediate-ws
sudo systemctl start intermediate-app intermediate-queue intermediate-ws

# Configure Nginx
sudo cp _deployment/nginx/nginx.conf /etc/nginx/sites-available/intermediate-service
sudo ln -s /etc/nginx/sites-available/intermediate-service /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

See [_deployment/README.md](_deployment/README.md) for detailed instructions.

## Testing

```bash
# Run all tests
php artisan test

# Test frame ingestion
php bin/sim-frames.php http://localhost:8080/api/v1/frames 5

# Test WebSocket connection
# Open examples/player-sim.html in browser
```

## Architecture

```
Edge Device → POST /api/v1/frames → FramesController
                                    ↓
                              FrameIngestService
                                    ↓
                    ┌───────────────┼───────────────┐
                    ↓               ↓               ↓
            TriggerEngine    PlayerStateRepo   ForwardFramesToAws
                    ↓               ↓               ↓
            SSE/WebSocket         Redis          AWS API
```

## Security & GDPR

- **Image Data Removal**: `imgDataBase64` automatically removed before logging
- **Rate Limiting**: 1000 requests/minute (configurable)
- **Payload Validation**: 10MB max size (configurable)
- **Authentication**: Bearer token support for production
- **TLS**: All external connections use HTTPS

See [docs/DPIA.md](docs/DPIA.md) for Data Protection Impact Assessment.

## Documentation

- **[API Specification](docs/openapi-dashboards.yaml)** - OpenAPI spec for dashboards endpoint
- **[DPIA](docs/DPIA.md)** - Data Protection Impact Assessment
- **[Deployment Guide](_deployment/README.md)** - Detailed deployment instructions

## License

Proprietary - All rights reserved
