Docker infrastructure for Magento development

# Usage
- Checkout this repository
- Install docker using [official guidelines](https://docs.docker.com/install/)
- Install docker compose using the same guidelines
- Install [mutagen](https://mutagen.io/documentation/introduction/installation)
- Run 
  ```
    mdev up
    mdev init
    mdev install
  ```

## Running PHPUnit tests

`mdev test` runs PHPUnit in the running `app` container with the container's
configured PHP runtime. The plain form passes arguments through to PHPUnit.
The `static`, `unit`, and `integration` helpers compare the `src/` checkout
against `MDEV_TEST_BASE` (default `origin/2.4-develop`, with local fallbacks)
and include staged, unstaged, and untracked files. Set `MDEV_TEST_BASE` to
choose another branch or commit.

```bash
mdev test static
mdev test unit --filter testName
mdev test integration --filter testName
```

The static helper runs Magento's PHP `LiveCodeTest` class with a temporary
changed-file list. Unit and integration helpers select changed test files and
conventional tests related to changed source files; pass a test path to run it
explicitly. They fail clearly if no related test target is found rather than
silently running the full suite. Xdebug is not forced by the wrapper.

## Relinking Adobe Commerce and B2B

Run the edition linker to link `src/ee/` and `src/b2b/` into the CE checkout:

```bash
mdev relinc
```

## Shared proxy

This project includes the Traefik gateway declaration and can run independently.
Run:

```bash
mdev up
```

If a compatible gateway is already running on `traefik-ingress` (for example from
`commerce-core-saas-service`), `mdev up` reuses it instead of starting another
gateway. Otherwise it starts this project's standalone gateway. The application
is available at:

```text
https://ccsaas.test/<compose-project-name>/
```