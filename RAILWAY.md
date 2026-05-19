# Deploy to Railway (step by step)

Your code is ready. Railway still needs you to create a database and set two variables in the dashboard.

## 1. Push your code

Commit and push this repository to GitHub (or connect the folder in Railway).

## 2. Create the Railway project

1. Go to [https://railway.com](https://railway.com) and sign in.
2. Click **New Project** → **Deploy from GitHub repo**.
3. Select this repository.
4. Railway detects the `Dockerfile` and builds the app service.

## 3. Add MySQL

1. In the same project, click **+ New** → **Database** → **MySQL**.
2. Wait until the MySQL service shows **Active** / healthy.

## 4. Link the database to your app

1. Click your **app** service (not MySQL).
2. Open the **Variables** tab.
3. Click **+ New Variable** → **Add Variable Reference** (or **Reference**).
4. Choose your **MySQL** service and select **`MYSQL_URL`**.
5. Set the variable **name** on the app to: `DATABASE_URL`  
   (Railway copies the MySQL URL into `DATABASE_URL` for Symfony.)

## 5. Set APP_SECRET

Still on the app **Variables** tab:

1. Click **+ New Variable**.
2. Name: `APP_SECRET`
3. Value: a long random string (at least 32 characters).  
   Example generator: `openssl rand -hex 32`

Optional but recommended:

| Name       | Value   |
|------------|---------|
| `APP_ENV`  | `prod`  |
| `APP_DEBUG`| `0`     |

## 6. Redeploy

1. Open the app service → **Deployments**.
2. Click **Redeploy** (or push a new commit).
3. Open **Deploy Logs**. You should see:
   - `Database target: mysql://...@....railway.app:.../railway`
   - `Database connection: OK`
   - `Database is ready.`

## 7. Open the site

1. App service → **Settings** → **Networking** → **Generate Domain**.
2. Open the generated URL in your browser.

---

## Common mistakes

| Problem | Fix |
|---------|-----|
| `host "db"` error | Do not use `mysql://root:root@db:3306/...`. Use **variable reference** `MYSQL_URL` → `DATABASE_URL`. |
| `APP_SECRET` error | Add `APP_SECRET` on the **app** service (not only MySQL). |
| Database timeout | MySQL must be **Active** before redeploying the app. Same Railway **project** for both services. |
| Build OK, app crashes | Check **Deploy Logs** on the app service, not only build logs. |

---

## Local test (before Railway)

```bash
docker compose up --build
```

Open [http://localhost:8000](http://localhost:8000).
