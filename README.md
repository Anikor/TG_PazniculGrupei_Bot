# PazniculGrupei the Attendance Bot [![Telegram Bot](https://img.shields.io/badge/Telegram-Bot-blue?logo=telegram)](https://t.me/PazniculGrupeiBot)

**PazniculGrupei** is a Telegram bot that makes tracking and viewing student attendance a breeze.  
Group leaders can mark attendance with a tap, view detailed reports, and students can self-check their stats—all without leaving Telegram.

---

## Features

<details>
<summary>See the features of @PazniculGrupeiBot </summary>

## 1. Greeting page
   
The Greeting Page is your bot's central dashboard, designed for maximum convenience and lightning‑fast access:

<img width="578" height="743" alt="image" src="https://github.com/user-attachments/assets/9942c808-6701-4897-9a0f-4285c5873732" />

 ### Personal Schedule
 - Instantly view yesterday's, today's, tomorrow's or this week's timetable.

### Dark/Light Theme
- Toggle between dark and light modes for comfortable viewing in any environment.

### User Context
- Displays the student's name and role (Student, Monitor, Admin, etc.) pulled directly from the database.

  <img width="950" height="1108" alt="image" src="https://github.com/user-attachments/assets/a874fe7f-71ea-4cad-b191-e862654d9f0b" />

### Weekly Schedule View
Clicking "This Week" brings up your full timetable for the current week, with:

Odd/Even Indicator
- A clear badge (Odd or Even) shows which rotation you're in.

Day-by-Day Grid
- Columns for Monday → Friday (or Saturday, Sunday if you have weekend classes)
- Rows for each time slot (08:00–09:30, 09:45–11:15, etc.)

Cells display:

- Session type (curs., sem., lab.)
- Subject name
- Location (room or lab)

### Attendance Controls

- View My Attendance (individual student history)

- Log Attendance (for Monitors and Admins)

- View Group Attendance (full group statistics for Monitors and Admins)

Everything you need as a Monitor - schedule lookup, attendance logging, and analytics—is automated and just one click away.


## 2. View My Attendance

Each student can view and analyze their own attendance data through:

<img width="1000" height="711" alt="image" src="https://github.com/user-attachments/assets/23bb3ac9-a63b-4481-ac17-1df0d402b3a9" />

## Summary Cards
### Quick stats for:
- Today, This Week, This Month, and All Time.

- Shows total sessions, total absences (unmotivated vs. motivated) and absence rate.

- All Time card adds "Lab Misses" count and an Estimated Fee for any missed labs.

## By Subject Absence Rates
### A table breaking down, for each subject:
- Lecture, seminar and lab absence counts & percentages

- Overall absence rate per subject

## Detailed Absence Logs
### Full listings of every absence entry in separate tables for:
- This Week

- This Month

- All Time

This page gives students both a high‑level snapshot and deep dives into their attendance history—complete with custom lab‑fee estimates for missed practical sessions.

## 3. Log Attendance (For Monitors, Admins & Moderators)

This page empowers Monitors, Admins, and Moderators to record and review group attendance with ease:

<img width="770" height="859" alt="image" src="https://github.com/user-attachments/assets/2ec37d23-6c5e-4a58-a88b-2b16144afa2f" />

### Date Navigation
- Quickly jump to any past session using the "← 4d", "← 3d", "Today", "→ 1d" buttons, then return to the full schedule.

### Attendance Toggles
- Each student's row shows one toggle per time slot—green for present, red for absent.

### Motivation Controls
- For any absence, check Motivated and enter a custom reason (e.g. "Being late", "Feels sick").

### Audit Trail
- See which user marked each attendance entry and when, directly in the table.

## Attendance Editing 

  <img width="602" height="353" alt="image" src="https://github.com/user-attachments/assets/f2832153-7671-4061-86ef-5d67be5ada63" />

### Edit Any Entry
- Monitors, Admins, and Moderators can update attendance toggles or motivation flags after the fact.

### Who & When
- Each edited cell shows the user's name and the exact timestamp of the last change.

### Full History
- Every update is recorded in the database, ensuring a complete, tamper‑proof audit trail.

Everything is laid out in a responsive, dark/light‑theme table for fast, accurate logging and complete accountability.

## 3. View Group Attendance
This page provides a high‑level overview of your entire group's attendance:

<img width="619" height="908" alt="image" src="https://github.com/user-attachments/assets/52495dfa-ea34-4d3b-ba10-9fba855737e8" />

## Group Summary Cards
### Quick stats for the whole group showing:

- Today, This Week, This Month, and All Time

- Format: Present / Total (S%)

## Per‑Student Breakdown
### A table listing each student with columns for:

- Today, This Week, Month, All Time – sessions attended / total (%)

### View – button to drill down into that student's personal attendance page

All percentages are calculated on‑the‑fly, giving you instant insight into who's on track and who may need follow‑up.

## Export Attendance

The **Export Attendance** feature lets you download your group's attendance data as a CSV file. You can choose from five export modes:

<img width="696" height="311" alt="image" src="https://github.com/user-attachments/assets/01ae65a6-7c65-4816-a4de-2b54cb15a754" />
<br></br>

| Button       | Description                                                        |
|--------------|--------------------------------------------------------------------|
| **This Week**   | All sessions from Monday through Sunday of the current week       |
| **This Month**  | All sessions in the current calendar month                       |
| **All Time**    | Every recorded session since the database was created            |
| **By Subject**  | Attendance broken out per subject over the selected period       |
| **By Student**  | Detailed, per-student history over the selected period           |

### CSV Output Format

1. **Header rows**  
   - **Row 1:** `Group,<Your-Group-Name>`  
   - **(When exporting "By Subject" only) Row 2:** `Subject,<Subject-Name>`  
   - **Next row:** `Date,<Date1>,<Date2>,…`

2. **Data rows**  
   - **Column header:**  
     ```
     Student,<Status@Date1>,<Status@Date2>,…
     ```
   - **One row per student:**  
     ```
     <Student-Name>,<Status1>,<Status2>,…
     ```
     where each `<Status>` is one of:
     - `Present`
     - `Absent`
     <br>
     <img width="320" height="496" alt="image" src="https://github.com/user-attachments/assets/143672a0-0de7-4c2a-b987-042349276637" />

3. **File naming convention**  

Example: `group_241_attendance_month_01.08.2025`

</details>

---

## Project Structure

```
├── config.php                          # Database & app configuration (env-based)
├── db.php                              # Database functions & queries
├── tg_auth.php                         # Telegram initData validation, session & permission checks
├── index.php                           # Main attendance logging page
├── edit_attendance.php                 # Edit historical attendance records
├── view_attendance.php                 # View individual attendance stats
├── view_group_attendance.php           # View group-level attendance
├── greeting.php                        # Landing/greeting page
├── time_restrict.php                   # Time-based access control logic
├── oe_weeks.php                        # Semester/week type calculations
├── log_stats.php                       # Statistics logging endpoint
├── export.php                          # Export attendance data
├── script.js                           # Frontend UI logic (minified)
├── style.css                           # Main stylesheet
├── tableb.css                          # Compact table layout
├── tablec.css                          # Big table layout
└── init_db.sql                         # SQL DB Structure example
```

---

## Prerequisites

- **PHP 8.0+** with PDO MySQL extension
- **nginx** or **Apache2** modules
- **MySQL 5.7+** or **MariaDB 10.3+** for the DB
- **Telegram Bot Token** from [BotFather](https://t.me/botfather)

---

## Setup: Create Mini App in BotFather + Configure Hosting

<details>
<summary>Click to expand (Core Setup)</summary>

1. Open a chat with [@BotFather](https://t.me/BotFather) in Telegram.  
2. Send `/newbot`, then follow prompts to choose:
   - **Name:** Your bot's display name (e.g. _AttendanceBot_)  
   - **Username:** Must end in `_bot` (e.g. _AttendanceDemo_bot_)  
3. When BotFather returns your **API token**, copy it.  
4. Enable Mini App:
   1. Send `/mybots`, then choose your bot
   2. Bot Settings → Configure Mini App → Enable Mini App
   3. Set the Mini App URL (see hosting setup below)

5. Store your Bot Token securely:
   - Create `/etc/attendance-bot.env` with:
     ```ini
     BOT_TOKEN=your-telegram-bot-token
     APP_HOST=https://your-domain-or-tunnel.com
     SECRET_TOKEN=some-random-secret
     DB_HOST=127.0.0.1
     DB_NAME=attendance_utm
     DB_USER=DBuser
     DB_PASS=your-db-password
     ```

</details>

---

## Hosting Option 1: Raspberry Pi + Cloudflare Tunnel + Custom Domain

<details>
<summary>Click to expand (Recommended for always-on setup)</summary>

### Prerequisites

- Raspberry Pi (Zero 2 W is more than enough for a few grups)
- A domain (e.g., `domain-example.com`)
- Cloudflare account (free tier account is OK)

### 1. Install Dependencies on RPi

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx php-fpm php-mysql mysql-server git curl

# Enable and start services
sudo systemctl enable nginx php-fpm
sudo systemctl start nginx php-fpm
```

### 2. Configure Nginx

Create `/etc/nginx/sites-available/attendance-bot`:

```bash
sudo tee /etc/nginx/sites-available/attendance-bot > /dev/null << 'EOF'
server {
    listen 80;
    server_name _;

    root /var/www/html/attendance-bot;
    index index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.ht {
        deny all;
    }
}
EOF

sudo ln -s /etc/nginx/sites-available/attendance-bot /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl restart nginx
```

### 3. Deploy Project

```bash
cd /var/www/html
sudo git clone <your-repo> attendance-bot
cd attendance-bot
sudo chown -R www-data:www-data .
```

### 4. Initialize Database

```bash
sudo mysql -u root -p < migration_slotid_time_slots.sql
# Create database user if not exists:
# CREATE USER 'DBuser'@'localhost' IDENTIFIED BY 'your-db-password';
# GRANT ALL PRIVILEGES ON attendance_utm.* TO 'DBuser'@'localhost';
```

### 5. Create Configuration File

```bash
sudo tee /etc/attendance-bot.env > /dev/null << 'EOF'
BOT_TOKEN=your-bot-token-here
APP_HOST=https://domain-example.com
SECRET_TOKEN=your-secret-token-here
DB_HOST=127.0.0.1
DB_NAME=attendance_utm
DB_USER=DBuser
DB_PASS=your-db-password
EOF

sudo chmod 600 /etc/attendance-bot.env
```

### 6. Install Cloudflare Tunnel (Argo Tunnel)

```bash
# Install cloudflared
curl -L --output cloudflared.deb https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-arm64.deb
sudo dpkg -i cloudflared.deb

# Authenticate
cloudflared tunnel login
# Follow the browser prompt to authorize your Cloudflare account

# Create tunnel
cloudflared tunnel create attendance-bot
cloudflared tunnel route dns attendance-bot domain-example.com
```

### 7. Configure Tunnel

Create `~/.cloudflared/config.yml`:

```yaml
tunnel: attendance-bot
credentials-file: /home/pi/.cloudflared/attendance-bot.json

ingress:
  - hostname: domain-example.com
    service: http://localhost
  - service: http_status:404
```

### 8. Start Tunnel as Service

```bash
sudo cloudflared service install
sudo systemctl start cloudflared
sudo systemctl enable cloudflared

# Check status
sudo systemctl status cloudflared
```

### 9. Set Telegram Mini App URL in BotFather

```
https://domain-example.com/greeting.php
```

### 10. Test

- Open your bot in Telegram
- Tap the Mini App button
- You should see the greeting page

</details>

---

## Hosting Option 2: Local PC with Ngrok (Old miniapp version, see releases)

<details>
<summary>Click to expand (skip if you've already done this)</summary>

### 1. Prerequisites
- **XAMPP** Control Panel installed and running:
  - Apache → port 80  
  - MySQL → port 3306  
- Your bot's code in `C:\xampp\htdocs\TG-Bot-Folder`

### 2. Verify Local Setup
Open in your browser:  http://localhost/TG-Bot-Folder

You should see your page.

### 3. Install & Authenticate ngrok

As Admin in PowerShell:
Through Chocolatey  `choco install ngrok -y` then run `ngrok http 80`

Chocolatey Installation: 
```
Set-ExecutionPolicy Bypass -Scope Process -Force
[System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072
iex ((New-Object System.Net.WebClient).DownloadString('https://community.chocolatey.org/install.ps1'))
```

Or Manually:
1. Download ngrok: https://ngrok.com/download
2. Unzip `ngrok.exe` to, e.g., `C:\tools\ngrok\`  
3. Sign up at https://dashboard.ngrok.com/signup and copy your **authtoken** from "Get Started"  
4. In PowerShell:
   ```
   cd C:\tools\ngrok
   .\ngrok.exe config add-authtoken YOUR_AUTHTOKEN
   ```

### 4. Start the Tunnel
```
cd C:\tools\ngrok
.\ngrok.exe http 80
```
Copy the Forwarding URL (e.g. https://abcd1234.ngrok-free.app).

### 5. Set Your Telegram Webhook
```
Invoke-WebRequest "https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook?url=<NGROK_URL>/TG_Bot/webhook.php"
```

### 6. Test
Send `/start` to your bot; it should reply.
View live HTTP logs at: http://127.0.0.1:4040/inspect/http

</details>

---

## Environment Variables

| Variable | Description | Example |
|----------|-------------|---------|
| `APP_HOST` | Public URL of your app | `https://domain-example.com` |
| `BOT_TOKEN` | Telegram Bot API token | `123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11` |
| `SECRET_TOKEN` | Secret for request validation | `your-random-secret-key` |
| `DB_HOST` | MySQL host | `127.0.0.1` |
| `DB_NAME` | Database name | `attendance_utm` |
| `DB_USER` | Database user | `DBuser` |
| `DB_PASS` | Database password | `your-secure-password` |

---

## Security Notes

- **HTTPS only**: Ensure your domain uses HTTPS (Cloudflare provides free SSL).
- **Database backups**: Regularly back up your MySQL database.
- **Access control**: Role-based permissions are enforced in `time_restrict.php`.

---

## Troubleshooting
<details>
<summary>Click to expand</summary>

### Bot doesn't respond
- Verify `BOT_TOKEN` is correct in `/etc/attendance-bot.env`.
- Check that your tunnel (Cloudflare or ngrok) is active and properly configured.

### Database connection error
- Ensure MySQL is running: `sudo systemctl status mysql`
- Verify credentials in `/etc/attendance-bot.env`.
- Test connection: `mysql -u DBuser -p -h 127.0.0.1 attendance_utm`

### Mini App not loading
- If `Invalid user/Telegram ID` check the DB table `users` for wrong `tg_id` or `role`.
- Verify `APP_HOST` matches your actual domain.
- Check PHP error logs: `/var/log/php-errors.log` or `journalctl -u apache2`
</details>
