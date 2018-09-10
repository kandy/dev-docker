Docker infrastructure for Magento development

# Usage
- Install docker using [official guidelines](https://docs.docker.com/install/)
- Install docker compose using the same guidelines
- Checkout this repository
- Execute `docker-compose up -d` cli command from repository root

# Helper tools usage
- `./bin/bash` proxies bash commands to app container
- `./bin/git` proxies git commands to app container
- `./bin/init-from-git` initialize development Magento instance in app container
- `./bin/install` install Magento on app container. Magento will be deployed on `mage.perf` hostname, so you need to have it in your host's `/etc/hosts` file. Admin panel will be located on `http://mage.perf/admin` URL. Login: `admin`, password: `123123q`
- `./bin/magento` proxies Magento cli commands to app container
- `./bin/sync` exposes Magento source code to host machine using samba. Use `diskutil unmountDisk force project` to unmount on Mac OS 