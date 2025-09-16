ACT_IMAGE ?= efrecon/act:v0.2.80
WORKDIR   ?= /work
UID_GID    := $(shell id -u):$(shell id -g)
DOCKER_GID := $(shell stat -c '%g' /var/run/docker.sock)

PLATFORM  ?= -P ubuntu-latest=ghcr.io/catthehacker/ubuntu:act-24.04

# Secrets-File in ENV format
SECRETS   ?= --secret-file .secrets

ACT_ARGS ?= \
  --artifact-server-path /home/act/.cache/artifacts

define DOCKER_RUN
docker run --rm -it \
  -u $(UID_GID) \
  --group-add $(DOCKER_GID) \
  -e HOME=/home/act \
  -e XDG_CACHE_HOME=/home/act/.cache \
  -e ACT_CACHE_DIR=/home/act/.cache/actcache \
  -v /var/run/docker.sock:/var/run/docker.sock \
  -v $(PWD):$(WORKDIR) -w $(WORKDIR) \
  -v $$HOME/.cache:/home/act/.cache \
  $(ACT_IMAGE)
endef

ci:
	$(DOCKER_RUN) $(PLATFORM) $(SECRETS) $(ACT_ARGS)
