---
paths:
  - 'app/WorkoutExecution/Infrastructure/**'
---

# Infrastructure

## Хранение снимка выполнения тренировки
Хранить агрегат выполнения нормализованно в workout_sessions/workout_exercises/workout_sets. training_program_id и exercise_id являются ссылками на источник снимка без FK: история должна переживать удаление плана и каталожного упражнения. Времена сохранять в UTC и восстанавливать в Europe/Moscow; исходную программу читать транзакционно под shared lock, а единственную active-сессию гарантировать частичным unique index.
