# Contributing to FocusFlow Backend

Thank you for contributing to FocusFlow!

## Development Workflow

1. Fork or branch from `main`.
2. Follow standard PSR coding styles for all PHP code.
3. Write atomic, descriptive Git commits:
   - Format: `<verb in imperative mood> <feature or fix description>`
   - Avoid emojis in commit messages and codebase.
4. Ensure all unit tests pass before submitting a pull request:
   ```bash
   php tests/run_all_tests.php
   ```
5. Update `docs/API.md` and `docs/openapi.yaml` when modifying existing endpoints or adding new ones.
