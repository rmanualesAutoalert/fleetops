# Laravel + Vue Starter Kit

## Introduction

Our Vue starter kit provides a robust, modern starting point for building Laravel applications with a Vue frontend using [Inertia](https://inertiajs.com).

Inertia allows you to build modern, single-page Vue applications using classic server-side routing and controllers. This lets you enjoy the frontend power of Vue combined with the incredible backend productivity of Laravel and lightning-fast Vite compilation.

This Vue starter kit utilizes Vue 3 and the Composition API, TypeScript, Tailwind, and the [shadcn-vue](https://www.shadcn-vue.com) component library.

## Local demo login

With the local database migrated and `APP_ENV=local`, create a login account:

```sh
php artisan migrate
php artisan db:seed --class=UserSeeder
```

Log in with `advisor@fleetops.test` and password `password`. This verified account
has the `service_advisor` role, including access to service advisor reports.
The password is hashed by the User model. Re-running this seeder preserves an
existing account without resetting its password or role.

`DatabaseSeeder` calls `UserSeeder` after the FleetOps dataset seeder.
Run the user seeder alone when you only need a login; running the full dataset
seeder again adds more business records. The demo account is only seeded in the
`local` environment.

The demo user is linked to the first advisor without a login, preserving that
advisor's branch and appointments. If none is available, the user seeder creates
a demo advisor in the first branch (or creates a branch on an empty database).
Existing user credentials and advisor assignments are preserved on reruns.
`$user->advisor` and `$advisor->user` expose the relationship. The advisor's
contact email is separate from the user's login email.

`advisors.user_id` is nullable and unique: advisors can exist without accounts,
and deleting a user retains their advisor record with a null link. Customers
remain business records without login accounts. The relationship does not change
the existing role middleware or introduce branch access restrictions.

## Official Documentation

For the Appointments Board, local setup, API contract and performance checks, see
[the board guide](docs/appointments-board.md),
[PR evidence](docs/appointments-board-pr-note.md), and
[the migration safety playbook](migration-safety-note.md).

Documentation for all Laravel starter kits can be found on the [Laravel website](https://laravel.com/docs/starter-kits).

## Contributing

Thank you for considering contributing to our starter kit! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

All contributions to the Starter Kits from now on should be made through [Maestro](https://github.com/laravel/maestro).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## License

The Laravel + Vue starter kit is open-sourced software licensed under the MIT license.
