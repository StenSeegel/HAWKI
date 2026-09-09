#!/bin/bash

# =====================================================
# Download HAWKI Backup from Staging Server
# =====================================================
# Usage: ./download-staging-backup.sh [backup-filename]
# =====================================================

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

BACKUP_DIR="$HOME/Downloads/hawki-backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}HAWKI Staging Backup Download${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""

# Interactive prompts
echo -e "${BLUE}Please provide server connection details:${NC}"
echo ""

read -p "SSH Username: " SSH_USER
if [ -z "$SSH_USER" ]; then
    echo -e "${RED}❌ Username is required!${NC}"
    exit 1
fi

read -p "Server Hostname (e.g., app.hawki.dev): " SERVER_HOST
if [ -z "$SERVER_HOST" ]; then
    echo -e "${RED}❌ Hostname is required!${NC}"
    exit 1
fi

read -p "Deployment Profile (dev/staging/prod) [staging]: " DEPLOY_PROFILE
DEPLOY_PROFILE=${DEPLOY_PROFILE:-staging}

# Auto-generate container name based on convention: hawki-<deployment>-app
CONTAINER_NAME="hawki-${DEPLOY_PROFILE}-app"

STAGING_SERVER="${SSH_USER}@${SERVER_HOST}"

echo ""
echo -e "${YELLOW}📋 Connection Summary:${NC}"
echo "   Server: $STAGING_SERVER"
echo "   Profile: $DEPLOY_PROFILE"
echo "   Container: $CONTAINER_NAME"
echo ""

BACKUP_FILE="$1"

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}HAWKI Staging Backup Download${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""

# Create local backup directory
mkdir -p "$BACKUP_DIR"

# Step 1: Get latest backup filename from server if not provided
if [ -z "$BACKUP_FILE" ]; then
    echo -e "${YELLOW}📋 Finding latest backup on staging server...${NC}"
    BACKUP_PATH=$(ssh $STAGING_SERVER "sudo docker exec $CONTAINER_NAME bash -c \"ls -t /var/www/html/storage/app/HAWKI2/*.zip 2>/dev/null | head -1\"")
    
    if [ -z "$BACKUP_PATH" ]; then
        echo -e "${YELLOW}⚠️  No backup found on staging server!${NC}"
        echo ""
        echo -e "${BLUE}Would you like to create a new backup now?${NC}"
        read -p "Create backup? (yes/no): " CREATE_BACKUP
        
        if [[ "$CREATE_BACKUP" == "yes" || "$CREATE_BACKUP" == "y" ]]; then
            echo ""
            echo -e "${YELLOW}🔄 Creating new backup on staging server...${NC}"
            echo -e "${YELLOW}   This may take a few moments...${NC}"
            
            ssh $STAGING_SERVER "sudo docker exec $CONTAINER_NAME php artisan backup:run --only-db"
            
            echo ""
            echo -e "${GREEN}✅ Backup created successfully!${NC}"
            echo -e "${YELLOW}📋 Finding the new backup...${NC}"
            
            # Try again to find the backup
            BACKUP_PATH=$(ssh $STAGING_SERVER "sudo docker exec $CONTAINER_NAME bash -c \"ls -t /var/www/html/storage/app/HAWKI2/*.zip 2>/dev/null | head -1\"")
            
            if [ -z "$BACKUP_PATH" ]; then
                echo -e "${RED}❌ Error: Backup was created but could not be found!${NC}"
                exit 1
            fi
        else
            echo ""
            echo -e "${YELLOW}Backup download cancelled.${NC}"
            exit 0
        fi
    fi
    
    BACKUP_FILENAME=$(basename "$BACKUP_PATH")
    echo -e "${GREEN}✅ Found: $BACKUP_FILENAME${NC}"
else
    BACKUP_FILENAME="$BACKUP_FILE"
    BACKUP_PATH="/var/www/html/storage/app/HAWKI2/$BACKUP_FILENAME"
fi

echo ""
echo -e "${YELLOW}🔍 Backup details:${NC}"
echo "   Filename: $BACKUP_FILENAME"
echo "   Server: $STAGING_SERVER"
echo "   Container: $CONTAINER_NAME"
echo ""

# Step 2: Copy from container to user home on server (via sudo + temp file)
echo -e "${YELLOW}📤 Copying backup from container to user home...${NC}"
echo -e "${YELLOW}   (This requires sudo access on the server)${NC}"

ssh $STAGING_SERVER "sudo docker cp $CONTAINER_NAME:$BACKUP_PATH /tmp/hawki-backup-temp.zip && \
                  sudo chown \$(whoami):\$(whoami) /tmp/hawki-backup-temp.zip && \
                  cp /tmp/hawki-backup-temp.zip ~/hawki-backup-temp.zip && \
                  sudo rm -f /tmp/hawki-backup-temp.zip"

echo -e "${GREEN}✅ Copied to server user home${NC}"
echo ""

# Step 3: Download to local machine
echo -e "${YELLOW}📥 Downloading to local machine...${NC}"
LOCAL_FILE="$BACKUP_DIR/hawki-staging-backup-$TIMESTAMP.zip"
scp $STAGING_SERVER:~/hawki-backup-temp.zip "$LOCAL_FILE"
echo -e "${GREEN}✅ Downloaded to: $LOCAL_FILE${NC}"
echo ""

# Step 4: Cleanup on server
echo -e "${YELLOW}🧹 Cleaning up on server...${NC}"
ssh $STAGING_SERVER "rm ~/hawki-backup-temp.zip"
echo -e "${GREEN}✅ Cleanup complete${NC}"
echo ""

# Step 5: Show file info
FILE_SIZE=$(du -h "$LOCAL_FILE" | cut -f1)
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}✅ Download complete!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "📁 Location: $LOCAL_FILE"
echo "📊 Size: $FILE_SIZE"
echo ""
echo "💡 Next steps:"
echo ""
echo "   1. Import to local dev database:"
echo "      cd _docker/scripts"
echo "      ./import-backup.sh \"$LOCAL_FILE\""
echo ""
echo "   2. Or extract manually:"
echo "      unzip -l \"$LOCAL_FILE\"  # List contents"
echo "      unzip \"$LOCAL_FILE\" -d /tmp/backup-extract  # Extract"
echo ""
echo "   3. Check encryption salts before import!"
echo "      Staging and Dev need matching encryption keys for users to login."
echo "      See: .env (APP_KEY, USERDATA_ENCRYPTION_SALT, etc.)"
