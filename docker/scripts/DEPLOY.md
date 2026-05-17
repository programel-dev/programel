# Deployment Guide

## DNS Setup

Create A-records pointing to your DigitalOcean droplet IP:

| Record | Type | Value |
|--------|------|-------|
| programel.com | A | <SERVER_IP> |
| test.programel.com | A | <SERVER_IP> |
| lebenslauf.programel.com | A | <SERVER_IP> |
| olcha.programel.com | A | <SERVER_IP> |

## SSL Certificates (first time)

```bash
certbot certonly --standalone \
    -d programel.com \
    -d test.programel.com \
    -d lebenslauf.programel.com \
    -d olcha.programel.com
```

## Cron Jobs

Add to deploy user's crontab (`crontab -e`):

```cron
# Database backup daily at 3:00 AM
0 3 * * * /var/www/programel/docker/scripts/backup.sh >> /var/log/programel-backup.log 2>&1

# Certbot auto-renewal twice daily
0 0,12 * * * certbot renew --quiet --deploy-hook "docker compose -f /var/www/programel/docker-compose.prod.yml exec -T nginx nginx -s reload"

# Docker cleanup weekly on Sunday at 4:00 AM
0 4 * * 0 docker system prune -af --filter "until=168h" >> /var/log/docker-prune.log 2>&1

# Disk monitoring every hour
0 * * * * /var/www/programel/docker/scripts/disk-monitor.sh >> /var/log/disk-monitor.log 2>&1
```

## GitHub Secrets Required

| Secret | Description |
|--------|-------------|
| SSH_PRIVATE_KEY | Deploy user's SSH private key |
| SERVER_HOST | DigitalOcean droplet IP |
| SERVER_USER | `deploy` |
