"""Section `docker`: daemon version, images and containers, from the Engine API (GET only).

No environment variable and no label is kept. The container `state` is used,
never the human `Status` text ("Up 3 hours") which would change the hash every
hour. The collector module `collectors/docker.py` keeps the legacy snapshot key.
"""

from datetime import datetime, timezone

from discover_agent import util
from discover_agent.collectors import docker
from discover_agent.sections.core import Collected, read_source


def _split(reference):
    """'localhost:5000/team/app:v2' -> ('localhost:5000/team/app', 'v2'); '<none>' -> (None, None)."""
    if not isinstance(reference, str) or reference.startswith("<none>"):
        return None, None
    repository, sep, tag = reference.rpartition(":")
    if not sep or "/" in tag:
        return reference, None
    return repository, tag


def _digests(values):
    """{repository: digest} from 'repo@sha256:...'; '<none>@<none>' is ignored."""
    found = {}
    for value in values or []:
        repository, sep, digest = value.partition("@") if isinstance(value, str) else ("", "", "")
        if sep and digest.startswith("sha256:"):
            found.setdefault(repository, digest)
    return found


def _created(value):
    if isinstance(value, int) and not isinstance(value, bool):
        try:
            return util.iso_utc(datetime.fromtimestamp(value, timezone.utc))
        except (OverflowError, OSError, ValueError):
            return None
    return None


def _images(raw):
    images, digest_of_id = [], {}
    for image in raw:
        digests = _digests(image.get("RepoDigests"))
        if digests:
            digest_of_id[image.get("Id")] = next(iter(digests.values()))
        created = _created(image.get("Created"))
        pairs = [_split(tag) for tag in image.get("RepoTags") or []]
        pairs = [p for p in pairs if p[0]] or [(None, None)]
        for repository, tag in pairs:
            digest = digests.get(repository) or (next(iter(digests.values())) if digests and repository else None)
            images.append({"repository": repository, "tag": tag, "digest": digest, "created": created})
    key = lambda i: (i["repository"] or "", i["tag"] or "", i["created"] or "", i["digest"] or "")
    return sorted(images, key=key), digest_of_id


def _containers(raw, digest_of_id):
    containers = []
    for summary in raw:
        names = summary.get("Names") or []
        containers.append({
            "name": names[0].lstrip("/") if names else None,
            "image": summary.get("Image"),
            "image_id": summary.get("ImageID"),
            "image_digest": digest_of_id.get(summary.get("ImageID")),
            "state": summary.get("State"),
            "ports": sorted(docker._ports(summary.get("Ports")),
                            key=lambda p: (p["address"], p["port"], p["container_port"] or 0, p["protocol"])),
        })
    return sorted(containers, key=lambda c: (c["name"] or "", c["image"] or ""))


def collect(api=docker.api_get, has_docker=docker.has_socket):
    if not has_docker():
        raise util.NotInstalled("docker")
    errors = {}
    version = read_source(errors, "/version", lambda: api("/version").get("Version"))
    raw_images = read_source(errors, "/images/json", lambda: api("/images/json"))
    raw_containers = read_source(errors, "/containers/json?all=1", lambda: api("/containers/json?all=1"))
    images, digest_of_id = _images(raw_images) if isinstance(raw_images, list) else (None, {})
    containers = _containers(raw_containers, digest_of_id) if isinstance(raw_containers, list) else None
    return Collected({"version": version, "images": images, "containers": containers}, errors)
