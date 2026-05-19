# Deploy to Railway (step by step)

        ## Important: your local `.env` is NOT used on Railway

The file `.env` on your computer (with `DATABASE_URL=...@db:3306...`) is **ignored** by Docker/Railway.  
Only variables you set on the **APP service** in Railway are used.

---

## 1. Push code to GitHub

Commit and push this repository.

## 2. Create Railway project

1. [railway.com](https://railway.com) → **New Project** → **Deploy from GitHub repo**
2. Select this repository

## 3. Add MySQL

1. In the project → **+ New** → **Database** → **MySQL**
2. Wait until MySQL is **Active**

## 4. Connect MySQL to your APP (choose one method)

### Method A — Connect (easiest)

1. Click your **APP** service (Symfony / Dockerfile), **not** MySQL
2. Go to **Settings**
3. Find **Connect** (or **Service connections**)
4. Connect to your **MySQL** service
5. Railway injects `MYSQLHOST`, `MYSQL_URL`, etc. onto the app

Then add one variable so Symfony sees the URL:

1. **APP service** → **Variables** → **+ New Variable** → **Reference**
2. Service: **MySQL** → variable: **`MYSQL_URL`**
3. Name on app: **`DATABASE_URL`**

### Method B — Manual variables only

On the **APP service** → **Variables**:

| Name | Value |
|------|--------|
| `DATABASE_URL` | `${{MySQL.MYSQL_URL}}` |
| `APP_SECRET` | long random string (32+ chars) |
| `APP_ENV` | `prod` |

If your MySQL service is not named `MySQL`, change the reference (e.g. `${{mysql.MYSQL_URL}}`).

See `railway.env.example` in this repo.

## 5. Set APP_SECRET

On the **APP service** → **Variables**:

- `APP_SECRET` = random string (e.g. from `openssl rand -hex 32`)

## 6. Redeploy the APP service

**Deployments** → **Redeploy**

### Success logs look like:

```text
Railway detected: yes
Database-related env vars on this container: DATABASE_URL, MYSQLHOST, ...
Database target: mysql://root@....railway.internal:3306/railway
Database connection: OK
Database is ready.
```

### If you see `(none)` for env vars:

Variables are on the wrong service or not added. They must be on the **APP** container, not only on MySQL.

### If the site shows "Application failed to respond" (502)

The container is not listening on Railway's `PORT`, or it crashed on startup.

1. Open **APP service** → **Deployments** → latest deploy → **View logs** (runtime, not only build).
2. You must see:
   ```text
   [entrypoint] Starting nginx on 0.0.0.0:XXXX...
   Database connection: OK
   ```
3. If logs stop at `ERROR: No database configuration` → add `DATABASE_URL` (see step 4 above).
4. If logs stop at `APP_SECRET` → add `APP_SECRET` on the APP service.
5. Push the latest code (nginx listens on `PORT` + cache permissions fix), then **Redeploy**.

### If you see Symfony "500 Internal Server Error"

Usually assets were not compiled. Latest code runs `importmap:install` and `asset-map:compile` on startup. Push, redeploy, and check logs for:

```text
[entrypoint] Installing importmap and compiling assets for production...
```

## 7. Public URL

**APP service** → **Settings** → **Networking** → **Generate Domain**

---

## Checklist

- [ ] MySQL service exists and is Active
- [ ] `DATABASE_URL` is on the **APP** service (reference to `MYSQL_URL`)
- [ ] `APP_SECRET` is on the **APP** service
- [ ] Redeployed **APP** after adding variables
- [ ] Logs show `Database-related env vars: ...` with at least `DATABASE_URL` or `MYSQL_URL`
