import os
import socket
import tempfile
import threading
import unittest

from discover_agent import util
from discover_agent.collectors import docker

WEB_ID = "a1b2c3d4e5f6" + "0" * 52
DB_ID = "0f9e8d7c6b5a" + "1" * 52
OLD_ID = "abcdefabcdef" + "2" * 52

CONTAINERS = [
    {
        "Id": WEB_ID, "Names": ["/shop-web-1"], "Image": "nginx:1.27", "State": "running",
        "Ports": [
            {"IP": "127.0.0.1", "PrivatePort": 80, "PublicPort": 8080, "Type": "tcp"},
            {"PrivatePort": 443, "Type": "tcp"},
        ],
        "Labels": {
            "com.docker.compose.project": "shop",
            "com.docker.compose.service": "web",
            "com.docker.compose.project.working_dir": "/srv/shop",
            "org.opencontainers.image.version": "1.27.2",
            "traefik.http.middlewares.auth.basicauth.users": "admin:$apr1$secret",
        },
    },
    {
        "Id": DB_ID, "Names": ["/pg"], "Image": "postgres:16", "State": "running",
        "Ports": [
            {"IP": "0.0.0.0", "PrivatePort": 5432, "PublicPort": 5432, "Type": "tcp"},
            {"IP": "::", "PrivatePort": 5432, "PublicPort": 5432, "Type": "tcp"},
        ],
        "Labels": {},
    },
    {"Id": OLD_ID, "Names": ["/old-job"], "Image": "busybox", "State": "exited", "Ports": [], "Labels": None},
]

INSPECT = {
    WEB_ID: {
        "State": {"StartedAt": "2026-10-20T08:12:03.123456789Z"},
        "HostConfig": {"RestartPolicy": {"Name": "unless-stopped"}},
        "Config": {"Env": ["DB_PASSWORD=hunter2"]},
    },
    DB_ID: {"State": {"StartedAt": "2026-10-19T00:00:00Z"}, "HostConfig": {"RestartPolicy": {"Name": "always"}}},
    OLD_ID: {"State": {"StartedAt": "0001-01-01T00:00:00Z"}, "HostConfig": {"RestartPolicy": {"Name": "no"}}},
}


def api(path):
    if path == "/version":
        return {"Version": "27.3.1"}
    if path == "/containers/json?all=1":
        return CONTAINERS
    if path == "/containers/json":
        return [c for c in CONTAINERS if c["State"] == "running"]
    prefix, _, ident = path.rpartition("/containers/")
    if not prefix and ident.endswith("/json") and ident[:-5] in INSPECT:
        return INSPECT[ident[:-5]]
    raise util.CollectorError(f"API Docker {path} : HTTP 404")


def collect(**overrides):
    values = dict(api=api, has_docker=lambda: True)
    values.update(overrides)
    return docker.collect_docker(**values)


class CollectDockerTest(unittest.TestCase):
    def test_no_docker_means_no_section(self):
        self.assertIsNone(collect(has_docker=lambda: False))

    def test_every_container_running_or_not(self):
        result = collect()
        self.assertEqual(result["version"], "27.3.1")
        self.assertEqual([c["name"] for c in result["containers"]], ["shop-web-1", "pg", "old-job"])

    def test_one_container_with_its_compose_service_and_published_ports(self):
        self.assertEqual(collect()["containers"][0], {
            "id": "a1b2c3d4e5f6",
            "name": "shop-web-1",
            "image": "nginx:1.27",
            "image_version": "1.27.2",
            "state": "running",
            "ports": [{"address": "127.0.0.1", "port": 8080, "container_port": 80, "protocol": "tcp"}],
            "compose_project": "shop",
            "compose_service": "web",
            "compose_dir": "/srv/shop",
            "restart_policy": "unless-stopped",
            "started_at": "2026-10-20T08:12:03Z",
        })

    def test_environment_and_other_labels_never_leave_the_server(self):
        text = repr(collect())
        self.assertNotIn("hunter2", text)
        self.assertNotIn("apr1", text)

    def test_ipv4_and_ipv6_bindings_of_a_port(self):
        self.assertEqual([p["address"] for p in collect()["containers"][1]["ports"]], ["0.0.0.0", "::"])

    def test_a_container_never_started_has_no_start_date(self):
        old = collect()["containers"][2]
        self.assertIsNone(old["started_at"])
        self.assertEqual(old["restart_policy"], "no")
        self.assertIsNone(old["compose_project"])

    def test_a_container_removed_while_collecting_is_still_listed(self):
        def gone(path):
            if path == f"/containers/{OLD_ID}/json":
                raise util.CollectorError("API Docker : HTTP 404")
            return api(path)

        old = collect(api=gone)["containers"][2]
        self.assertEqual(old["name"], "old-job")
        self.assertIsNone(old["restart_policy"])

    def test_an_odd_id_is_never_put_in_a_request_path(self):
        odd = [dict(CONTAINERS[2], Id="../../version")]
        calls = []

        def spy(path):
            calls.append(path)
            return odd if path.startswith("/containers/json") else api(path)

        self.assertEqual(collect(api=spy)["containers"], [])
        self.assertNotIn("/containers/../../version/json", calls)


class PublishedPortsTest(unittest.TestCase):
    def test_ports_published_by_running_containers(self):
        self.assertEqual(docker.published_ports(api=api, has_docker=lambda: True), {8080, 5432})

    def test_no_docker_no_ports(self):
        self.assertEqual(docker.published_ports(api=api, has_docker=lambda: False), set())

    def test_an_unreachable_daemon_is_not_an_error_here(self):
        def down(path):
            raise util.CollectorError("API Docker : connexion refusée")

        self.assertEqual(docker.published_ports(api=down, has_docker=lambda: True), set())


class ApiGetTest(unittest.TestCase):
    """A real HTTP exchange over a Unix socket, as with dockerd."""

    def serve(self, response):
        directory = tempfile.mkdtemp()
        path = os.path.join(directory, "docker.sock")
        server = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        server.bind(path)
        server.listen(1)
        self.addCleanup(server.close)
        self.requests = []

        def handle():
            conn, _ = server.accept()
            with conn:
                self.requests.append(conn.recv(65536).decode())
                conn.sendall(response)

        threading.Thread(target=handle, daemon=True).start()
        return path

    def test_get_json(self):
        body = b'{"Version": "27.3.1"}'
        path = self.serve(b"HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: %d\r\n\r\n%s" % (len(body), body))
        self.assertEqual(docker.api_get("/version", socket_path=path), {"Version": "27.3.1"})
        self.assertTrue(self.requests[0].startswith("GET /version HTTP/1.1"))

    def test_http_error(self):
        path = self.serve(b"HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\n\r\n")
        with self.assertRaisesRegex(util.CollectorError, "500"):
            docker.api_get("/version", socket_path=path)

    def test_oversized_answer(self):
        body = b"[" + b"1," * 100 + b"1]"
        path = self.serve(b"HTTP/1.1 200 OK\r\nContent-Length: %d\r\n\r\n%s" % (len(body), body))
        with self.assertRaisesRegex(util.CollectorError, "trop"):
            docker.api_get("/containers/json", socket_path=path, limit=64)

    def test_no_daemon(self):
        with self.assertRaises(util.CollectorError):
            docker.api_get("/version", socket_path=os.path.join(tempfile.mkdtemp(), "none.sock"))


if __name__ == "__main__":
    unittest.main()
