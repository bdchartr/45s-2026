# Running Tests

1. Install dependencies:

```bash
composer install
```

2. Run all tests:

```bash
composer test
```

Current test focus:
- Game runtime flow and turn advancement behavior
- Bidding close/advance behavior
- Card play turn advancement
- SessionAuth CSRF token generation/validation lifecycle
- RateLimiter window and retry-after behavior
- Route-level CSRF rejection (403 invalid_csrf_token)
- Route-level auth rate limit rejection (429 rate_limited)

Planned next additions:
- Route-level validation tests for create/join game payloads
- Stats query contract tests
- Route-level authorization and CSRF failure-path coverage
