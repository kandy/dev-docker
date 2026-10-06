# Adobe Commerce Local Development Environment

## Purpose

This repository provides Docker infrastructure for local Adobe Commerce
(Magento) development. It is a development environment rather than an Adobe
Commerce application repository.

Start with the root `README.md`. Use the repository's `mdev` wrapper for normal
environment operations instead of invoking scripts in `bin/` directly.

## Repository Scope

The tracked repository contains the local development environment:

- `mdev` and `bin/`: environment commands
- `docker-compose.yml`: core service definitions
- `compose/`: optional and shared Compose definitions
- `build/`: local container images
- `etc/`: nginx, PHP, and service configuration

The `src/` directory is a git-ignored Adobe Commerce source checkout mounted
into the containers at `/magento`.

- Read and edit Commerce source files locally under `src/`.
- Run PHP, Composer, Magento CLI, and test commands in the `app` container.
- Do not expect changes under `src/` to appear in this repository's Git diff.
- Before changing code, determine whether the task belongs to this
  infrastructure repository or to the separate repository checked out in
  `src/`.

## Prerequisites and Initial Setup

The host needs Docker with Docker Compose, Mutagen, Git, and OpenSSL.

Typical setup:

```bash
./mdev up
./mdev init
./mdev install
```

`mdev init` supports these source variants:

```bash
./mdev init template  # Magento Cloud template
./mdev init git       # Magento Open Source 2.4-develop
./mdev init git-ee    # Adobe Commerce CE and EE repositories
```

The `git-ee` option requires access to the private Adobe Commerce repositories
and working host SSH credentials.

`mdev install` resets and reinstalls the local Commerce application and its
data. Treat it as destructive: do not run it when existing local data must be
preserved unless the user explicitly requests a reinstall.

## Common Commands

```bash
./mdev                    # list available commands
./mdev up                 # start the proxy and application services
./mdev down               # stop application services
./mdev in                 # open a shell in the app container
./mdev exec app <command> # run a command in the app container
```



Use `./mdev help <command>` when help exists. If a wrapper does not support a
needed operation, use `./mdev exec <service> <command>` or `docker compose`
from the repository root.

## Local URLs and Proxy

`mdev up` creates or reuses the external `traefik-ingress` network and a
compatible Traefik gateway. The storefront URL is derived from `MAGENTO_BASE_URL`,
`INGRESS_SERVICE_DOMAIN`, and the Compose project name. `mdev install` prints
the resolved URL when installation completes.

The proxy generates a self-signed certificate under `.docker/traefik/`. Browser
trust warnings are expected unless the certificate is trusted locally.

Usally base url is https://ccsaas.test/$(basename $(pwd))/  

## Runtime Services

The default Compose stack includes:

- `webserver`: nginx
- `app`: PHP application runtime
- `app-xdebug`: PHP runtime with Xdebug
- `db`: MariaDB
- `redis`: cache and session storage
- `elastic`: OpenSearch
- `rabbit`: RabbitMQ
- `fluentbit`: log forwarding


Use normal Adobe Commerce CLI functionality in this environment, including
cache, indexer, cron, queue, setup, module, and deployment commands when
appropriate.

## Working With Adobe Commerce Source

Follow the conventions of the source repository checked out in `src/`; its own
instructions take precedence for files under that directory.

For PHP changes:

- Declare strict types when required by the target module's conventions.
- Use dependency injection; do not use the Object Manager directly in
  application code.
- Keep constructors limited to dependency assignment and argument validation.
- Prefer service contracts and existing framework abstractions.
- Use factories or injected collaborators instead of directly constructing
  framework objects.
- Use prepared statements or Magento database adapters with bound parameters.
- Add or update focused tests for changed behavior.
- Preserve backward compatibility for public APIs unless the task explicitly
  requires a reviewed breaking change.
- Any new/changed event observers/plugins must be covered with integration tests (new or modification of exists) 
  of target class

For infrastructure changes:

- Keep service versions, health checks, dependencies, volumes, and network
  behavior consistent across related Compose files.
- Preserve the `./src:/magento` mount unless a task explicitly changes the
  source layout.
- Prefer configuration through existing environment variables and Compose
  defaults.
- Do not add credentials, repository tokens, private keys, or populated
  `auth.json` files to Git.
- Update the root `README.md` when setup steps or user-facing commands change.

## Security

Apply standard Adobe Commerce secure coding practices:

- Validate all external input and enforce authorization server-side.
- Escape output for its HTML, attribute, JavaScript, URL, or CSS context.
- Use `RequestInterface` and framework abstractions instead of PHP
  superglobals in application logic.
- Never concatenate untrusted input into SQL, shell commands, file paths, or
  outbound URLs.
- Protect state-changing browser requests with form-key or CSRF validation.
- Do not log credentials, access tokens, customer data, or other sensitive
  information.
- Never hardcode credentials or commit local secrets.
- Do not weaken TLS, authentication, authorization, or input validation merely
  to simplify local setup.

## Validation

Choose the smallest validation that covers the change.

For environment changes:

```bash
docker compose config
./mdev up
docker compose ps
```

Inspect affected service logs with:

```bash
docker compose logs <service>
```

For Commerce source changes, run the test and static-analysis commands provided
by the source repository. The local PHPUnit wrapper is:

```bash
./mdev test <phpunit arguments>
```

Common Commerce maintenance commands can be run through:

```bash
./mdev magento setup:upgrade
./mdev magento setup:di:compile
./mdev magento indexer:reindex
./mdev magento cache:flush
```

Only run broad, destructive, or time-consuming commands when they are necessary
for the requested change.

## Change Discipline

- Keep changes focused on the requested local-development behavior.
- Do not edit generated files or local state when a source configuration change
  is sufficient.
- Do not remove volumes, reset databases, reinstall Commerce, or delete the
  `src/` checkout without explicit approval.
- Do not commit, push, or create a pull request without explicit user approval.
- Before finishing, review the diff and verify that commands documented in this
  file exist in the current repository.

Adobe Commerce (aka Magento) is including multiple repos: ce - in `src` directory, ee - `ee`, and b2b - in `b2b` directory.
