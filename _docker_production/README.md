# HAWKI Docker Deployment

This directory contains all Docker production and staging deployment configurations.

## 📁 File Structure

```
_docker_production/
├── docker-compose.yml          # Production: Code baked into image
├── d## 🔐 Proxy Configuration

Proxy settings are configured in the `.env` file:

```bash
# Docker Build Proxy Configuration (for university network)
# Leave empty if no proxy is needed
DOCKER_HTTP_PROXY=http://10.60.3.254:3128
DOCKER_HTTPS_PROXY=http://10.60.3.254:3128
DOCKER_NO_PROXY=localhost,127.0.0.1
```

**How it works:**
- Deploy scripts automatically read proxy settings from `.env`
- If `DOCKER_HTTP_PROXY` is empty, no proxy is used
- Proxy is only used during Docker build for:
  - `apt-get` package downloads
  - `npm install` during build
  - `composer install` during build

**For local development without proxy:**
```bash
# Leave proxy settings empty in .env
DOCKER_HTTP_PROXY=
DOCKER_HTTPS_PROXY=
DOCKER_NO_PROXY=localhost,127.0.0.1
```live.yml     # Development/Testing: Live code mounting
├── deploy.sh                   # Deploy with HAWK-provided official image
├── deploy-dev.sh              # Deploy with custom build (own modifications)
├── deploy-live.sh             # Deploy for active development (live code)
├── update-live.sh             # Quick update for live setup (no rebuild)
├── .env                       # Environment variables
├── storage/                   # Persistent storage
├── config/                    # Production config overrides
└── certs/                     # SSL certificates
```

## 🚀 Deployment Workflows

### 1. Production with Official HAWK Image

**Use Case**: Production servers using the official HAWK-provided Docker image

```bash
cd _docker_production
./deploy.sh
```

**Characteristics**:
- ✅ Uses pre-built official image from HAWK registry
- ✅ Maximum stability and tested deployment
- ✅ Fast deployment (no build required)
- ❌ No custom code modifications possible

**When to use**:
- Production servers with official HAWK releases
- When you want the stable, tested version
- Standard deployments without modifications

---

### 2. Production/Test with Custom Modifications

**Use Case**: Production or test servers with your own code modifications

```bash
cd _docker_production
./deploy-dev.sh
```

**Characteristics**:
- ✅ Builds image from current repository code
- ✅ Includes your custom modifications
- ✅ Code baked into image (immutable deployment)
- ❌ Requires full rebuild (~10 minutes) for changes
- ❌ `git pull` has no effect (code in image)

**When to use**:
- Test servers with custom features
- Production with approved modifications
- When you need custom code but stable deployment
- Before contributing changes back to HAWK

---

### 3. Development/Testing During Active Development

**Use Case**: Development during active coding - fast iterations without rebuilds

#### Initial Setup:
```bash
cd _docker_production
./deploy-live.sh --build
```

#### Quick Updates (Fast Development Cycle):
```bash
cd ~/HAWKI
git pull  # or make local changes
cd _docker_production
./update-live.sh  # ~10 seconds instead of 10 minutes!
```

**Characteristics**:
- ✅ Code mounted live from repository (`..:/var/www/html`)
- ✅ Changes immediately available (just refresh browser)
- ✅ Perfect for rapid development cycles
- ✅ `git pull` → changes instantly live
- ⚠️ Not for production (live code mounting)

**When to use**:
- Active development and testing
- Rapid prototyping
- Feature development
- Bug fixing with quick iterations
- Before building final image with `deploy-dev.sh`

---

## 📊 Deployment Comparison

| Feature | Official Image<br>`deploy.sh` | Custom Build<br>`deploy-dev.sh` | Live Development<br>`deploy-live.sh` |
|---------|------------------------------|----------------------------------|--------------------------------------|
| **Use Case** | Production (HAWK Official) | Production/Test (Custom Code) | Active Development |
| **Image Source** | HAWK Registry | Built from Repo | Built from Repo |
| **Code Location** | Pre-built Image | Inside Built Image | Live Volume Mount |
| **Build Time** | None (pull only) | ~10 minutes | ~10 min (first time) |
| **Update Time** | Pull + Restart | ~10 minutes (rebuild) | ~10 seconds (`update-live.sh`) |
| **git pull Effect** | ❌ None | ❌ None (need rebuild) | ✅ Immediate |
| **Custom Code** | ❌ Not possible | ✅ Included in build | ✅ Live changes |
| **Stability** | 🔒 Highest | 🔒 High | ⚠️ Development only |
| **Security** | 🔒 Highest | 🔒 High | ⚠️ Medium |

---

## 🔄 Typical Development Workflow

### Phase 1: Active Development
```bash
# Start with live code for fast iterations
./deploy-live.sh --build

# Make changes, test immediately
vim ../app/Http/Controllers/SomeController.php
./update-live.sh  # 10 seconds

# Keep iterating...
git pull
./update-live.sh
```

### Phase 2: Testing & Approval
```bash
# Build custom image for stable testing
./deploy-dev.sh

# Test thoroughly with immutable deployment
# If issues found, go back to Phase 1
```

### Phase 3: Production
```bash
# Option A: Deploy with your custom build
./deploy-dev.sh

# Option B: Contribute to HAWK, then use official image
PR to https://github.com/hawk-digital-environments/HAWKI.git

# Wait for HAWK team to build official image
./deploy.sh
```

---

## 🏗️ Architecture

### Why is Dockerfile in Root?

The `Dockerfile` **must** stay in the root directory because:

1. **Build Context**: Docker needs access to entire codebase
   ```dockerfile
   COPY --chown=www-data:www-data . .
   ```

2. **Multi-Stage Build**: Node builder needs `package.json`, `resources/`, etc.
   ```dockerfile
   FROM node:23-bookworm AS node_builder
   COPY --chown=node:node . .
   RUN npm install && npm run build
   ```

3. **All docker-compose files reference it**:
   ```yaml
   build:
     context: ..              # Points to repository root
     dockerfile: Dockerfile   # Dockerfile in root
   ```

### Build Context Explanation

```
HAWKI/                          ← Build context (root)
├── Dockerfile                  ← Build instructions
├── app/                        ← PHP code (needed for build)
├── resources/                  ← Frontend code (needed for build)
├── public/                     ← Static assets
├── package.json               ← NPM dependencies
├── composer.json              ← PHP dependencies
└── _docker_production/
    ├── docker-compose.yml     ← References ../Dockerfile
    └── deploy-dev.sh          ← cd .. && docker compose build
```

---

## 🔧 Configuration Files

### docker-compose.yml (Production & Custom Build)
- Uses `build: context: .. / dockerfile: Dockerfile`
- Code is **inside** the Docker image
- Mounts only: storage, config overrides
- Target: `app_prod` (optimized, no dev tools)
- Used by: `deploy.sh` (pull image) & `deploy-dev.sh` (build image)

### docker-compose.live.yml (Development)
- Uses same build context
- Code is **live-mounted**: `- ..:/var/www/html`
- Mounts: entire repository + storage overrides
- Target: `app_prod` (but with live code)
- Used by: `deploy-live.sh` & `update-live.sh`

---

## 🌍 Environment Variables

Configure your deployment in `.env`:

```bash
PROJECT_NAME=hawki-prod
PROJECT_HAWKI_IMAGE=digitalenvironments/hawki:latest
APP_URL=https://your-domain.com
DB_DATABASE=hawki_production
DB_USERNAME=hawki
DB_PASSWORD=secret
```

---

##  Proxy Configuration

All deploy scripts automatically configure HTTP proxy for the university network:

```bash
export HTTP_PROXY="http://10.60.3.254:3128"
export HTTPS_PROXY="http://10.60.3.254:3128"
export NO_PROXY="localhost,127.0.0.1"
```

This is passed to Docker build via `--build-arg` for:
- `apt-get` package downloads
- `npm install` during build
- `composer install` during build

---

## 🐛 Troubleshooting

### Port Already Allocated (MySQL 3306)
```bash
# Stop conflicting containers
docker ps | grep mysql
docker stop <container-name>
```

### Build Fails (Network Timeout)
- Check proxy configuration in deploy scripts
- Verify university network access

### Live Code Not Updating
```bash
# Clear Laravel caches
cd _docker_production
./update-live.sh
```

### Permission Issues
```bash
# Fix storage permissions
chmod -R 755 storage
find storage -type f -exec chmod 644 {} \;
```

---

## 📝 Best Practices

1. **Production (Official)**: Use `deploy.sh` with HAWK-provided image
2. **Production (Custom)**: Use `deploy-dev.sh` after thorough testing
3. **Development**: Use `deploy-live.sh` for fast iterations
4. **Testing Flow**: `deploy-live.sh` → develop → `deploy-dev.sh` → test → `deploy.sh` (production)
5. **Git Info**: Commit ID is automatically stored in `storage/app/git_info.json`
6. **Backups**: Always backup `.env` and `storage/` before deploying

---

## 🆘 Support

For issues or questions:
- Check logs: `docker compose -f docker-compose.yml logs -f app`
- Inspect containers: `docker compose -f docker-compose.yml ps`
- Access container: `docker compose -f docker-compose.yml exec app bash`
