#!/usr/bin/env python3
import json
import sys

path = sys.argv[1] if len(sys.argv) > 1 else "/tmp/clone-compose.json"
with open(path, "r", encoding="utf-8") as handle:
    compose = json.load(handle)

services = compose.get("services", {})
networks = compose.get("networks", {})

backend = networks.get("backend", {})
if backend.get("internal") is not True:
    raise SystemExit("backend network must remain internal")

shop_networks = set((services.get("shop", {}).get("networks") or {}).keys())
if shop_networks != {"default", "backend"}:
    raise SystemExit(f"shop must use only default + backend networks, got {sorted(shop_networks)}")

shop_env = services.get("shop", {}).get("environment") or {}
host_keys = {
    "database": "CLONE_DATABASE_HOST",
    "redis": "CLONE_REDIS_HOST",
    "opensearch": "CLONE_OPENSEARCH_HOST",
}

for service, env_key in host_keys.items():
    config = services.get(service, {})
    service_networks = config.get("networks") or {}
    if set(service_networks.keys()) != {"backend"}:
        raise SystemExit(f"{service} must only use the internal backend network")

    if config.get("ports"):
        raise SystemExit(f"{service} must not publish host ports")

    aliases = service_networks.get("backend", {}).get("aliases") or []
    expected_host = shop_env.get(env_key)
    if not expected_host:
        raise SystemExit(f"shop is missing {env_key}")
    if expected_host not in aliases:
        raise SystemExit(f"{service} backend alias does not match {env_key}: {expected_host!r}")
    if expected_host == service:
        raise SystemExit(f"{service} must use a clone-specific hostname, not the shared service name")

print("Compose network isolation tests: OK")
