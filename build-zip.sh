#!/bin/bash

# Blog Publisher - Build Distribution Zip
# This script creates a clean zip file for WordPress plugin distribution

set -e

PLUGIN_NAME="blog-publisher"
VERSION="1.0.0"
BUILD_DIR="./build"
DIST_DIR="./dist"
PLUGIN_DIR="$BUILD_DIR/$PLUGIN_NAME"

echo "🔨 Building $PLUGIN_NAME v$VERSION..."

# Clean previous builds
rm -rf "$BUILD_DIR" "$DIST_DIR"
mkdir -p "$DIST_DIR"

# Create build directory
mkdir -p "$PLUGIN_DIR"

# Copy plugin files (excluding ignored files)
echo "📦 Copying files..."
rsync -av --quiet \
    --exclude='.git' \
    --exclude='.gitignore' \
    --exclude='build' \
    --exclude='dist' \
    --exclude='node_modules' \
    --exclude='vendor' \
    --exclude='*.log' \
    --exclude='.DS_Store' \
    --exclude='.idea' \
    --exclude='.vscode' \
    --exclude='*.swp' \
    --exclude='*.swo' \
    --exclude='*~' \
    --exclude='phpunit.xml' \
    --exclude='phpcs.xml' \
    --exclude='tests' \
    ./ "$PLUGIN_DIR/"

# Remove any nested git directories
find "$PLUGIN_DIR" -name ".git" -type d -exec rm -rf {} + 2>/dev/null || true

# Create zip
echo "🗜️  Creating zip..."
cd "$BUILD_DIR"
zip -rq "../$DIST_DIR/${PLUGIN_NAME}.zip" "$PLUGIN_NAME"

# Also create versioned zip
zip -rq "../$DIST_DIR/${PLUGIN_NAME}.${VERSION}.zip" "$PLUGIN_NAME"

cd ..

# Show results
echo ""
echo "✅ Build complete!"
echo ""
echo "Distribution files:"
ls -lh "$DIST_DIR"
