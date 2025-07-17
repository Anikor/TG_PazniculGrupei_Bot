# run-bot.ps1
# Сначала проверим, запущен ли скрипт с правами администратора
If (-not ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()
    ).IsInRole([Security.Principal.WindowsBuiltinRole] "Administrator")) {
    # Перезапустим этот скрипт с правами администратора
    Start-Process powershell `
        -ArgumentList "-NoProfile -ExecutionPolicy Bypass -File `"$PSCommandPath`"" `
        -Verb RunAs
    Exit
}

# Если мы здесь — значит уже с админ-правами
Write-Host "Running as Administrator, starting bot..." -ForegroundColor Green

# Переходим в папку с ботом
Set-Location "C:\xampp\htdocs\TG_Bot"

# Запускаем скрипт polling
php poll.php

# По завершении можно оставить окно открытым
Read-Host "Press Enter to exit"
