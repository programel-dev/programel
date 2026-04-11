#!/usr/bin/env bash
set -euo pipefail

echo "=== Programel Server Setup ==="

# Update system
apt-get update && apt-get upgrade -y

# Install Docker
curl -fsSL https://get.docker.com | sh

# Install Docker Compose plugin
apt-get install -y docker-compose-plugin

# Create deploy user
useradd -m -s /bin/bash -G docker deploy
mkdir -p /home/deploy/.ssh
cp /root/.ssh/authorized_keys /home/deploy/.ssh/
chown -R deploy:deploy /home/deploy/.ssh
chmod 700 /home/deploy/.ssh
chmod 600 /home/deploy/.ssh/authorized_keys

# Create project directory
mkdir -p /opt/programel /backups
chown deploy:deploy /opt/programel /backups

# Configure UFW
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

# Install Certbot
apt-get install -y certbot

echo ""
echo "=== Setup complete ==="
echo "Next steps:"
echo "  1. Configure DNS A-records for all domains → $(curl -s ifconfig.me)"
echo "  2. Run: certbot certonly --standalone -d programel.com -d test.programel.com -d lebenslauf.programel.com -d olcha.programel.com"
echo "  3. Copy docker-compose.prod.yml and .env to /opt/programel/"
echo "  4. Run: docker compose -f docker-compose.prod.yml up -d"
