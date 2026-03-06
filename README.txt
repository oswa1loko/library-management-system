LIBRARYMANAGE - QUICK START

1) PROJECT LOCATION
- Put this folder in: C:\xampp\htdocs\librarymanage

2) START SERVICES (XAMPP)
- Apache = Start
- MySQL = Start

3) FIRST RUN
- Open: http://localhost/librarymanage/setup.php
- This creates/updates the database schema automatically.

4) LOGIN
- Open: http://localhost/librarymanage/loginpage.php

DEFAULT ADMIN (if seeded in your setup flow)
- Username: admin
- Password: admin123

MAIN MODULES
- Admin: accounts, payments, penalties, complaints, analytics, audit logs, notifications, backup/restore
- Custodian: books, active borrows, return confirmation, penalties
- Student/Faculty: browse books, borrow/return requests, payment upload

API (v1)
- Base: /librarymanage/api/v1/
- Supports session and token-based auth (Bearer tokens with scopes)

OVERDUE PENALTY RULE
- Automatic sync enabled
- Rate: PHP 2.00 per overdue day
- Manual trigger available in Admin dashboard and penalties page
- Cron-ready script: scripts/penalty_sync.php

MOBILE TESTING
- Local network URL: http://<PC-IP>/librarymanage/loginpage.php
- Public temporary demo: Cloudflare quick tunnel to localhost

TROUBLESHOOTING
- If DB credentials changed, update includes/config.php
- If routes fail, verify folder name is exactly "librarymanage"
- If mobile cannot connect, verify Apache is running and URL path includes /librarymanage/


✅ Admin

Email: admin@gmail.com

Username: admin

Role: admin

Password: admin123

✅ Student

Email: student1@gmail.com

Username: student1

Role: student

Password: admin123

✅ Custodian

Email: custodian1@gmail.com

Username: custodian1

Role: custodian

Password: admin123

