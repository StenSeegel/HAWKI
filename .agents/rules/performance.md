---
trigger: always_on
---

## Performance
1. **Settings Caching**: Since settings (e.g., filter options) are queried on every request, they must be cached via `Cache::remember`. The cache must be cleared on every change in the corresponding settings screen