#!/usr/bin/env bash
[[ ! ${MDEV_DIR} ]] && >&2 echo -e "\033[31mThis script is not intended to be run directly!\033[0m" && exit 1

function m_version {
  echo "$@" | awk -F. '{ printf("%d%03d%03d%03d\n", $1,$2,$3,$4); }';
}


function m_fatal {
   >&2 printf "\033[31mERROR\033[0m: $@\n";
  exit -1
}

## verify docker is running
function m_assumeDocker {
  ## verify docker-compose is installed
  if ! which docker-compose >/dev/null; then
    m_fatal "docker-compose could not be found; please install and try again."
  fi

  ## verify docker-compose meets version constraint
  DOCKER_COMPOSE_REQUIRE="1.25.0"
  DOCKER_COMPOSE_REQUIRE_V2="2.2.3"
  DOCKER_COMPOSE_VERSION="$(docker-compose --version | grep -oE '[0-9\.]+' | head -n1)"
  if ! test $(m_version ${DOCKER_COMPOSE_VERSION}) -ge $(m_version ${DOCKER_COMPOSE_REQUIRE}); then
    m_fatal "docker-compose version should be ${DOCKER_COMPOSE_REQUIRE} or higher (${DOCKER_COMPOSE_VERSION} installed)"
  fi

  if test $(m_version ${DOCKER_COMPOSE_VERSION}) -ge $(m_version "2.0.0") \
    && ! test $(m_version ${DOCKER_COMPOSE_VERSION}) -ge $(m_version ${DOCKER_COMPOSE_REQUIRE_V2})
  then
    m_fatal "docker-compose version should be ${DOCKER_COMPOSE_REQUIRE_V2} or higher (${DOCKER_COMPOSE_VERSION} installed)"
  fi

  if ! docker system info >/dev/null 2>&1; then
    m_fatal "Docker does not appear to be running. Please start Docker."
  fi
}

## return first container name based on service name
function m_containerNameByService {
  APP_CONTAINER=$(docker container inspect $(docker compose ps -q $1 ) --format '{{ .Name }}')
  APP_CONTAINER="${APP_CONTAINER:1}"
  echo $APP_CONTAINER
}

## return project name (aka root folder name)
function m_projectName {
  PROJECT_NAME=$(docker compose ls | grep "${MDEV_DIR}/docker" | awk  '{print $1}')
  echo $PROJECT_NAME
}