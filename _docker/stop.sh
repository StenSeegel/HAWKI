#!/bin/bash
set -e

# Show help function
show_help() {
    echo "HAWKI Docker Stop Script"
    echo ""
    echo "Usage: ./stop.sh [--dev|--staging|--auto] [--remove]"
    echo ""
    echo "Profiles:"
    echo "  --dev, --development   Stop development containers"
    echo "  --staging              Stop staging containers"
    echo "  --auto                 Auto-detect running environment (default)"
    echo ""
    echo "Options:"
    echo "  --remove               Also remove build volumes (forces rebuild)"
    echo "  --help, -h             Show this help message"
    echo ""
    echo "Examples:"
    echo "  ./stop.sh              # Auto-detect and stop"
    echo "  ./stop.sh --auto       # Same as above"
    echo "  ./stop.sh --dev"
    echo "  ./stop.sh --staging --remove"
    echo ""
    echo "Note: Database volumes are NEVER removed automatically!"
    exit 0
}

# Default values
PROFILE=""
AUTO_DETECT=true
REMOVE_BUILD_VOLUMES=false

# Parse arguments
for arg in "$@"; do
    case $arg in
        --help|-h)
            show_help
            ;;
        --auto)
            AUTO_DETECT=true
            ;;
        --dev|--development)
            PROFILE="dev"
            AUTO_DETECT=false
            ;;
        --staging)
            PROFILE="staging"
            AUTO_DETECT=false
            ;;
        --remove)
            REMOVE_BUILD_VOLUMES=true
            ;;
        *)
            echo "❌ Unknown argument: $arg"
            echo ""
            echo "Usage: ./stop.sh [--dev|--staging|--auto] [--remove]"
            echo ""
            echo "Run './stop.sh --help' for more information"
            exit 1
            ;;
    esac
done

# Auto-detect profile if not specified
if [ "$AUTO_DETECT" = true ]; then
    echo "🔍 Auto-detecting running environment..."
    
    # Check for running containers
    if docker ps --format '{{.Names}}' | grep -q '^hawki-dev-'; then
        PROFILE="dev"
        echo "✓ Detected: Development environment"
    elif docker ps --format '{{.Names}}' | grep -q '^hawki-staging-'; then
        PROFILE="staging"
        echo "✓ Detected: Staging environment"
    else
        echo "❌ No running HAWKI containers found!"
        echo ""
        echo "💡 You can specify the profile manually:"
        echo "   ./stop.sh --dev"
        echo "   ./stop.sh --staging"
        exit 1
    fi
    echo ""
fi

# Check if profile is set
if [ -z "$PROFILE" ]; then
    echo "❌ Error: No profile specified and auto-detection failed!"
    echo ""
    echo "Usage: ./stop.sh [--dev|--staging] [--remove]"
    exit 1
fi

# Convert profile to uppercase for display
PROFILE_UPPER=$(echo "$PROFILE" | tr '[:lower:]' '[:upper:]')

echo "🛑 Stopping HAWKI ${PROFILE_UPPER} Containers..."
echo ""

# Stop containers
echo "⏸️  Stopping and removing containers..."
docker compose -f "compose/docker-compose.${PROFILE}.yml" --env-file env/.env down

echo "✅ Containers stopped!"
echo ""

# Remove build volumes if requested
if [ "$REMOVE_BUILD_VOLUMES" = true ]; then
    echo "🗑️  Removing build volumes..."
    
    case $PROFILE in
        dev)
            docker volume rm hawki-dev_dev_vendor 2>/dev/null || echo "   ℹ️  Volume hawki-dev_dev_vendor not found"
            docker volume rm hawki-dev_dev_node_modules 2>/dev/null || echo "   ℹ️  Volume hawki-dev_dev_node_modules not found"
            ;;
        staging)
            docker volume rm hawki-staging_staging_public 2>/dev/null || echo "   ℹ️  Volume hawki-staging_staging_public not found"
            docker volume rm hawki-staging_staging_build 2>/dev/null || echo "   ℹ️  Volume hawki-staging_staging_build not found"
            ;;
    esac
    
    echo ""
    echo "✅ Build volumes removed! Next deployment will rebuild."
    echo ""
fi

echo "═══════════════════════════════════════════════════════"
echo "📋 Quick Reference:"
echo "   ./stop.sh                    # Auto-detect and stop"
echo "   ./stop.sh --dev              # Stop development"
echo "   ./stop.sh --staging          # Stop staging"
echo "   ./stop.sh --prod             # Stop production"
echo "   ./stop.sh --remove           # Auto-detect + remove builds"
echo ""
echo "⚠️  Database volumes are NEVER removed automatically!"
echo "═══════════════════════════════════════════════════════"
