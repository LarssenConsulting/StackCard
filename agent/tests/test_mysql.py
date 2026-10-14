import unittest

from discover_agent import util
from discover_agent.collectors import mysql

OUTPUTS = {
    "SELECT VERSION()": "10.11.8-MariaDB-0ubuntu0.24.04.1\n",
    "information_schema.SCHEMATA": "blog\t0\nshop_prod\t1048576\n",
    "FROM mysql.user": "blog\tlocalhost\nroot\tlocalhost\nshop\t%\nshop\tlocalhost\n",
    "SCHEMA_PRIVILEGES": (
        "'shop'@'localhost'\tshop\\_prod\tSELECT\n"
        "'shop'@'localhost'\tshop\\_prod\tINSERT\n"
        "'blog'@'localhost'\tblog\tSELECT\n"
    ),
    "USER_PRIVILEGES": (
        "'root'@'localhost'\tSELECT\n"
        "'root'@'localhost'\tINSERT\n"
        "'shop'@'localhost'\tUSAGE\n"
    ),
}


class FakeRunner:
    def __init__(self, outputs=OUTPUTS, error=None):
        self.outputs, self.error, self.calls = outputs, error, []

    def __call__(self, cmd, **kwargs):
        self.calls.append(cmd)
        if self.error:
            raise util.CommandError(self.error)
        sql = cmd[-1]
        for marker, out in self.outputs.items():
            if marker in sql:
                return out
        raise AssertionError(f"unexpected query: {sql}")


def collect(runner, cnf_exists=False):
    return mysql.collect_mysql(
        runner=runner, has_client=lambda: True, exists=lambda path: cnf_exists
    )


class CollectMysqlTest(unittest.TestCase):
    def test_returns_none_without_client(self):
        self.assertIsNone(mysql.collect_mysql(runner=FakeRunner(), has_client=lambda: False))

    def test_version_databases_and_users(self):
        result = collect(FakeRunner())
        self.assertEqual(result["version"], "10.11.8-MariaDB-0ubuntu0.24.04.1")
        self.assertEqual(
            result["databases"],
            [{"name": "blog", "size_bytes": 0}, {"name": "shop_prod", "size_bytes": 1048576}],
        )
        self.assertIn({"user": "shop", "host": "%"}, result["users"])
        self.assertEqual(len(result["users"]), 4)

    def test_grants_are_aggregated_per_user_and_database(self):
        grants = collect(FakeRunner())["grants"]
        self.assertIn(
            {"user": "shop", "host": "localhost", "database": "shop_prod",
             "privileges": ["INSERT", "SELECT"], "global": False},
            grants,
        )
        self.assertIn(
            {"user": "root", "host": "localhost", "database": None,
             "privileges": ["INSERT", "SELECT"], "global": True},
            grants,
        )
        # USAGE means "no privilege" and is not reported.
        self.assertFalse(any(g["user"] == "shop" and g["global"] for g in grants))

    def test_uses_socket_and_extra_defaults_file_when_present(self):
        runner = FakeRunner()
        collect(runner, cnf_exists=True)
        first = runner.calls[0]
        self.assertEqual(first[0], "mysql")
        self.assertEqual(first[1], "--defaults-extra-file=/etc/discover-agent/mysql.cnf")
        self.assertIn("--protocol=socket", first)

    def test_access_denied_gives_actionable_error(self):
        runner = FakeRunner(error="ERROR 1698 (28000): Access denied for user 'root'@'localhost'")
        with self.assertRaises(util.CollectorError) as ctx:
            collect(runner)
        self.assertIn("/etc/discover-agent/mysql.cnf", str(ctx.exception))
        self.assertIn("Access denied", str(ctx.exception))


if __name__ == "__main__":
    unittest.main()
