---
paths:
  - '**/*'
  - '**/*.php'
---

# General

## Telegram-only user authentication
Authenticate users through cryptographically validated Telegram Mini App initData and identify persisted users by unique telegram_id. Do not add email/password credentials, password-reset tables, remember tokens, or database-backed sessions unless the authentication design is explicitly changed.

## Run Larastan after PHP changes
После изменения PHP-кода запускайте `composer analyse` и устраняйте все ошибки Larastan/PHPStan перед завершением работы. Не добавляйте baseline или `ignoreErrors` только ради зелёного результата: сначала подтвердите, что сообщение является ложным срабатыванием.
