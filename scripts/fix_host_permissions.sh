#!/bin/sh

# Run this on the HOST, not inside a container. Needs sudo.
#
# core/ is bind-mounted into the containers, so the image's own ownership is
# replaced by whatever the host files carry. A file written by an editor, a
# sync tool or an agent can land readable only by its owner, and Laravel then
# dies with "Permission denied" on something as basic as bootstrap/app.php.
#
# Two things happen here. The first fixes the files that are already wrong.
# The second installs DEFAULT ACLs, so every file created in these directories
# from now on inherits readable permissions — which is what stops this from
# being a chore to repeat after every change.

set -e

cd "$(dirname "$0")/.."
ROOT=$(pwd)

echo "fixing permissions under $ROOT/core"

# --- what is already there ---
find core -type d -not -path 'core/vendor/*' -not -path 'core/node_modules/*' -exec chmod 755 {} +
find core -type f -not -path 'core/vendor/*' -not -path 'core/node_modules/*' -exec chmod 644 {} +

# Compiled views, framework cache and logs: the web user writes these.
chmod -R 777 core/storage core/bootstrap/cache

chmod +x scripts/*.sh

# --- and what gets created later ---
if command -v setfacl >/dev/null 2>&1; then
    # Readable by anyone, which is all php-fpm needs for application code.
    setfacl -R -m   u::rwX,g::rX,o::rX core 2>/dev/null || true
    setfacl -R -d -m u::rwX,g::rX,o::rX,m::rwX core 2>/dev/null || true

    # Writable, because Laravel compiles views and writes logs into these.
    setfacl -R -m   o::rwX core/storage core/bootstrap/cache 2>/dev/null || true
    setfacl -R -d -m u::rwX,g::rwX,o::rwX,m::rwX core/storage core/bootstrap/cache 2>/dev/null || true

    echo "default ACLs set: new files will be readable without running this again"
else
    echo
    echo "setfacl not found, so only the current files were fixed."
    echo "To stop this from recurring, install it once:"
    echo "    sudo apt-get install -y acl"
    echo "then run this script again."
fi

echo "done"
