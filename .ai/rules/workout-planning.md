---
paths:
  - 'app/WorkoutPlanning/**'
---

# Workout Planning

## WorkoutPlanning persistence and mutation locking
Хранить Eloquent-модели и реализации портов внутри WorkoutPlanning/Infrastructure, не связывая Domain с Laravel. Изменения расписания (create/update/delete) сериализуются Redis atomic lock по users.id; транзакции и уникальные ограничения БД остаются окончательной защитой. Программы удаляются физически, дочерние planned_exercises — каскадно.
