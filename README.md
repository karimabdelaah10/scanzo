# Scanzo

A multi-tenant point-of-sale system. Every item carries a QR label; the seller
scans it with a phone or a USB scanner, and the system builds the invoice,
handles returns and keeps stock per branch.

Full documentation lives in [`docs/`](docs/README.md).

## Layout

```
.
├─ core/            the Laravel application
├─ .docker/         Dockerfile, nginx, php and supervisor config
├─ .docker-data/    mysql data (gitignored)
├─ scripts/         entrypoint and setup scripts
└─ docs/            schema, decisions and roadmap
```

## Running it

Create the root `.env` (docker settings — container names, ports, database
credentials):

```bash
./scripts/create_env_file.sh
```

Edit it if the default ports clash with anything, then create the network and
start the stack:

```bash
docker network create scanzo-network
docker compose up --build -d
```

Prepare the Laravel app inside the container:

```bash
docker exec -it scanzo-app bash
/bin/prepare_env.sh
```

That copies `core/.env.example` to `core/.env`, generates the app key, links
storage and runs the migrations. Edit `core/.env` afterwards if you need to.

The app is then on <http://127.0.0.1:49100> (or whatever `MAIN_NGINX_PORT` you set).

## When Laravel says "Permission denied"

`core/` is bind-mounted into the containers, so the image's own ownership is
replaced by whatever the host files carry, and a file written by an editor or a
sync tool can land unreadable by php-fpm. From the host:

```bash
./scripts/fix_host_permissions.sh
```

Then, if the config was cached before the fix:

```bash
docker exec -it scanzo-app php artisan optimize:clear
```

## Ports

| Service        | Host port |
|----------------|-----------|
| nginx          | 49100     |
| mysql          | 43406     |
| mysql (test)   | 43407     |

## Tests

```bash
docker exec -it scanzo-app php artisan test
```
