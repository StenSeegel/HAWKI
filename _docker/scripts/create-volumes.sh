#!/bin/bash

# =====================================================
# HAWKI - Volume Creation Script
# =====================================================
# Creates external Docker volumes for staging/prod
# Skips if volumes already exist
# =====================================================

set -e

PROFILE=${1:-staging}

echo "🔧 Checking external volumes for $PROFILE environment..."

CREATED=false

if [ "$PROFILE" = "staging" ]; then
    MYSQL_VOLUME="hawki-staging_mysql_data"
    REDIS_VOLUME="hawki-staging_redis_data"
elif [ "$PROFILE" = "prod" ]; then
    MYSQL_VOLUME="hawki-prod_mysql_data"
    REDIS_VOLUME="hawki-prod_redis_data"
else
    echo "❌ Unknown profile: $PROFILE"
    echo "Usage: $0 [staging|prod]"
    exit 1
fi

# Check and create MySQL volume
if ! docker volume inspect "$MYSQL_VOLUME" >/dev/null 2>&1; then
    echo "   Creating volume: $MYSQL_VOLUME"
    docker volume create "$MYSQL_VOLUME" >/dev/null
    CREATED=true
else
    echo "   Volume exists: $MYSQL_VOLUME ✓"
fi

# Check and create Redis volume
if ! docker volume inspect "$REDIS_VOLUME" >/dev/null 2>&1; then
    echo "   Creating volume: $REDIS_VOLUME"
    docker volume create "$REDIS_VOLUME" >/dev/null
    CREATED=true
else
    echo "   Volume exists: $REDIS_VOLUME ✓"
fi

if [ "$CREATED" = true ]; then
    echo "✅ Created new volumes for $PROFILE"
else
    echo "✅ All volumes already exist"
fi
