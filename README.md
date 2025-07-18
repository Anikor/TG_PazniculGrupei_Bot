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
4. (Optional) Customize your bot:
   - `/setdescription` → select your bot → _“A Telegram bot for marking and viewing student attendance.”_  
   - `/setabouttext` → select your bot → any longer intro text.  
   - `/setuserpic` → select your bot → upload an icon.  
   - `/setcommands` → select your bot → paste:
     ```
     start – Initialize bot & choose group
     select_group – Change your active group
     mark – Mark attendance for current session
     view – View attendance report
     ```
5. In your project’s `src/config.php`, set:
   ```php
   define('TELEGRAM_TOKEN', 'PASTE_YOUR_TOKEN_HERE');
