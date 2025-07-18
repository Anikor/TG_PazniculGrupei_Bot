# AttendanceBot [![Telegram Bot](https://img.shields.io/badge/Telegram-Bot-blue?logo=telegram)](https://t.me/PazniculGrupeiBot)

**AttendanceBot** is a Telegram bot that makes tracking and viewing student attendance a breeze.  
Group leaders can mark attendance with a tap, view detailed reports, and students can self-check their stats—all without leaving Telegram.

---

## Setup in BotFather

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
   3. Send to bot your https link, since i run my bot localy on PC i'll use ngrok (https://234yourlink.ngrok-free.app/TG_Bot/miniapp/greeting.php)

6. In your project’s `src/config.php`, set:
   ```php
   define('TELEGRAM_TOKEN', 'PASTE_YOUR_TOKEN_HERE');
