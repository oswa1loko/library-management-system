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
- Librarian: books, active borrows, return confirmation, penalties
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

EMAIL REMINDERS
- The system now supports due-soon reminder emails 1 day before the due date.
- Daily runner:
  php scripts/due_reminder_sync.php
- Gmail SMTP setup values:
  LIBRARY_SMTP_HOST=smtp.gmail.com
  LIBRARY_SMTP_PORT=587
  LIBRARY_SMTP_SECURE=tls
  LIBRARY_SMTP_USERNAME=yourgmail@gmail.com
  LIBRARY_SMTP_PASSWORD=your-gmail-app-password
  LIBRARY_MAIL_FROM_ADDRESS=yourgmail@gmail.com
  LIBRARY_MAIL_FROM_NAME=Library Management System
- Use a Gmail App Password, not your normal Gmail password.

✅ Student

Email: student1@gmail.com

Username: student1

Role: student

Password: admin123

✅ Librarian

Email: librarian1@gmail.com

Username: librarian1

Role: librarian

Password: admin123
