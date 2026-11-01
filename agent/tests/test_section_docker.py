import json
import unittest

from discover_agent import util
from discover_agent.sections import docker_images as section

IMAGES = [
    {"Id": "sha256:aaa", "RepoTags": ["nginx:1.27", "nginx:latest"],
     "RepoDigests": ["nginx@sha256:d1"], "Created": 1700000000, "Labels": {"secret": "x"}},
    {"Id": "sha256:bbb", "RepoTags": ["<none>:<none>"], "RepoDigests": ["<none>@<none>"], "Created": 1700000100},
    {"Id": "sha256:ccc", "RepoTags": None, "RepoDigests": None, "Created": 1700000200},
    {"Id": "sha256:ddd", "RepoTags": ["localhost:5000/team/app:v2"],
     "RepoDigests": ["localhost:5000/team/app@sha256:d4"], "Created": 1700000300},
]
CONTAINERS = [{
    "Id": "f" * 64, "Names": ["/web"], "Image": "nginx:1.27", "ImageID": "sha256:aaa",
    "State": "running", "Status": "Up 3 hours (healthy)",
    "Labels": {"com.docker.compose.project": "p", "password": "x"},
    "Ports": [{"IP": "0.0.0.0", "PrivatePort": 80, "PublicPort": 8080, "Type": "tcp"},
              {"PrivatePort": 443, "Type": "tcp"}],
    "Env": ["DB_PASSWORD=hunter2"],
}]


def make_api(seen):
    def api(path, **kw):
        seen.append(path)
        return {"/version": {"Version": "27.3.1"}, "/images/json": IMAGES,
                "/containers/json?all=1": CONTAINERS}[path]
    return api


def collect():
    seen = []
    return seen, section.collect(api=make_api(seen), has_docker=lambda: True)


class DockerSectionTest(unittest.TestCase):
    def test_docker_only_get(self):
        seen, _ = collect()
        self.assertEqual(sorted(seen), ["/containers/json?all=1", "/images/json", "/version"])

    def test_docker_keeps_state_not_status(self):
        _, result = collect()
        container = result.data["containers"][0]
        self.assertEqual(container["state"], "running")
        self.assertNotIn("Up 3 hours", json.dumps(result.data))
        self.assertEqual(container["image"], "nginx:1.27")
        self.assertEqual(container["image_digest"], "sha256:d1")
        self.assertEqual(container["ports"], [
            {"address": "0.0.0.0", "port": 8080, "container_port": 80, "protocol": "tcp"}])

    def test_docker_no_env_no_labels(self):
        _, result = collect()
        text = json.dumps(result.data)
        for forbidden in ("hunter2", "DB_PASSWORD", "password", "secret", "compose", "Labels"):
            self.assertNotIn(forbidden, text)

    def test_images(self):
        _, result = collect()
        self.assertEqual(result.data["version"], "27.3.1")
        images = result.data["images"]
        self.assertIn({"repository": "nginx", "tag": "1.27", "digest": "sha256:d1",
                       "created": "2023-11-14T22:13:20Z"}, images)
        self.assertIn({"repository": "nginx", "tag": "latest", "digest": "sha256:d1",
                       "created": "2023-11-14T22:13:20Z"}, images)
        self.assertIn({"repository": "localhost:5000/team/app", "tag": "v2", "digest": "sha256:d4",
                       "created": "2023-11-14T22:18:20Z"}, images)
        dangling = [i for i in images if i["repository"] is None]
        self.assertEqual(len(dangling), 2)
        for image in dangling:
            self.assertIsNone(image["tag"])
            self.assertIsNone(image["digest"])

    def test_no_docker_is_not_installed(self):
        with self.assertRaises(util.NotInstalled):
            section.collect(api=make_api([]), has_docker=lambda: False)

    def test_failing_call_is_a_source_error(self):
        def api(path, **kw):
            if path == "/images/json":
                raise util.CollectorError("boom")
            return {"/version": {"Version": "27"}, "/containers/json?all=1": []}[path]
        result = section.collect(api=api, has_docker=lambda: True)
        self.assertIsNone(result.data["images"])
        self.assertIn("/images/json", result.source_errors)
        self.assertEqual(result.data["containers"], [])


class StableOrderTest(unittest.TestCase):
    def test_ports_are_sorted(self):
        containers = [dict(CONTAINERS[0], Ports=[
            {"IP": "0.0.0.0", "PrivatePort": 443, "PublicPort": 8443, "Type": "tcp"},
            {"IP": "0.0.0.0", "PrivatePort": 80, "PublicPort": 8080, "Type": "tcp"}])]
        def api(path, **kw):
            return {"/version": {"Version": "1"}, "/images/json": [], "/containers/json?all=1": containers}[path]
        result = section.collect(api=api, has_docker=lambda: True)
        self.assertEqual([p["port"] for p in result.data["containers"][0]["ports"]], [8080, 8443])


if __name__ == "__main__":
    unittest.main()
