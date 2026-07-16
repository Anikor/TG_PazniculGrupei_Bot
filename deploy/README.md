# Pi deploy reference — performance

Config files here are meant to be copied onto the Raspberry Pi during
deployment. None of this is read by `dev.sh`'s local server (PHP's built-in
`php -S` doesn't use php-fpm or these config paths), so there's nothing to
test locally for this part — see `OPTIMIZATION.md` for the numbers these
are based on.

## 1. opcache

Copy `opcache.ini` to `/etc/php/8.x/fpm/conf.d/10-opcache.ini` (check the
Pi's actual PHP version first: `php -v`). Restart php-fpm after.

## 2. MariaDB

Copy `mariadb-pi.cnf` to `/etc/mysql/mariadb.conf.d/60-pi.cnf`. Restart
MariaDB after: `sudo systemctl restart mariadb`.

## 3. php-fpm pool

`php-fpm-pool.conf` isn't a drop-in file — edit the values into your
existing pool file (commonly `/etc/php/8.x/fpm/pool.d/www.conf`) in place.
Restart php-fpm after.

## 4. Indexes — apply carefully, in this order

`idx_user_date` is safe to add any time:

```sql
ALTER TABLE attendance ADD INDEX idx_user_date (user_id, date);
```

`uq_attendance` is a UNIQUE constraint, which means it will **fail** if any
duplicate `(user_id, schedule_id, date)` rows already exist on the live
table. Check first:

```sql
SELECT user_id, schedule_id, date, COUNT(*) c
FROM attendance
GROUP BY user_id, schedule_id, date
HAVING c > 1;
```

If that returns rows, decide what to do with the duplicates (keep the
latest by `id`, or whichever is correct for your data) before adding the
constraint. If it returns nothing, it's safe:

```sql
ALTER TABLE attendance ADD UNIQUE KEY uq_attendance (user_id, schedule_id, date);
```

Both are already in `tools/schema.sql` for local dev (a fresh table, so no
duplicate-check needed there).
