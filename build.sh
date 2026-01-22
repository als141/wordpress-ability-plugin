#!/bin/bash
#
# Build script for WordPress MCP Ability Plugin
# Creates a distribution-ready ZIP file
#

set -e

# Configuration
PLUGIN_SLUG="wordpress-mcp-ability-plugin"
VERSION=$(grep -Po "Version:\s*\K[0-9.]+" readonly-ability-plugin.php 2>/dev/null || echo "1.0.0")
BUILD_DIR="./build"
DIST_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"

echo "🔧 Building ${PLUGIN_SLUG} v${VERSION}..."

# Clean previous build
rm -rf "${BUILD_DIR}"
mkdir -p "${DIST_DIR}"

# Install production dependencies
echo "📦 Installing production dependencies..."
composer install --no-dev --optimize-autoloader --prefer-dist --quiet

# Copy plugin files
echo "📁 Copying plugin files..."

# Main plugin file
cp readonly-ability-plugin.php "${DIST_DIR}/"

# Includes directory
cp -r includes "${DIST_DIR}/"

# Vendor directory (required for dependencies)
cp -r vendor "${DIST_DIR}/"

# Optional: Copy additional files if they exist
[ -f "readme.txt" ] && cp readme.txt "${DIST_DIR}/"
[ -f "README.md" ] && cp README.md "${DIST_DIR}/"
[ -f "LICENSE" ] && cp LICENSE "${DIST_DIR}/"
[ -f "CHANGELOG.md" ] && cp CHANGELOG.md "${DIST_DIR}/"
[ -d "assets" ] && cp -r assets "${DIST_DIR}/"
[ -d "languages" ] && cp -r languages "${DIST_DIR}/"
[ -d "docs" ] && cp -r docs "${DIST_DIR}/"

# Remove unnecessary files from vendor
echo "🧹 Cleaning up vendor directory..."
find "${DIST_DIR}/vendor" -type d -name ".git" -exec rm -rf {} + 2>/dev/null || true
find "${DIST_DIR}/vendor" -type d -name "tests" -exec rm -rf {} + 2>/dev/null || true
find "${DIST_DIR}/vendor" -type d -name "test" -exec rm -rf {} + 2>/dev/null || true
find "${DIST_DIR}/vendor" -type d -name "docs" -exec rm -rf {} + 2>/dev/null || true
find "${DIST_DIR}/vendor" -type f -name "*.md" -delete 2>/dev/null || true
find "${DIST_DIR}/vendor" -type f -name "phpunit.xml*" -delete 2>/dev/null || true
find "${DIST_DIR}/vendor" -type f -name "phpcs.xml*" -delete 2>/dev/null || true
find "${DIST_DIR}/vendor" -type f -name ".gitignore" -delete 2>/dev/null || true
find "${DIST_DIR}/vendor" -type f -name ".gitattributes" -delete 2>/dev/null || true
find "${DIST_DIR}/vendor" -type f -name "Makefile" -delete 2>/dev/null || true

# Create ZIP file
echo "📦 Creating ZIP archive..."
cd "${BUILD_DIR}"
zip -r "${PLUGIN_SLUG}-${VERSION}.zip" "${PLUGIN_SLUG}" -q

# Move ZIP to project root
mv "${PLUGIN_SLUG}-${VERSION}.zip" ../

cd ..

# Cleanup
rm -rf "${BUILD_DIR}"

# Restore dev dependencies
echo "🔄 Restoring development dependencies..."
composer install --quiet

echo ""
echo "✅ Build complete!"
echo "📄 Output: ${PLUGIN_SLUG}-${VERSION}.zip"
echo ""
echo "File size: $(du -h "${PLUGIN_SLUG}-${VERSION}.zip" | cut -f1)"
