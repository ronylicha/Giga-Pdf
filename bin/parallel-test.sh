#!/bin/bash

# Setup parallel test databases
echo "Setting up parallel test databases..."

# Number of parallel processes
PROCESSES=${1:-4}

# Database credentials
DB_USER="gigapdf_user"
DB_PASS="G1g@PdF2024Secure"
ROOT_PASS="21081986Rl@"

# Create test databases for each process
for i in $(seq 1 $PROCESSES); do
    DB_NAME="gigapdf_test_$i"
    echo "Creating database: $DB_NAME"
    
    mysql -u root -p"$ROOT_PASS" -e "DROP DATABASE IF EXISTS $DB_NAME; CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '$DB_USER'@'localhost'; FLUSH PRIVILEGES;" 2>/dev/null
    
    # Run migrations for each test database
    php artisan migrate:fresh --database=mysql --env=testing --force --path=database/migrations --seed --no-interaction \
        --database-connection="mysql" \
        --database-database="$DB_NAME" 2>/dev/null
done

echo "Running parallel tests with $PROCESSES processes..."

# Run ParaTest with Laravel's parallel testing support
php artisan test --parallel --processes=$PROCESSES

# Cleanup test databases (optional)
# for i in $(seq 1 $PROCESSES); do
#     DB_NAME="gigapdf_test_$i"
#     mysql -u root -p"$ROOT_PASS" -e "DROP DATABASE IF EXISTS $DB_NAME;" 2>/dev/null
# done