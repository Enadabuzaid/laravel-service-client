## enadstack/laravel-service-client

- Service clients extend `Enadstack\ServiceClient\ServiceClient`; call them only from Actions/jobs, never controllers or views.
- Catch `Enadstack\ServiceClient\ServiceException` and branch on `code()` (e.g. `SERVICE_UNREACHABLE`); check `isConfigured()` before calling when a local fallback exists.
- In tests use `<Name>Client::fake()`; never hit a real service.
- Env: `SERVICE_APPLICATION_CODE`, `<NAME>_SERVICE_URL`, `<NAME>_SERVICE_API_KEY`, `<NAME>_WEBHOOK_SECRET`.
- Incoming webhooks: route middleware `service-webhook:<name>`.
