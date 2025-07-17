@echo off
:: Запускаем PowerShell с правами Админа и исполняем ваш PS1
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "Start-Process PowerShell -Verb RunAs -ArgumentList '-NoProfile -ExecutionPolicy Bypass -File ""C:\xampp\htdocs\TG_Bot\run-bot.ps1""'"
