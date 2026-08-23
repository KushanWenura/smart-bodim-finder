# One-command local launcher

After installing dependencies once, start Laravel, the queue worker, the trained AI service and Vite with:

```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
.\Run\start-all.ps1
```

Open `http://127.0.0.1:5173`. Stop launcher-managed processes with:

```powershell
.\Run\stop-all.ps1
```

The launcher stores only process IDs in the ignored `Run/.pids.json` file. It does not install dependencies or alter database data.
