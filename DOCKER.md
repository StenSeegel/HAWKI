# Docker Production Deployment

For Docker deployment instructions, see [`_docker_production/README.md`](_docker_production/README.md).

## Quick Start

# Docker Production Deployment

For detailed Docker deployment instructions, see [`_docker_production/README.md`](_docker_production/README.md).

## Quick Start

### Production with Official HAWK Image
```bash
cd _docker_production
./deploy.sh  # Uses pre-built HAWK-provided image
```

### Production/Test with Custom Modifications
```bash
cd _docker_production
./deploy-dev.sh  # Builds from current repository
```

### Active Development (Live Code)
```bash
cd _docker_production
./deploy-live.sh --build  # Initial setup

# Quick updates during development
git pull
cd _docker_production
./update-live.sh  # Changes live in ~10 seconds
```

## Deployment Strategy

| Script | Use Case | Code Source | Update Time |
|--------|----------|-------------|-------------|
| **deploy.sh** | Production (Official HAWK) | HAWK Registry | Fast (no build) |
| **deploy-dev.sh** | Production/Test (Custom) | Built from Repo | ~10 min (rebuild) |
| **deploy-live.sh** | Active Development | Live Volume | ~10 sec (no rebuild) |

## File Structure

- **`Dockerfile`** - Multi-stage build for production (must stay in root for build context)
- **`_docker_production/`** - All Docker deployment configs and scripts
- **Local Development** - Uses Laravel HERD (no Docker needed)

## File Structure

- **`Dockerfile`** - Multi-stage build for production (must stay in root for build context)
- **`_docker_production/`** - All Docker deployment configs and scripts
- **Local Development** - Uses Laravel HERD (no Docker needed)
