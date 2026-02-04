#!/usr/bin/env bash
set -euo pipefail

# Run this from your Laravel project root (where artisan exists).
if [[ ! -f artisan ]]; then
  echo "ERROR: artisan not found. Run this script from Laravel project root."
  exit 1
fi

echo "== PATCH 1/2: Vacation mode schedule =="

# Command
php artisan make:command ProcessVacationSchedules || true

# Migration (table modify)
php artisan make:migration add_vacation_schedule_to_seller_settings --table=seller_settings || true

echo "== PATCH 2/2: Social media integration =="

# Command
php artisan make:command ProcessScheduledPosts || true

# Controllers
php artisan make:controller Api/SocialMediaController || true
php artisan make:controller Api/InstagramController || true

# Models
php artisan make:model SocialAccount || true
php artisan make:model ScheduledPost || true
php artisan make:model InstagramImport || true

# Migrations
php artisan make:migration create_social_accounts_table || true
php artisan make:migration create_scheduled_posts_table || true
php artisan make:migration create_instagram_imports_table || true

echo "== DONE generating skeleton files =="
echo
echo "NEXT STEPS:"
echo "1) Copy/paste the EXACT code you have (from your patches) into the generated files."
echo "2) Update app/Console/Kernel.php schedule() with:"
echo "   - vacation:process-schedules dailyAt('00:05')"
echo "   - social:process-scheduled-posts everyMinute()"
echo "3) Update routes/api.php with /social/* and /instagram/* routes."
echo "4) Run migrations:"
echo "   php artisan migrate"
echo "5) Optional test:"
echo "   php artisan vacation:process-schedules"
echo "   php artisan social:process-scheduled-posts"
