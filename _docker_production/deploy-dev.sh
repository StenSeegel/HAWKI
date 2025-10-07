#!/bin/bash
set -e  # Exit on error

echo "🚀 Starting HAWKI Live Deployment (with live code)..."

# Check if .env file exists
if [ ! -f ".env" ]; then
    echo "❌ Error: .env file not found in _docker_production directory!"
    echo "   Please create .env file from .env.example"
    exit 1
fi

# Function to generate encryption key
generate_key() {
    echo "base64:$(openssl rand -base64 32)"
}

# Auto-generate missing encryption keys
echo "🔑 Checking encryption keys..."
KEYS_UPDATED=0

if ! grep -q "^APP_KEY=" .env || grep -q "^APP_KEY=$" .env || grep -q "^APP_KEY= $" .env; then
    echo "   Generating APP_KEY..."
    echo "APP_KEY=$(generate_key)" >> .env
    KEYS_UPDATED=1
fi

if ! grep -q "^USERDATA_ENCRYPTION_SALT=" .env || grep -q "^USERDATA_ENCRYPTION_SALT=$" .env; then
    echo "   Generating USERDATA_ENCRYPTION_SALT..."
    echo "USERDATA_ENCRYPTION_SALT=$(generate_key)" >> .env
    KEYS_UPDATED=1
fi

if ! grep -q "^INVITATION_SALT=" .env || grep -q "^INVITATION_SALT=$" .env; then
    echo "   Generating INVITATION_SALT..."
    echo "INVITATION_SALT=$(generate_key)" >> .env
    KEYS_UPDATED=1
fi

if ! grep -q "^AI_CRYPTO_SALT=" .env || grep -q "^AI_CRYPTO_SALT=$" .env; then
    echo "   Generating AI_CRYPTO_SALT..."
    echo "AI_CRYPTO_SALT=$(generate_key)" >> .env
    KEYS_UPDATED=1
fi

if ! grep -q "^PASSKEY_SALT=" .env || grep -q "^PASSKEY_SALT=$" .env; then
    echo "   Generating PASSKEY_SALT..."
    echo "PASSKEY_SALT=$(generate_key)" >> .env
    KEYS_UPDATED=1
fi

if ! grep -q "^BACKUP_SALT=" .env || grep -q "^BACKUP_SALT=$" .env; then
    echo "   Generating BACKUP_SALT..."
    echo "BACKUP_SALT=$(generate_key)" >> .env
    KEYS_UPDATED=1
fi

if [ $KEYS_UPDATED -eq 1 ]; then
    echo "✅ Encryption keys generated and added to .env"
else
    echo "✅ All encryption keys already present"
fi

# Generate nginx configuration from template
if [ -f "generate-nginx-config.sh" ]; then
    echo "🔧 Generating Nginx configuration..."
    
    # Ensure NGINX_SERVER_NAME is set for dev mode
    if ! grep -q "^NGINX_SERVER_NAME=" .env; then
        echo "NGINX_SERVER_NAME=app.hawki.dev" >> .env
    fi
    if ! grep -q "^NGINX_HTTP_PORT=" .env; then
        echo "NGINX_HTTP_PORT=80" >> .env
    fi
    if ! grep -q "^NGINX_HTTPS_PORT=" .env; then
        echo "NGINX_HTTPS_PORT=443" >> .env
    fi
    
    ./generate-nginx-config.sh
fi

# Fix storage permissions for dev mode (must match container UID)
if [ -d "./storage" ]; then
    echo "📁 Fixing storage permissions for dev mode..."
    # Get UID/GID from .env or use defaults
    STORAGE_UID=$(grep -E "^DOCKER_UID=" .env | cut -d '=' -f2- | tr -d '"' | tr -d "'" || echo "501")
    STORAGE_GID=$(grep -E "^DOCKER_GID=" .env | cut -d '=' -f2- | tr -d '"' | tr -d "'" || echo "20")
    
    # Change ownership (will ask for password if needed)
    sudo chown -R ${STORAGE_UID}:${STORAGE_GID} ./storage
    chmod -R 755 ./storage
    find ./storage -type f -exec chmod 644 {} \;
    echo "✅ Storage permissions fixed (UID:${STORAGE_UID}, GID:${STORAGE_GID})"
fi

# Setup local dev domains in /etc/hosts
echo "🌐 Setting up local dev domains..."
HOSTS_ENTRIES="127.0.0.1 app.hawki.dev db.hawki.dev"

if ! grep -q "app.hawki.dev" /etc/hosts; then
    echo "   Adding app.hawki.dev and db.hawki.dev to /etc/hosts..."
    echo "$HOSTS_ENTRIES" | sudo tee -a /etc/hosts > /dev/null
    echo "✅ Local domains added to /etc/hosts"
else
    echo "✅ Local domains already in /etc/hosts"
fi

# Build from parent directory (where Dockerfile is located)
cd ..

# Load proxy configuration from .env file
if [ -f "_docker_production/.env" ]; then
    export HTTP_PROXY=$(grep -E "^DOCKER_HTTP_PROXY=" _docker_production/.env | cut -d '=' -f2- | tr -d '"' | tr -d "'")
    export HTTPS_PROXY=$(grep -E "^DOCKER_HTTPS_PROXY=" _docker_production/.env | cut -d '=' -f2- | tr -d '"' | tr -d "'")
    export NO_PROXY=$(grep -E "^DOCKER_NO_PROXY=" _docker_production/.env | cut -d '=' -f2- | tr -d '"' | tr -d "'")
fi

# Only set proxy if values are not empty
if [ -n "$HTTP_PROXY" ]; then
    echo "🌐 Using proxy: $HTTP_PROXY"
    PROXY_ARGS="--build-arg HTTP_PROXY=$HTTP_PROXY --build-arg HTTPS_PROXY=$HTTPS_PROXY --build-arg NO_PROXY=$NO_PROXY"
else
    PROXY_ARGS=""
fi

# Build app image (only needed first time or after Dockerfile changes)
if [ "$1" == "--build" ] || [ ! "$(docker images -q ${PROJECT_HAWKI_IMAGE} 2> /dev/null)" ]; then
    echo "🔨 Building app image..."
    docker compose -f _docker_production/docker-compose.dev.yml build \
      $PROXY_ARGS \
      --pull app
fi

echo "🚢 Starting containers with live code..."
docker compose -f _docker_production/docker-compose.dev.yml up -d --force-recreate --remove-orphans

# Wait for containers to be ready
echo "⏳ Waiting for containers to be ready..."
sleep 5

# Update Composer dependencies (WITH dev dependencies for development)
echo "📦 Installing/Updating Composer dependencies..."
docker compose -f _docker_production/docker-compose.dev.yml exec app composer install --optimize-autoloader

# Note: NPM should be run on HOST in dev mode (live mount)
echo "� For frontend changes, run on your HOST machine:"
echo "   npm install"
echo "   npm run dev   # or npm run build"

# Run Laravel setup (without route:cache due to Laravel 12 bug)
echo "⚙️  Running Laravel setup..."
docker compose -f _docker_production/docker-compose.dev.yml exec app bash -c "php artisan migrate --force && \
    php artisan db:seed --force && \
    php artisan storage:link && \
    php artisan optimize:clear"

# Generate git info
echo "📝 Generating Git info..."
docker compose -f _docker_production/docker-compose.dev.yml exec app bash -c "git config --global --add safe.directory /var/www/html && /var/www/html/git_info.sh"

# Get configuration from .env file
cd _docker_production
APP_URL=$(grep -E "^APP_URL=" .env | cut -d '=' -f2- | sed 's/#.*//' | tr -d '"' | tr -d "'" | xargs)
DOCKER_PROJECT_IP=$(grep -E "^DOCKER_PROJECT_IP=" .env | cut -d '=' -f2- | sed 's/#.*//' | tr -d '"' | tr -d "'" | xargs)

# Determine if we're running locally or on remote server
if [ -z "$DOCKER_PROJECT_IP" ] || [ "$DOCKER_PROJECT_IP" = "127.0.0.1" ] || [ "$DOCKER_PROJECT_IP" = "0.0.0.0" ]; then
    # Local development - Docker exposes ports
    IS_LOCAL=true
else
    IS_LOCAL=false
fi

echo ""
echo "✅ Live deployment complete!"
echo ""
echo "🌐 Access your application:"
if [ "$IS_LOCAL" = true ]; then
    echo "   → https://app.hawki.dev (HAWKI Application)"
    echo "   → https://db.hawki.dev (Adminer - Database Management)"
    echo ""
    echo "   Alternative URLs:"
    echo "   → http://localhost (Application)"
    if [ -n "$APP_URL" ] && [ "$APP_URL" != "http://localhost" ]; then
        echo "   → $APP_URL (Remote/Production URL in .env)"
    fi
else
    # Remote server
    if [ -n "$APP_URL" ]; then
        echo "   → $APP_URL"
    else
        echo "   → http://localhost"
    fi
fi
echo ""
echo "💡 Code is now live-mounted from the repository."
echo "   To update code: git pull && cd _docker_production && ./update-dev.sh"
echo ""
echo "🔧 To force rebuild: ./deploy-dev.sh --build"
