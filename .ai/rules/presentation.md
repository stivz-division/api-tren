---
paths:
  - 'app/WorkoutPlanning/Presentation/**'
---

# Presentation

## WorkoutPlanning HTTP boundary
Использовать invokable-контроллеры и auth:sanctum; users.id брать только из авторизованного пользователя. HTTP принимает и возвращает working_weight_kg с максимум двумя знаками после запятой, а перед Application преобразует вес в целые граммы. Исключения Domain/Application преобразуются в стабильные JSON-коды централизованно через стандартный Laravel handler в bootstrap/app.php.
