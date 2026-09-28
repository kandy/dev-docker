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