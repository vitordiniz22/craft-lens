#!/bin/bash
#
# Lens Plugin - Test Environment Setup
#
# This script sets up everything needed to run tests.
# Run from the plugin root (plugins/lens/):
#   bash tests/setup.sh
#
# What it does:
#   1. Installs composer dev dependencies
#   2. Copies .env.example to .env if not present
#   3. Creates the test database (requires mysql client access)
#   4. Builds Codeception actor classes
#
# Database requirements:
#   The test DB credentials in tests/.env must point to a MySQL server
#   you have access to. The defaults assume DDEV (host=db, user=db).
#   If running outside DDEV, edit tests/.env before running this script.

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"

cd "$PLUGIN_DIR"

echo "=== Lens Test Setup ==="
echo ""

# 1. Install dependencies
echo "1. Installing dev dependencies..."
composer install --quiet
echo "   Done."
echo ""

# 2. Copy .env
if [ ! -f tests/.env ]; then
    echo "2. Creating tests/.env from example..."
    cp tests/.env.example tests/.env
    echo "   Done. Edit tests/.env if your DB credentials differ."
else
    echo "2. tests/.env already exists, skipping."
fi
echo ""

# 3. Create test database
echo "3. Creating test database..."
# Parse DSN from .env to extract dbname
DB_NAME=$(grep CRAFT_DB_DSN tests/.env | sed 's/.*dbname=\([^"]*\).*/\1/')
DB_USER=$(grep CRAFT_DB_USER tests/.env | sed 's/.*="\?\([^"]*\)"\?/\1/')
DB_PASS=$(grep CRAFT_DB_PASSWORD tests/.env | sed 's/.*="\?\([^"]*\)"\?/\1/')
DB_HOST=$(grep CRAFT_DB_DSN tests/.env | sed 's/.*host=\([^;]*\).*/\1/')

if mysql -h"$DB_HOST" -u root -proot -e "SELECT 1" &>/dev/null; then
    mysql -h"$DB_HOST" -u root -proot -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'%'; FLUSH PRIVILEGES;"
    echo "   Database '$DB_NAME' ready."
elif mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -e "USE \`$DB_NAME\`" &>/dev/null; then
    echo "   Database '$DB_NAME' already accessible."
else
    echo "   WARNING: Could not create database automatically."
    echo "   Please create '$DB_NAME' manually and grant access to '$DB_USER'."
fi
echo ""

# 4. Build Codeception actors
echo "4. Building Codeception actors..."
php vendor/bin/codecept build
echo ""

echo "=== Setup complete ==="
echo ""
echo "Run tests with:"
echo "  php vendor/bin/codecept run              # All suites"
echo "  php vendor/bin/codecept run unit          # Unit tests"
echo "  php vendor/bin/codecept run integration   # Integration tests"
echo "  php vendor/bin/codecept run functional    # Functional tests"
echo ""
echo "Or via composer:"
echo "  composer test"
echo "  composer test:unit"
