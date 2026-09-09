#!/bin/bash
# =====================================================
# Run Post-Deployment Commands
# =====================================================
# This script executes Artisan commands defined in
# dev-cmds file after deployment
# =====================================================

set -e

COMMANDS_FILE="env/dev-cmds"
COMPOSE_FILE="compose/docker-compose.dev.yml"

# Check if commands file exists
if [ ! -f "$COMMANDS_FILE" ]; then
    echo "ℹ️  No post-deployment commands file found, skipping"
    exit 0
fi

# Count non-empty, non-comment lines
COMMAND_COUNT=$(grep -v '^#' "$COMMANDS_FILE" | grep -v '^[[:space:]]*$' | wc -l | tr -d ' ')

if [ "$COMMAND_COUNT" -eq 0 ]; then
    echo "ℹ️  No post-deployment commands defined, skipping"
    exit 0
fi

echo "🔧 Running post-deployment commands..."
echo "   Found $COMMAND_COUNT command(s) in $COMMANDS_FILE"
echo ""

# Change to parent directory to access docker-compose
cd ..

# Read commands file and execute each command
EXECUTED_COUNT=0
FAILED_COUNT=0
while IFS= read -r command || [ -n "$command" ]; do
    # Skip empty lines and comments
    if [[ -z "$command" ]] || [[ "$command" =~ ^[[:space:]]*# ]]; then
        continue
    fi
    
    # Trim whitespace
    command=$(echo "$command" | xargs)
    
    echo "   → Running: php artisan $command"
    
    # Execute via docker compose exec
    if docker compose -f _docker/${COMPOSE_FILE} exec -T app php artisan $command; then
        echo "   ✓ Success"
        EXECUTED_COUNT=$((EXECUTED_COUNT + 1))
    else
        echo "   ✗ Failed (exit code: $?)"
        FAILED_COUNT=$((FAILED_COUNT + 1))
    fi
    echo ""
done < "_docker/$COMMANDS_FILE"

# Summary
if [ "$FAILED_COUNT" -eq 0 ]; then
    echo "✅ Successfully executed $EXECUTED_COUNT post-deployment command(s)"
else
    echo "⚠️  Executed $EXECUTED_COUNT command(s), $FAILED_COUNT failed"
fi

# Return to original directory
cd _docker
