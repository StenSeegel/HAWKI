#!/bin/bash
set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Function to display help
show_help() {
    echo ""
    echo "═══════════════════════════════════════════════════════"
    echo "🔐 HAWKI Certificate Management"
    echo "═══════════════════════════════════════════════════════"
    echo ""
    echo "Usage: ./manage-certs.sh [COMMAND]"
    echo ""
    echo "Commands:"
    echo "  find        Find existing server certificates and private keys"
    echo "  copy        Copy certificates from another location"
    echo "  import      Find and copy system certificates automatically"
    echo "  generate    Generate development certificates with mkcert"
    echo "  help        Show this help message"
    echo ""
    echo "Examples:"
    echo "  ./manage-certs.sh find"
    echo "  ./manage-certs.sh copy /path/to/source/certs"
    echo "  ./manage-certs.sh import hostname"
    echo "  ./manage-certs.sh generate              # Generate all hawki.dev certificates"
    echo ""
    echo "═══════════════════════════════════════════════════════"
    echo ""
}

# Function to find server certificates
find_certs() {
    echo ""
    echo -e "${BLUE}🔍 Searching for server certificates...${NC}"
    echo ""
    
    # Common certificate locations
    CERT_LOCATIONS=(
        "/etc/ssl/certs"
        "/etc/pki/tls/certs"
        "/etc/nginx/ssl"
        "/etc/apache2/ssl"
        "/usr/local/etc/nginx/ssl"
        "$HOME/.ssh"
        "$HOME/ssl"
        "$HOME/certs"
    )
    
    # Common private key locations
    KEY_LOCATIONS=(
        "/etc/ssl/private"
        "/etc/pki/tls/private"
        "/etc/nginx/ssl"
        "/etc/apache2/ssl"
        "/usr/local/etc/nginx/ssl"
    )
    
    echo "Checking common certificate locations:"
    for location in "${CERT_LOCATIONS[@]}"; do
        if [ -d "$location" ]; then
            cert_files=$(find "$location" -maxdepth 2 -type f \( -name "*.crt" -o -name "*.pem" -o -name "*.cer" \) 2>/dev/null | head -20)
            if [ -n "$cert_files" ]; then
                echo -e "${GREEN}✓${NC} $location"
                echo "$cert_files" | while read -r file; do
                    local filename=$(basename "$file")
                    echo "  - $filename ($file)"
                done
            else
                echo -e "${YELLOW}○${NC} $location (directory exists, no certificate files found)"
            fi
        else
            echo -e "${RED}✗${NC} $location (not found)"
        fi
    done
    
    echo ""
    echo "Checking common private key locations:"
    for location in "${KEY_LOCATIONS[@]}"; do
        if [ -d "$location" ]; then
            key_files=$(find "$location" -maxdepth 2 -type f \( -name "*.key" -o -name "*priv*.pem" \) 2>/dev/null | head -20)
            if [ -n "$key_files" ]; then
                echo -e "${GREEN}✓${NC} $location"
                echo "$key_files" | while read -r file; do
                    local filename=$(basename "$file")
                    echo "  - $filename ($file)"
                done
            else
                echo -e "${YELLOW}○${NC} $location (directory exists, no key files found)"
            fi
        else
            echo -e "${RED}✗${NC} $location (not found)"
        fi
    done
    
    echo ""
    echo "Certificates in current directory ($SCRIPT_DIR):"
    local found_files=false
    for ext in crt pem key; do
        while IFS= read -r -d '' file; do
            if [ -f "$file" ]; then
                local filename=$(basename "$file")
                echo "  - $filename"
                found_files=true
            fi
        done < <(find "$SCRIPT_DIR" -maxdepth 1 -name "*.$ext" -print0 2>/dev/null)
    done
    if [ "$found_files" = false ]; then
        echo -e "${YELLOW}  No certificates found${NC}"
    fi
    
    echo ""
    echo -e "${BLUE}💡 Tip: Use './manage-certs.sh import hostname' to automatically find and copy certificates${NC}"
    echo ""
}

# Function to copy certificates
copy_certs() {
    local source_dir="$1"
    
    if [ -z "$source_dir" ]; then
        echo -e "${RED}❌ Error: Please specify source directory${NC}"
        echo "Usage: ./manage-certs.sh copy /path/to/source/certs"
        exit 1
    fi
    
    if [ ! -d "$source_dir" ]; then
        echo -e "${RED}❌ Error: Source directory does not exist: $source_dir${NC}"
        exit 1
    fi
    
    echo ""
    echo -e "${BLUE}📋 Copying certificates from: $source_dir${NC}"
    echo ""
    
    # Count certificate files
    cert_count=$(find "$source_dir" -maxdepth 1 -type f \( -name "*.crt" -o -name "*.pem" -o -name "*.key" \) | wc -l)
    
    if [ "$cert_count" -eq 0 ]; then
        echo -e "${YELLOW}⚠️  No certificate files found in source directory${NC}"
        exit 1
    fi
    
    echo "Found $cert_count certificate file(s)"
    echo ""
    
    # Copy files
    find "$source_dir" -maxdepth 1 -type f \( -name "*.crt" -o -name "*.pem" -o -name "*.key" \) -exec cp -v {} "$SCRIPT_DIR/" \;
    
    echo ""
    echo -e "${GREEN}✅ Certificates copied successfully!${NC}"
    echo ""
    
    # Set appropriate permissions
    echo "Setting file permissions..."
    chmod 644 "$SCRIPT_DIR"/*.crt 2>/dev/null || true
    chmod 644 "$SCRIPT_DIR"/*.pem 2>/dev/null || true
    chmod 600 "$SCRIPT_DIR"/*.key 2>/dev/null || true
    
    echo -e "${GREEN}✅ Permissions set${NC}"
    echo ""
}

# Function to generate development certificates with mkcert
generate_dev_certs() {
    local domain="$1"
    
    echo ""
    echo -e "${BLUE}🔐 Generating development certificates with mkcert${NC}"
    echo ""
    
    # Check if mkcert is available
    local mkcert_cmd=""
    if command -v mkcert &> /dev/null; then
        mkcert_cmd="mkcert"
    elif [ -f "/opt/homebrew/bin/mkcert" ]; then
        mkcert_cmd="/opt/homebrew/bin/mkcert"
    elif [ -f "/usr/local/bin/mkcert" ]; then
        mkcert_cmd="/usr/local/bin/mkcert"
    else
        echo -e "${RED}❌ Error: mkcert is not installed${NC}"
        echo ""
        echo "Please install mkcert first:"
        echo "  brew install mkcert"
        echo ""
        echo "Then run:"
        echo "  mkcert -install"
        echo ""
        exit 1
    fi
    
    # Check if mkcert CA is installed
    if ! $mkcert_cmd -CAROOT &> /dev/null; then
        echo -e "${YELLOW}⚠️  mkcert CA not installed yet${NC}"
        echo "Installing local CA..."
        $mkcert_cmd -install
        echo ""
    fi
    
    # Backup old certificates if they exist
    if ls *.crt *.key &> /dev/null; then
        echo "Backing up old certificates..."
        mkdir -p old_certs
        mv *.crt *.key old_certs/ 2>/dev/null || true
        echo -e "${GREEN}✓${NC} Old certificates backed up to old_certs/"
        echo ""
    fi
    
    # Define all HAWKI domains
    local domains=("app.hawki.dev" "db.hawki.dev" "mail.hawki.dev" "admin.hawki.dev")
    
    # If a specific domain was provided, only generate that one
    if [ -n "$domain" ]; then
        domains=("$domain")
    fi
    
    echo "Generating certificates for the following domains:"
    for d in "${domains[@]}"; do
        echo "  - $d"
    done
    echo ""
    
    # Generate certificates for each domain
    for d in "${domains[@]}"; do
        echo -e "${BLUE}Generating certificate for: $d${NC}"
        
        $mkcert_cmd \
            -cert-file "$SCRIPT_DIR/${d}.crt" \
            -key-file "$SCRIPT_DIR/${d}.key" \
            "$d" localhost 127.0.0.1 ::1
        
        # Set appropriate permissions
        chmod 644 "$SCRIPT_DIR/${d}.crt"
        chmod 600 "$SCRIPT_DIR/${d}.key"
        
        echo ""
    done
    
    # Create generic cert.pem and key.pem (use app.hawki.dev as default)
    echo "Creating generic certificates (cert.pem/key.pem)..."
    cp "$SCRIPT_DIR/app.hawki.dev.crt" "$SCRIPT_DIR/cert.pem"
    cp "$SCRIPT_DIR/app.hawki.dev.key" "$SCRIPT_DIR/key.pem"
    chmod 644 "$SCRIPT_DIR/cert.pem"
    chmod 600 "$SCRIPT_DIR/key.pem"
    
    echo ""
    echo -e "${GREEN}✅ All certificates generated successfully!${NC}"
    echo ""
    echo "Generated certificates:"
    ls -lh "$SCRIPT_DIR"/*.crt "$SCRIPT_DIR"/*.key 2>/dev/null | grep -v old_certs | awk '{print "  - " $9 " (" $5 ")"}'
    echo ""
    
    # Check /etc/hosts entries
    echo -e "${BLUE}Checking /etc/hosts entries...${NC}"
    local missing_hosts=()
    for d in "${domains[@]}"; do
        if [ "$d" != "localhost" ] && [ "$d" != "127.0.0.1" ]; then
            if ! grep -q "$d" /etc/hosts 2>/dev/null; then
                missing_hosts+=("$d")
            fi
        fi
    done
    
    if [ ${#missing_hosts[@]} -gt 0 ]; then
        echo ""
        echo -e "${YELLOW}⚠️  The following domains are missing from /etc/hosts:${NC}"
        for d in "${missing_hosts[@]}"; do
            echo "  - $d"
        done
        echo ""
        echo -e "${YELLOW}� Add them with:${NC}"
        for d in "${missing_hosts[@]}"; do
            echo "   echo \"127.0.0.1 $d\" | sudo tee -a /etc/hosts"
        done
        echo ""
    else
        echo -e "${GREEN}✓${NC} All domains found in /etc/hosts"
        echo ""
    fi
    
    echo -e "${GREEN}🎉 Certificate generation complete!${NC}"
    echo ""
    echo "Next steps:"
    echo "  1. Copy certificates to your project: cp *.crt *.key /path/to/project/certs/"
    echo "  2. Restart your web server to load the new certificates"
    echo "  3. Restart your browser to trust the new certificates"
    echo ""
    echo -e "${BLUE}Note: These certificates are valid until $(date -v+825d '+%B %d, %Y' 2>/dev/null || date -d '+825 days' '+%B %d, %Y' 2>/dev/null || echo '~2 years from now')${NC}"
    echo ""
}

# Function to import system certificates
import_system_certs() {
    local hostname="$1"
    
    if [ -z "$hostname" ]; then
        # Try to detect hostname
        hostname=$(hostname -f 2>/dev/null || hostname 2>/dev/null || echo "")
        if [ -z "$hostname" ]; then
            echo -e "${YELLOW}⚠️  Could not detect hostname. Please specify manually.${NC}"
            echo "Usage: ./manage-certs.sh import hostname"
            exit 1
        fi
        echo -e "${BLUE}🔍 Detected hostname: $hostname${NC}"
    fi
    
    echo ""
    echo -e "${BLUE}🔍 Searching for certificates matching: $hostname${NC}"
    echo ""
    
    # Search for certificate
    local cert_file=""
    local key_file=""
    
    # Common certificate patterns
    local cert_patterns=(
        "/etc/ssl/certs/${hostname}.pem"
        "/etc/ssl/certs/${hostname}.crt"
        "/etc/ssl/certs/${hostname%-*}.pem"
        "/etc/pki/tls/certs/${hostname}.pem"
        "/etc/pki/tls/certs/${hostname}.crt"
    )
    
    # Common key patterns
    local key_patterns=(
        "/etc/ssl/private/${hostname}.key"
        "/etc/ssl/private/${hostname%-*}.key"
        "/etc/ssl/private/priv.pem"
        "/etc/pki/tls/private/${hostname}.key"
        "/etc/pki/tls/private/priv.pem"
    )
    
    # Find certificate
    for pattern in "${cert_patterns[@]}"; do
        if [ -f "$pattern" ]; then
            cert_file="$pattern"
            local cert_name=$(basename "$cert_file")
            echo -e "${GREEN}✓${NC} Found certificate: $cert_name"
            echo -e "   Path: $cert_file"
            break
        fi
    done
    
    # Find private key
    for pattern in "${key_patterns[@]}"; do
        if [ -f "$pattern" ]; then
            key_file="$pattern"
            local key_name=$(basename "$key_file")
            echo -e "${GREEN}✓${NC} Found private key: $key_name"
            echo -e "   Path: $key_file"
            break
        fi
    done
    
    # Check if we found both files
    if [ -z "$cert_file" ] || [ -z "$key_file" ]; then
        echo ""
        echo -e "${YELLOW}⚠️  Could not find matching certificate and/or private key${NC}"
        echo ""
        echo "Run './manage-certs.sh find' to see available certificates"
        echo "Or use './manage-certs.sh copy /path/to/certs' to copy manually"
        exit 1
    fi
    
    echo ""
    echo -e "${BLUE}📋 Copying certificates...${NC}"
    echo ""
    
    # Copy certificate
    cp "$cert_file" "$SCRIPT_DIR/cert.pem"
    local cert_name=$(basename "$cert_file")
    echo -e "${GREEN}✓${NC} Copied: $cert_name → cert.pem"
    echo -e "   From: $cert_file"
    
    # Copy private key
    cp "$key_file" "$SCRIPT_DIR/key.pem"
    local key_name=$(basename "$key_file")
    echo -e "${GREEN}✓${NC} Copied: $key_name → key.pem"
    echo -e "   From: $key_file"
    
    # Set appropriate permissions
    chmod 644 "$SCRIPT_DIR/cert.pem"
    chmod 600 "$SCRIPT_DIR/key.pem"
    
    echo ""
    echo -e "${GREEN}✅ Certificates imported successfully!${NC}"
    echo ""
    echo "Files created:"
    echo "  - $SCRIPT_DIR/cert.pem (certificate)"
    echo "  - $SCRIPT_DIR/key.pem (private key)"
    echo ""
}

# Main script logic
case "${1:-help}" in
    find)
        find_certs
        ;;
    copy)
        copy_certs "$2"
        ;;
    import)
        import_system_certs "$2"
        ;;
    generate)
        generate_dev_certs "$2"
        ;;
    help|--help|-h)
        show_help
        ;;
    *)
        echo -e "${RED}❌ Unknown command: $1${NC}"
        show_help
        exit 1
        ;;
esac
