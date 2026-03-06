# Scheduled Jobs

## Overdue penalty sync (PHP 2/day)

Run manually:

```bash
php C:\xampp\htdocs\librarymanage\scripts\penalty_sync.php
```

Windows Task Scheduler command:

```text
Program/script: C:\xampp\php\php.exe
Add arguments: C:\xampp\htdocs\librarymanage\scripts\penalty_sync.php
```

Recommended frequency: every 30 minutes.

