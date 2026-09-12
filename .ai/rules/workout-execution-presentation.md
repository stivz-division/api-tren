---
paths:
  - 'app/WorkoutExecution/Presentation/**'
---

# Workout Execution Presentation

## Workout execution HTTP contract
Protect every endpoint with auth:sanctum and derive user identity only from the authenticated User. Mutation routes use workoutSessionId plus the source exerciseId (never persistence IDs). Save sets as full ordered draft replacement; HTTP exposes kilograms with at most 2 decimals while Application receives integer grams. Missing and cross-user resources share 404 responses; state and mutation-lock conflicts use stable 409 codes.
