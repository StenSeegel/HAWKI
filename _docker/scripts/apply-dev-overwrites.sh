#!/bin/bash
# =====================================================
# Apply Development Configuration Overwrites
# =====================================================
# This script applies mandatory configuration overwrites
# for local development by updating database settings
# via Artisan commands
# =====================================================

set -e

OVERWRITES_FILE="env/dev-overwrites"
COMPOSE_FILE="compose/docker-compose.dev.yml"

# Check if overwrites file exists
if [ ! -f "$OVERWRITES_FILE" ]; then
    echo "ℹ️  No dev-overwrites file found, skipping overwrites"
    exit 0
fi

# Count non-empty, non-comment lines
OVERWRITE_COUNT=$(grep -v '^#' "$OVERWRITES_FILE" | grep -v '^[[:space:]]*$' | wc -l | tr -d ' ')

if [ "$OVERWRITE_COUNT" -eq 0 ]; then
    echo "ℹ️  dev-overwrites file is empty, skipping overwrites"
    exit 0
fi

echo "🔧 Applying development configuration overwrites..."
echo "   Found $OVERWRITE_COUNT override(s) in $OVERWRITES_FILE"
echo ""

# Change to parent directory to access docker-compose
cd ..

# Read overwrites file and apply each setting via tinker
APPLIED_COUNT=0
while IFS='=' read -r key value || [ -n "$key" ]; do
    # Skip empty lines and comments
    if [[ -z "$key" ]] || [[ "$key" =~ ^[[:space:]]*# ]]; then
        continue
    fi
    
    # Trim whitespace from key and value
    key=$(echo "$key" | xargs)
    value=$(echo "$value" | xargs)
    
    # Convert config key format: auth.local_authentication -> auth_local_authentication (database key)
    db_key=$(echo "$key" | tr '.' '_')
    
    # Determine the value type and construct proper PHP code
    if [[ "$value" =~ ^[0-9]+$ ]]; then
        # Numeric value
        php_value="$value"
        display_value="$value"
    elif [[ "$value" == "true" || "$value" == "false" ]]; then
        # Boolean value
        php_value="$value"
        display_value="$value"
    elif [[ "$value" == "null" ]]; then
        # Null value
        php_value="null"
        display_value="null"
    else
        # String value
        php_value="'$value'"
        display_value="'$value'"
    fi
    
    # Create PHP tinker code to update the setting
    TINKER_CODE="\$setting = \App\Models\AppSetting::where('key', '${db_key}')->first(); if (\$setting) { \$setting->value = ${php_value}; \$setting->save(); echo 'Updated'; } else { \App\Models\AppSetting::create(['key' => '${db_key}', 'value' => ${php_value}, 'type' => 'string']); echo 'Created'; }"
    
    # Execute via docker compose exec
    RESULT=$(docker compose -f _docker/${COMPOSE_FILE} exec -T app php artisan tinker --execute="$TINKER_CODE" 2>&1 | tail -n 1)
    
    if [[ "$RESULT" == *"Updated"* ]]; then
        echo "   ✓ Updated config('$key'): $display_value"
        APPLIED_COUNT=$((APPLIED_COUNT + 1))
    elif [[ "$RESULT" == *"Created"* ]]; then
        echo "   ✓ Created config('$key'): $display_value"
        APPLIED_COUNT=$((APPLIED_COUNT + 1))
    else
        echo "   ⚠️  Warning: Could not set config('$key')"
    fi
done < "_docker/$OVERWRITES_FILE"

# Clear config cache to ensure new values are loaded
echo ""
echo "🔄 Clearing configuration cache..."
docker compose -f _docker/${COMPOSE_FILE} exec -T app php artisan config:clear > /dev/null 2>&1

echo ""
echo "✅ Applied $APPLIED_COUNT development configuration overwrite(s)"

# Return to original directory
cd _docker

