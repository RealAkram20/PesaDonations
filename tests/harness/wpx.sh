#!/bin/bash
# WP-CLI against the throwaway site D:\pdwp (XAMPP PHP, private MariaDB on 3399).
export WP_CLI_CACHE_DIR="D:/pdtest/wpcli-cache"
exec /d/xampp/php/php.exe -d memory_limit=1024M D:/pdtest/wp-cli.phar --path="D:/pdwp/site" "$@"
