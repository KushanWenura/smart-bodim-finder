# One-command local launcher

Start Laravel, the queue worker, the trained AI service and Vite with:

```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
.\Run\start-all.ps1
```

Open `http://127.0.0.1:5173`. Stop launcher-managed processes with:

```powershell
.\Run\stop-all.ps1
```

On the first run, the launcher installs missing Composer, pnpm and Python dependencies and creates a seeded SQLite database. Later runs start immediately. It stores only process IDs in the ignored `Run/.pids.json` file.
