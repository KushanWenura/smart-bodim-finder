# Database

The authoritative schema is defined by the versioned Laravel migrations in `backend/database/migrations/`; synthetic Sri Lankan assessment data is in `backend/database/seeders/DatabaseSeeder.php`.

Use `php artisan migrate --seed` for a new database. The migrations were executed against both SQLite test storage and a local WAMP MySQL database. Do not hand-edit a generated schema dump in place of migrations.
