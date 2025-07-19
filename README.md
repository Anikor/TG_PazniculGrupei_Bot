# AttendanceBot [![Telegram Bot](https://img.shields.io/badge/Telegram-Bot-blue?logo=telegram)](https://t.me/PazniculGrupeiBot)

**AttendanceBot** is a Telegram bot that makes tracking and viewing student attendance a breeze.  
Group leaders can mark attendance with a tap, view detailed reports, and students can self-check their stats—all without leaving Telegram.

---

## Features

<details>
<summary>See the features of @PazniculGrupeibot </summary>

## 1. Greeting page
   
The Greeting Page is your bot’s central dashboard, designed for maximum convenience and lightning‑fast access:

<img width="578" height="743" alt="image" src="https://github.com/user-attachments/assets/9942c808-6701-4897-9a0f-4285c5873732" />

 ### Personal Schedule
 - Instantly view yesterday’s, today’s, tomorrow’s or this week’s timetable.

### Dark/Light Theme
- Toggle between dark and light modes for comfortable viewing in any environment.

### User Context
- Displays the student’s name and role (Student, Monitor, Admin, etc.) pulled directly from the database.

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

- All Time card adds “Lab Misses” count and an Estimated Fee for any missed labs.

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
- Quickly jump to any past session using the “← 4d”, “← 3d”, “Today”, “→ 1d” buttons, then return to the full schedule.

### Attendance Toggles
- Each student’s row shows one toggle per time slot—green for present, red for absent.

### Motivation Controls
- For any absence, check Motivated and enter a custom reason (e.g. “Being late”, “Feels sick”).

### Audit Trail
- See which user marked each attendance entry and when, directly in the table.

## Attendance Editing 

  <img width="602" height="353" alt="image" src="https://github.com/user-attachments/assets/f2832153-7671-4061-86ef-5d67be5ada63" />

### Edit Any Entry
- Monitors, Admins, and Moderators can update attendance toggles or motivation flags after the fact.

### Who & When
- Each edited cell shows the user’s name and the exact timestamp of the last change.

### Full History
- Every update is recorded in the database, ensuring a complete, tamper‑proof audit trail.

Everything is laid out in a responsive, dark/light‑theme table for fast, accurate logging and complete accountability.

## 3. View Group Attendance
This page provides a high‑level overview of your entire group’s attendance:

<img width="619" height="908" alt="image" src="https://github.com/user-attachments/assets/52495dfa-ea34-4d3b-ba10-9fba855737e8" />

## Group Summary Cards
### Quick stats for the whole group showing:

- Today, This Week, This Month, and All Time

- Format: Present / Total (S%)

## Per‑Student Breakdown
### A table listing each student with columns for:

- Today, This Week, Month, All Time – sessions attended / total (%)

## View – button to drill down into that student’s personal attendance page

All percentages are calculated on‑the‑fly, giving you instant insight into who’s on track and who may need follow‑up.



</details>


---

## Setup Mini App option in BotFather + Ngrok setup for the mini app

<details>
<summary>Click to expand (skip if you’ve already done this)</summary>

1. Open a chat with [@BotFather](https://t.me/BotFather) in Telegram.  
2. Send `/newbot`, then follow prompts to choose:
   - **Name:** Your bot’s display name (e.g. _AttendanceBot_)  
   - **Username:** Must end in `_bot` (e.g. _AttendanceDemo_bot_)  
3. When BotFather returns your **API token**, copy it.  
4. Set your bot:
   1. Send `/mybots`, then follow than choose:
   2. Bot Settings -> Configure Mini App -> Enable Mini App
   3. Send to bot your https link, since i run my bot localy on PC i'll use ngrok (See how to setup below) (https://234yourlink.ngrok-free.app/TG_Bot/miniapp/greeting.php)

6. In your project’s `src/config.php`, set:
   define('TELEGRAM_TOKEN', 'PASTE_YOUR_TOKEN_HERE');
   
And in `poll.php`: $host = (https://234yourlink.ngrok-free.app/TG_Bot/miniapp/index.html)
   


## HOW TO SET UP NGROK (To run localy on your PC)

<details>
<summary>Click to expand (skip if you’ve already done this)</summary>
   
### 1. Prerequisites
- **XAMPP** Control Panel installed and running:
  - Apache → port 80  
  - MySQL → port 3306  
- Your bot’s code in `C:\xampp\htdocs\TG_Bot`

### 2. Verify Local Setup
Open in your browser:  http://localhost/TG_Bot

You should see your page.

### 3. Install & Authenticate ngrok

As Admin in PowerShell:
Through Chocolatey  `choco install ngrok -y` then run `ngrok http 80`

Chocolatey Installation: 
`Set-ExecutionPolicy Bypass -Scope Process -Force
[System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor  3072
iex ((New-Object System.Net.WebClient).DownloadString('https://community.chocolatey.org/install.ps1'))`


Or Manually:
1. Download ngrok: https://ngrok.com/download
2. Unzip `ngrok.exe` to, e.g., `C:\tools\ngrok\`  
3. Sign up at https://dashboard.ngrok.com/signup and copy your **authtoken** from “Get Started”  
4. In PowerShell:
`
   cd C:\tools\ngrok
   .\ngrok.exe config add-authtoken YOUR_AUTHTOKEN `
### 4. Start the Tunnel
` cd C:\tools\ngrok
.\ngrok.exe http 80 `
Copy the Forwarding URL (e.g. https://abcd1234.ngrok-free.app).

### 5. Set Your Telegram Webhook
Invoke-WebRequest "https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook?url=<NGROK_URL>/TG_Bot/webhook.php"

### 6. Test
Send `/start` to your bot; it should reply.
View live HTTP logs at: http://127.0.0.1:4040/inspect/http




   
