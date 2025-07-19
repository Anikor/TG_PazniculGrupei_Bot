# AttendanceBot [![Telegram Bot](https://img.shields.io/badge/Telegram-Bot-blue?logo=telegram)](https://t.me/PazniculGrupeiBot)

**AttendanceBot** is a Telegram bot that makes tracking and viewing student attendance a breeze.  
Group leaders can mark attendance with a tap, view detailed reports, and students can self-check their stats—all without leaving Telegram.

---

## Setup Mini App option in BotFather

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
   3. Send to bot your https link, since i run my bot localy on PC i'll use ngrok (See below) (https://234yourlink.ngrok-free.app/TG_Bot/miniapp/greeting.php)

6. In your project’s `src/config.php`, set:
   define('TELEGRAM_TOKEN', 'PASTE_YOUR_TOKEN_HERE');
And in poll.php: $host = (https://234yourlink.ngrok-free.app/TG_Bot/miniapp/index.html)
   


## HOW TO SET UP NGROK (To run localy on your PC)

<details>
<summary>Click to expand (skip if you’ve already done this)</summary>
   
### 1. Prerequisites
- **XAMPP** Control Panel running:
  - Apache → port 80  
  - MySQL → port 3306  
- Your bot’s code in `C:\xampp\htdocs\TG_Bot`

### 2. Verify Local Setup
Open in your browser:  http://localhost/TG_Bot

You should see your page.

### 3. Install & Authenticate ngrok
1. Download ngrok for Windows: https://ngrok.com/download  
2. Unzip `ngrok.exe` to, e.g., `C:\tools\ngrok\`  
3. Sign up at https://dashboard.ngrok.com/signup and copy your **authtoken** from “Get Started”  
4. In PowerShell:
   cd C:\tools\ngrok
   .\ngrok.exe config add-authtoken YOUR_AUTHTOKEN
### 4. Start the Tunnel
cd C:\tools\ngrok
.\ngrok.exe http 80
Copy the Forwarding URL (e.g. https://abcd1234.ngrok-free.app).

### 5. Set Your Telegram Webhook
Invoke-WebRequest "https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook?url=<NGROK_URL>/TG_Bot/webhook.php"

### 6. Test
Send /start to your bot; it should reply.
View live HTTP logs at: http://127.0.0.1:4040/inspect/http




   
