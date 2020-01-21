Messenger Extra
===============

[![Build Status](https://travis-ci.com/alekitto/messenger-extra.svg?branch=master)](https://travis-ci.com/alekitto/messenger-extra)

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

Transport using MongoDB PHP client as trasport.

Supports delayed, prioritized, expiring (TTL) and unique messages.

Use DSN with `mongodb` scheme with `/database/collection` path
(database `default` and `queue` collection are used if not specified).

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
| dev-master | 4.3.*                      | [![Build Status](https://travis-ci.com/alekitto/messenger-extra.svg?branch=master)](https://travis-ci.com/alekitto/messenger-extra) |
| 1.x        | 4.2.*                      | [![Build Status](https://travis-ci.com/alekitto/messenger-extra.svg?branch=1.x)](https://travis-ci.com/alekitto/messenger-extra)    |

## Symfony bundle

A symfony bundle is included in the code under /lib: 
Use `MessengerExtraBundle` to fully integrate this library into your symfony application.
Just add this to `bundles.php`:
```
    ...
    Kcs\MessengerExtra\MessengerExtraBundle::class => ['all' => true],
```
Available transports and functionalities will be registered automatically.

---

Amazing things will come shortly.

## License & Contribute

This library is released under MIT license.

Feel free to open an issue or a PR on GitHub. If you want to contribute to this project, you're welcome!

A.

