#!/bin/bash

# WPS3 Plugin Build Script
# This script creates a production-ready ZIP file for WordPress.org submission

set -e  # Exit on any error

# Configuration
PLUGIN_NAME="wps3"
VERSION=$(grep "Version:" wps3.php | cut -d' ' -f2)
BUILD_DIR="build"
DIST_DIR="dist"
ZIP_NAME="${PLUGIN_NAME}-${VERSION}.zip"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}Building WPS3 Plugin v${VERSION}${NC}"

# Check if Node.js and npm are available
if ! command -v node &> /dev/null; then
    echo -e "${RED}Error: Node.js is not installed. Please install Node.js to build assets.${NC}"
    exit 1
fi

if ! command -v npm &> /dev/null; then
    echo -e "${RED}Error: npm is not installed. Please install npm to build assets.${NC}"
    exit 1
fi

# Install dependencies if node_modules doesn't exist
if [ ! -d "node_modules" ]; then
    echo -e "${YELLOW}Installing npm dependencies...${NC}"
    npm install
fi

# Build JavaScript and CSS assets
echo -e "${YELLOW}Building JavaScript and CSS assets...${NC}"
npm run build

# Clean up previous builds
echo -e "${YELLOW}Cleaning up previous builds...${NC}"
rm -rf $BUILD_DIR
rm -rf $DIST_DIR
mkdir -p $BUILD_DIR
mkdir -p $DIST_DIR

# Copy plugin files to build directory (excluding build and dist directories)
echo -e "${YELLOW}Copying plugin files...${NC}"
rsync -av --exclude="$BUILD_DIR" --exclude="$DIST_DIR" --exclude=".git" --exclude="node_modules" . $BUILD_DIR/

# Change to build directory
cd $BUILD_DIR

# Remove development and unnecessary files
echo -e "${YELLOW}Removing development files...${NC}"
rm -rf node_modules/
rm -rf .vscode/
rm -rf .idea/
rm -f .DS_Store
rm -f Thumbs.db
rm -f *.log
rm -f *.tmp
rm -f *.temp
rm -f .zipignore
rm -f build-dist.sh
rm -f package.json
rm -f package-lock.json
rm -f composer.lock
rm -f phpunit.xml
rm -f phpcs.xml
rm -f examples.php

# Remove source files (keep only built assets)
rm -f css/admin.scss
rm -f js/admin.js
rm -f assets/css/admin.css.map
rm -f assets/js/admin.js.map

# Remove test files
rm -f test-*.php
rm -f *test*.php
rm -f TEST_*.md
rm -f SETUP_*.md
rm -f RESTORATION_*.md
rm -f SIMPLIFIED_*.md

# Remove empty files
find . -type f -empty -delete

    # Install production dependencies only
echo -e "${YELLOW}Installing production dependencies...${NC}"
if [ -f "composer.json" ]; then
    # Use --no-dev to exclude development dependencies
    # Use --classmap-authoritative to generate optimized autoloader (this creates manifest.json)
    composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction
    
    if [ $? -ne 0 ]; then
        echo -e "${RED}Composer install failed!${NC}"
        exit 1
    fi
fi

# Clean up vendor directory - remove documentation but keep all code
echo -e "${YELLOW}Cleaning up vendor directory...${NC}"
if [ -d "vendor/" ]; then
    # Remove test directories
    find vendor/ -type d -name "test" -exec rm -rf {} + 2>/dev/null || true
    find vendor/ -type d -name "tests" -exec rm -rf {} + 2>/dev/null || true
    find vendor/ -type d -name "Test" -exec rm -rf {} + 2>/dev/null || true
    find vendor/ -type d -name "Tests" -exec rm -rf {} + 2>/dev/null || true

    # Remove doc directories
    find vendor/ -type d -name "doc" -exec rm -rf {} + 2>/dev/null || true
    find vendor/ -type d -name "docs" -exec rm -rf {} + 2>/dev/null || true
    find vendor/ -type d -name "documentation" -exec rm -rf {} + 2>/dev/null || true

    # Remove example directories
    find vendor/ -type d -name "example" -exec rm -rf {} + 2>/dev/null || true
    find vendor/ -type d -name "examples" -exec rm -rf {} + 2>/dev/null || true
    find vendor/ -type d -name "sample" -exec rm -rf {} + 2>/dev/null || true
    find vendor/ -type d -name "samples" -exec rm -rf {} + 2>/dev/null || true

    # Remove development files
    find vendor/ -name "*.md" -delete 2>/dev/null || true
    find vendor/ -name "README*" -delete 2>/dev/null || true
    find vendor/ -name "CHANGELOG*" -delete 2>/dev/null || true
    find vendor/ -name ".gitignore" -delete 2>/dev/null || true
    find vendor/ -name ".gitattributes" -delete 2>/dev/null || true
    find vendor/ -name "composer.json" -delete 2>/dev/null || true
    find vendor/ -name "composer.lock" -delete 2>/dev/null || true
    find vendor/ -name "phpunit.xml*" -delete 2>/dev/null || true
    find vendor/ -name "phpcs.xml*" -delete 2>/dev/null || true
    find vendor/ -name ".php_cs*" -delete 2>/dev/null || true
    find vendor/ -name "psalm.xml*" -delete 2>/dev/null || true
    find vendor/ -name ".scrutinizer.yml" -delete 2>/dev/null || true
    find vendor/ -name ".travis.yml" -delete 2>/dev/null || true
    find vendor/ -name ".github" -type d -exec rm -rf {} + 2>/dev/null || true

    # Remove empty directories
    find vendor/ -type d -empty -delete 2>/dev/null || true
    
    echo -e "${GREEN}✓ Vendor cleanup complete${NC}"
fi

# Update version in readme.txt to match plugin file
echo -e "${YELLOW}Updating version in readme.txt...${NC}"
if [ -f "readme.txt" ]; then
    sed -i.bak "s/Stable tag: .*/Stable tag: ${VERSION}/" readme.txt
    rm -f readme.txt.bak
fi

# Validate plugin structure
echo -e "${YELLOW}Validating plugin structure...${NC}"
if [ ! -f "wps3.php" ]; then
    echo -e "${RED}Error: Main plugin file (wps3.php) not found!${NC}"
    exit 1
fi

if [ ! -f "readme.txt" ]; then
    echo -e "${RED}Error: readme.txt not found!${NC}"
    exit 1
fi

if [ ! -d "includes/" ]; then
    echo -e "${RED}Error: includes/ directory not found!${NC}"
    exit 1
fi

if [ ! -d "vendor/" ]; then
    echo -e "${RED}Error: vendor/ directory not found!${NC}"
    exit 1
fi

# Validate built assets
if [ ! -f "assets/js/admin.js" ]; then
    echo -e "${RED}Error: Built JavaScript file (assets/js/admin.js) not found!${NC}"
    exit 1
fi

if [ ! -f "assets/css/admin.css" ]; then
    echo -e "${RED}Error: Built CSS file (assets/css/admin.css) not found!${NC}"
    exit 1
fi

# Check for PHP syntax errors
echo -e "${YELLOW}Checking PHP syntax...${NC}"
find . -name "*.php" -exec php -l {} \; | grep -v "No syntax errors detected" && {
    echo -e "${RED}PHP syntax errors found!${NC}"
    exit 1
} || true

# Calculate final size and show optimization results
FINAL_SIZE=$(du -sh . | cut -f1)
echo -e "${GREEN}Build directory size: ${FINAL_SIZE}${NC}"

# Show size breakdown
echo -e "${YELLOW}Size breakdown:${NC}"
echo -e "  Main plugin files: $(du -sh wps3.php includes/ | cut -f1)"
echo -e "  Vendor files: $(du -sh vendor/ | cut -f1)"
echo -e "  Assets: $(du -sh assets/ | cut -f1)"
echo -e "  Other files: $(du -sh *.txt *.md | cut -f1)"

# Create ZIP file
echo -e "${YELLOW}Creating ZIP file...${NC}"
cd ..
zip -r "${DIST_DIR}/${ZIP_NAME}" "${BUILD_DIR}/" -x "*.DS_Store" "*/Thumbs.db" "*.log" "*.tmp" "*.temp"

# Verify ZIP contents
echo -e "${YELLOW}Verifying ZIP contents...${NC}"
unzip -l "${DIST_DIR}/${ZIP_NAME}" | head -20

# Final statistics
ZIP_SIZE=$(du -sh "${DIST_DIR}/${ZIP_NAME}" | cut -f1)
FILE_COUNT=$(unzip -l "${DIST_DIR}/${ZIP_NAME}" | tail -1 | awk '{print $2}')

echo -e "${GREEN}✓ Build completed successfully!${NC}"
echo -e "${GREEN}✓ Plugin: ${PLUGIN_NAME} v${VERSION}${NC}"
echo -e "${GREEN}✓ ZIP file: ${DIST_DIR}/${ZIP_NAME}${NC}"
echo -e "${GREEN}✓ ZIP size: ${ZIP_SIZE}${NC}"
echo -e "${GREEN}✓ Files: ${FILE_COUNT}${NC}"

# Clean up build directory
rm -rf $BUILD_DIR

echo -e "${GREEN}Build complete! ZIP file is ready for distribution.${NC}"
echo -e "${YELLOW}Location: $(pwd)/${DIST_DIR}/${ZIP_NAME}${NC}"
