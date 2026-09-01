# Laravel Internals Notes

## Container

Laravel's service container is responsible for constructing classes and supplying their dependencies. When a controller requests `ReportGenerator`, the container looks up the binding registered for that interface and creates `ServiceRecordReportGenerator`. It can also automatically resolve concrete dependencies, such as the `ViewFactory` injected into `HandleAppearance`. This keeps classes focused on what they need instead of making them responsible for locating or constructing dependencies.

> I used to think the container was only a place to store shared objects; actually, it is the mechanism Laravel uses to resolve dependencies and assemble the application.

## Provider

A service provider tells Laravel how application services should be registered and bootstrapped. The `register()` method is where container bindings belong, which is why `ReportServiceProvider` maps `ReportGenerator` to `ServiceRecordReportGenerator` there. The `boot()` method is for behavior that should run after all providers have registered their services, such as registering listeners or view composers. Our provider currently has no boot-time work, so its `boot()` method remains empty.

> I used to think `register()` and `boot()` were interchangeable; actually, `register()` defines services while `boot()` uses services after registration is complete.

## Facade

A facade provides a short, static-looking API to an object managed by Laravel's container. A call such as `View::share()` is not a normal static method call; Laravel forwards it to the underlying view service. Facades are convenient, but they can hide a class's dependencies. Refactoring `HandleAppearance` to inject `ViewFactory` made the dependency explicit and allowed a unit test to provide a mock directly.

> I used to think a facade was just a static helper class; actually, it is a proxy to an object resolved through Laravel's service container.

## Middleware

Middleware sits in the HTTP request pipeline and decides whether a request should continue to its destination. `EnsureServiceAdvisor` checks the authenticated user's role and returns a `403 Forbidden` response when the user is not a service advisor; otherwise, it passes the request to the next middleware or controller. Applying it to the report route group ensures every route in that group uses the same authorization rule, while the feature tests prove that the rule is genuinely enforced.

> I used to think middleware only ran before a controller; actually, it wraps the request pipeline and can perform work both before and after the next handler runs.
