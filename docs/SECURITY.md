# Security Policy & Best Practices

## Reporting Security Issues
If you discover a security vulnerability within FocusFlow backend, please open a private security advisory or contact the repository maintainers directly.

## Implemented Security Safeguards

1. **SQL Injection Mitigation**:
   - All database queries are executed via PDO prepared statements with parameter binding. Direct string interpolation into queries is prohibited.

2. **CORS & HTTP Security Headers**:
   - Headers include `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, and strict CORS policies.

3. **Input Sanitization**:
   - All string inputs are passed through `htmlspecialchars` and `strip_tags` via `Validator::sanitizeString`.

4. **Rate Limiting**:
   - IP-based rate limiting prevents brute-force login attempts and flood requests.

5. **Groot AI Prompt Isolation**:
   - User inputs forwarded to the Groot AI inference server are properly sanitized and wrapped in predefined system context guards.
