Messenger Extra
===============

[![Tests](https://github.com/alekitto/messenger-extra/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/alekitto/messenger-extra/actions/workflows/tests.yml)

This library provides additional transports (and other things) for the symfony messenger component.

## Transports

### Doctrine DBAL

A transport using doctrine DBAL can be used through the `DbalTransportFactory`.

Supports delayed, prioritized, expiring (TTL) and unique messages.

The dsn supports the following schemes:

- `db2`
- `mssql`
- `mysql`
- `mysql2`
- `postgres`
- `postgresql`
- `pgsql`
- `sqlite`
- `sqlite3`

### Doctrine ORM

An existing ORM connection can be used with `doctrine` scheme.

For example `doctrine://default/queue` will use the `default` doctrine
connection with the `queue` table.

The `doctrine` scheme will also register a `postGenerateSchema`
event listener that will create the correct table during a schema update
(or migration if using DoctrineMigrations)

### Mongo

Transport using MongoDB PHP client as transport.

Supports delayed, prioritized, expiring (TTL) and unique messages.

Use DSN with `mongodb` scheme with `/database/collection` path
(database `default` and `queue` collection are used if not specified).

#### Mongodb authentication

For authenticated database, with user configured per database, you could
specify `authSource` option in DSN query string:

```
mongodb://user:pass@server:port/database?authSource=database
```

> :warning: NOTE that if a username is passed in DSN the `authSource` connection option
is already added to the mongo uri when passing to the client library.
Its value is equal to the database selected (`default` if missing).

> :information_source: For information about the supported options in mongo DSN you can check
the [official documentation page on PHP.net](https://www.php.net/mongodb-driver-manager.construct#mongodb-driver-manager.construct-urioptions)

### Null

Useful for testing environments, where no message should be dispatched.

## Messages utilities

### DelayedMessageInterface

Allows to specify a message delay. Implement this in your message class to delay the delivery of your message.

### TTLAwareMessageInterface

Allows the expiration of a message.
Implement this interface if you want your message to expire after a given amount of time.

### UniqueMessageInterface

Adds a message only once in the queue.
If another message with the same uniqueness key is present, the message is discarded.

## Compatibility

| Version    | Compatible Symfony Version | Build Status |
|------------|----------------------------|--------------|
| >= 2.2.0   | ^4.4, ^5.0                 | [![Tests](https://github.com/alekitto/messenger-extra/actions/workflows/tests.yml/badge.svg?branch=2.x)](https://github.com/alekitto/messenger-extra/actions/workflows/tests.yml) |
| 2.0, 2.1   | ^4.3, ^5.0                 | Not available |
| 1.x        | 4.2.*                      | Not available |

## Symfony bundle

A symfony bundle is included in the code under /lib:
Use `MessengerExtraBundle` to fully integrate this library into your symfony application.
Just add this to `bundles.php`:

```
    Kcs\MessengerExtra\MessengerExtraBundle::class => ['all' => true],
```

Available transports and functionalities will be registered automatically.

---

Amazing things will come shortly.

## License & Contribute

This library is released under MIT license.

Feel free to open an issue or a PR on GitHub. If you want to contribute to this project, you're welcome!

A.

