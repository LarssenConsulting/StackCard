"""Section `php`: effective ini values per version and SAPI, FPM pools, per-site PHP mode and AllowOverride.

Root runs `phpX.Y -i` and `php-fpmX.Y -i`, whose output contains the environment: only the
directive tables (local value), the build facts and the version lines of the modules named by
`-m` are kept, never the Environment and PHP Variables parts, and every value goes through
`redact`. A credential under an unexpected directive name relies on `redact` alone: reporting
every directive was chosen so that a new CVE does not need a new agent. Only absolute system paths with a regex-validated version are executed, from `/` with a
cleaned environment (the CLI SAPI loads a php.ini found in the cwd and honours PHP_INI_SCAN_DIR).
"""

import dataclasses
import os
import re
from fnmatch import fnmatchcase

from discover_agent import util
from discover_agent.collectors import apache, sites
from discover_agent.redact import MASK, _is_secret_name, redact
from discover_agent.sections.core import Collected, describe, read_source
from discover_agent.sections.defaults import PHP_INI
from discover_agent.sections.restart_needed import ProcFS, master_pids
from discover_agent.sections.tools import run_tool

PHP_ROOT = "/etc/php"
APACHE_ROOT = "/etc/apache2"
SAPIS = ("fpm", "apache2", "cli")
BINARIES = {"fpm": "/usr/sbin/php-fpm{}", "cli": "/usr/bin/php{}"}
CLEAN_ENV = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LC_ALL": "C"}
PHP_I_TIMEOUT = 5  # one broken version must not eat the 30 s section budget
INCLUDE_DEPTH = 5

_VERSION = re.compile(r"\d+\.\d+\Z")
_POOL_DIRECTIVE = re.compile(r"(php_value|php_admin_value|php_flag|php_admin_flag)\[([^\]]+)\]\Z")
_DIRECTORY_OPEN = re.compile(r"<Directory\s+(.+?)\s*>\s*\Z", re.IGNORECASE)
_OPEN_TAG = re.compile(r"<\s*([A-Za-z]+)\s*(.*?)\s*>\s*\Z")
_GLOB_CHARS = re.compile(r"[*?\[]")
_KEY_PARTS = re.compile(r"[._\-\[\]]")
_POOL_FIELDS = ("listen", "listen.allowed_clients", "user", "group")  # reported as their own fields
_DIRECTIVE_NAME = re.compile(r"[A-Za-z_][\w.\-]*\Z")
_DIRECTIVES_HEADER = "Directive => Local Value => Master Value"
_END_OF_CONFIG = ("Environment", "PHP Variables")  # everything from here on is process state
_NOT_DIRECTIVES = ("extension", "zend_extension")  # repeatable: one value would be misleading
_MODULE_NAME = re.compile(r"[A-Za-z0-9][\w .+\-]*\Z")
_VERSION_LINE = re.compile(r"version|\AcURL Information\Z", re.IGNORECASE)


def _listdir(path):
    try:
        return sorted(os.listdir(path))
    except OSError:
        return None


def _sensitive_key(key):
    """Keys that name a credential: PW as a whole segment or suffix (pwd is not one), license, and redact's markers."""
    upper = key.upper()
    if _is_secret_name(key) or "LICENSE" in upper or upper.endswith("PW"):
        return True
    return "PW" in _KEY_PARTS.split(upper)


def _safe(key, value):
    """Mask credentials in a value: all of it for a sensitive key, else whatever redact finds."""
    if _sensitive_key(key):
        return MASK if value else value
    return redact(f"{key} = {value}").partition(" = ")[2]


def _unquote(value):
    value = value.strip()
    if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
        return value[1:-1]
    return value


def _values(found):
    """Every directive found, masked; the keys of PHP_INI are always there, None when absent."""
    values = dict.fromkeys(PHP_INI)
    values.update((key, _safe(key, value)) for key, value in found.items())
    return values


def _config_lines(text):
    """(line, inside a directive table) for the lines before the Environment part of `php -i`."""
    in_table = False
    for line in text.splitlines():
        if line in _END_OF_CONFIG:
            return
        if line == _DIRECTIVES_HEADER:
            in_table = True
        elif not line.strip():
            in_table = False
        else:
            yield line, in_table


def parse_php_i(text):
    """Every ini directive of a `php -i` dump, with its local value; PHP_INI keys absent are None."""
    found = {}
    for line, in_table in _config_lines(text):
        parts = line.split(" => ")
        key = parts[0].strip()
        if in_table and len(parts) >= 2 and _DIRECTIVE_NAME.match(key):
            columns = parts[1:]  # local => master: a value may itself hold " => "
            found[key] = " => ".join(columns[:max(1, len(columns) // 2)]).strip()
    return _values(found)


def parse_build(text):
    """PHP version and build date as `php -i` states them; the System line (hostname) stays out."""
    build = {"php_version": None, "build_date": None}
    keys = {"PHP Version": "php_version", "Build Date": "build_date"}
    for line, in_table in _config_lines(text):
        key, sep, value = line.partition(" => ")
        if sep and not in_table and key in keys and build[keys[key]] is None:
            build[keys[key]] = value.strip()
    return build


def parse_extensions(modules_text, info_text):
    """Modules of `php -m`, each with the version lines of its block in `php -i`.

    PHP has no uniform extension version: `versions` holds what phpinfo states, which is
    also where the linked libraries appear (OpenSSL, libxml, cURL...); it may be empty.
    """
    names = {}
    for raw in modules_text.splitlines():
        name = raw.strip()
        if _MODULE_NAME.match(name):
            names.setdefault(name.lower(), name)
    versions = {key: {} for key in names}
    current = None
    for line, in_table in _config_lines(info_text):
        if line.strip().lower() in names:
            current = line.strip().lower()
            continue
        key, sep, value = line.partition(" => ")
        key = key.strip()
        if current and sep and not in_table and _VERSION_LINE.search(key):
            versions[current][key] = _safe(key, value.strip())
    return [{"name": names[key], "versions": versions[key]} for key in sorted(names)]


def parse_ini(text, found):
    """Fold the `key = value` lines of one ini file into `found` (the last one wins).

    Per-path and per-host sections (`[PATH=...]`, `[HOST=...]`) are skipped: their
    values do not apply globally.
    """
    scoped = False
    for raw in text.splitlines():
        line = raw.strip()
        if not line or line[0] in ";#":
            continue
        if line[0] == "[":
            scoped = line[1:].upper().startswith(("PATH=", "HOST="))
            continue
        if scoped:
            continue
        key, sep, value = line.partition("=")
        key, value = key.strip(), value.strip()
        if not sep or not _DIRECTIVE_NAME.match(key) or key in _NOT_DIRECTIVES:
            continue
        if value and value[0] in "\"'":
            end = value.find(value[0], 1)
            value = value[1:end] if end > 0 else value[1:]
        else:
            value = value.split(";", 1)[0].strip()
        found[key] = value
    return found


def parse_pool_file(text, path):
    # Not sites.parse_fpm_pools: that one drops pools without `listen` and keeps no php_value lines.
    pools, current = [], None
    for raw in text.splitlines():
        line = raw.strip()
        if not line or line.startswith(";"):
            continue
        if line.startswith("[") and line.endswith("]"):
            name = line[1:-1]
            current = None if name == "global" else {"name": name, "file": path, "values": {}, "php_values": []}
            if current:
                pools.append(current)
            continue
        key, sep, value = line.partition("=")
        if not sep or current is None:
            continue
        key, value = key.strip(), value.strip()
        match = _POOL_DIRECTIVE.match(key)
        if match:
            current["php_values"].append(
                {"directive": match.group(1), "key": match.group(2), "value": _safe(match.group(2), value)}
            )
        else:
            current["values"][key] = value
    result = []
    for pool in pools:
        values = pool["values"]
        listen = _unquote(values["listen"]) if "listen" in values else None
        result.append({
            "name": pool["name"],
            "file": pool["file"],
            "listen": _safe("listen", listen) if listen is not None else None,
            "listen_allowed_clients": values.get("listen.allowed_clients"),
            "user": values.get("user"),
            "group": values.get("group"),
            "php_values": sorted(pool["php_values"], key=lambda v: (v["directive"], v["key"])),
            "settings": {
                key: _safe(key, _unquote(value)) for key, value in values.items()
                if key not in _POOL_FIELDS and not key.lower().startswith("env[")
            },
        })
    return result


def read_pools(version, reader, listdir):
    directory = f"{PHP_ROOT}/{version}/fpm/pool.d"
    pools = []
    for name in listdir(directory) or []:
        if name.endswith(".conf"):
            path = f"{directory}/{name}"
            pools += parse_pool_file(reader(path) or "", path)
    return sorted(pools, key=lambda p: (p["name"], p["file"]))


def read_ini_files(version, reader, listdir):
    """Values of the apache2 SAPI from php.ini then conf.d/*.ini; raises if php.ini is unreadable."""
    base = f"{PHP_ROOT}/{version}/apache2"
    paths = [f"{base}/php.ini"]
    paths += [f"{base}/conf.d/{n}" for n in sorted(listdir(f"{base}/conf.d") or []) if n.endswith(".ini")]
    found = {}
    for index, path in enumerate(paths):
        text = reader(path)
        if text is None:
            if index == 0:
                raise util.CollectorError(f"unreadable: {path}")
            continue
        parse_ini(text, found)
    return _values(found)


# --- AllowOverride -------------------------------------------------------


def _components(path):
    return [part for part in path.split("/") if part]


def effective_allow_override(document_root, directories):
    """AllowOverride of the most specific `<Directory>` covering `document_root`, or None.

    `directories` is an ordered list of {"path", "allow_override"} (earlier first). A longer
    path wins and, at equal depth, the later entry; `*`/`?` match within one component.
    An entry flagged "incomplete" (unreadable include, unevaluable path) makes the answer
    None, and so does an "uncertain" entry (inside a block we cannot evaluate) that would
    win or tie.
    """
    if not document_root:
        return None
    root = _components(document_root)
    best, best_depth, doubt = None, -1, -1
    for entry in directories:
        if entry.get("incomplete"):
            return None
        pattern = _components(entry["path"])
        if len(pattern) > len(root):
            continue
        if not all(fnmatchcase(part, pat) for part, pat in zip(root, pattern)):
            continue
        if entry.get("uncertain"):
            doubt = max(doubt, len(pattern))
        elif len(pattern) >= best_depth:
            best, best_depth = entry["allow_override"], len(pattern)
    return None if doubt >= best_depth and doubt >= 0 else best


def _module_name(arg):
    name = arg.lstrip("!")
    if name.endswith(".c") and name.startswith("mod_"):
        return name[4:-2] + "_module"
    return name


class ApacheConfig:
    """`<Directory>`/AllowOverride facts of the Apache configuration, with Include followed.

    Every fact carries the `<VirtualHost>` it belongs to, as (file, start line), or None
    for the global config. Whatever cannot be evaluated is recorded as doubt, so that
    `lookup` answers None rather than a wrong value.
    """

    def __init__(self, reader, listdir, modules):
        self.reader, self.listdir, self.modules = reader, listdir, modules
        self.entries = []
        self.scopes = set()
        self.global_files = set()

    # -- walking --

    def load(self, extra_files=()):
        self.walk_file(f"{APACHE_ROOT}/apache2.conf", [], 0)
        for sub in ("conf-enabled", "mods-enabled", "sites-enabled"):
            for name in self.listdir(f"{APACHE_ROOT}/{sub}") or []:
                path = f"{APACHE_ROOT}/{sub}/{name}"
                if name.endswith(".conf") and path not in self.global_files:
                    self.walk_file(path, [], 0)
        for path in extra_files:
            if path and path not in self.global_files:
                self.walk_file(path, [], 0)

    def walk_file(self, path, stack, depth):
        text = self.reader(path)
        if text is None:
            self.doubt(stack)
            return
        if not stack:
            self.global_files.add(path)
        base = len(stack)
        for number, line in apache._logical_lines(text):
            if line and not line.startswith("#"):
                self.statement(path, number, line, stack, depth)
        del stack[base:]

    def statement(self, path, number, line, stack, depth):
        if line.startswith("</"):
            if stack:
                stack.pop()
            return
        if line.startswith("<"):
            self.open_tag(path, number, line, stack)
            return
        parts = line.split(None, 1)  # directives may be separated by tabs
        name = parts[0].lower()
        args = parts[1].strip() if len(parts) > 1 else ""
        if name == "allowoverride":
            self.allow_override(" ".join(args.split()), stack)
        elif name in ("include", "includeoptional"):
            self.include(_unquote(args), name == "includeoptional", stack, depth)

    def open_tag(self, path, number, line, stack):
        match = _OPEN_TAG.match(line)
        tag = match.group(1).lower() if match else ""
        arg = match.group(2) if match else ""
        if tag == "virtualhost":
            key = (path, number)
            self.scopes.add(key)
            stack.append(("vhost", key))
        elif tag == "directory":
            directory = _DIRECTORY_OPEN.match(line)
            target = _unquote(directory.group(1)) if directory else ""
            if not target or target.startswith("~") or "${" in target:
                stack.append(("regex",))  # cannot tell which directories it covers
            else:
                stack.append(("dir", target.rstrip("/") or "/"))
        elif tag == "directorymatch":
            stack.append(("regex",))
        elif tag == "ifmodule":
            if self.modules is None:
                stack.append(("if", "unknown"))
            else:
                loaded = _module_name(arg) in self.modules
                stack.append(("if", "on" if loaded != arg.startswith("!") else "off"))
        elif tag == "macro":
            stack.append(("if", "off"))
        elif tag.startswith("if") or tag == "else":
            stack.append(("if", "unknown"))
        else:
            stack.append(("other",))  # Location, Files, Proxy...: AllowOverride is not valid there

    def context(self, stack):
        """(vhost scope, nearest directory path, state) for a statement at the top of `stack`."""
        scope, directory, state, blocked = None, None, "on", False
        for item in reversed(stack):
            kind = item[0]
            if kind == "vhost" and scope is None:
                scope = item[1]
            elif kind == "dir" and directory is None and not blocked:
                directory = item[1]
            elif kind in ("other", "regex") and directory is None:
                blocked = True
                if kind == "regex":
                    state = "regex"
            elif kind == "if":
                if item[1] == "off":
                    return scope, directory, "off"
                if item[1] == "unknown" and state == "on":
                    state = "unknown"
        return scope, directory, state

    def allow_override(self, value, stack):
        scope, directory, state = self.context(stack)
        if state == "off":
            return
        if state == "regex":
            self.entries.append({"path": "/", "allow_override": None, "scope": scope, "incomplete": True})
            return
        if directory is None:
            return
        entry = {"path": directory, "allow_override": value, "scope": scope}
        if state == "unknown":
            entry["uncertain"] = True
        self.entries.append(entry)

    def doubt(self, stack):
        scope, _, state = self.context(stack)
        if state != "off":
            self.entries.append({"path": "/", "allow_override": None, "scope": scope, "incomplete": True})

    # -- Include --

    def include(self, pattern, optional, stack, depth):
        if self.context(stack)[2] == "off":
            return
        if not pattern or "${" in pattern or depth >= INCLUDE_DEPTH:
            self.doubt(stack)
            return
        if not pattern.startswith("/"):
            pattern = f"{APACHE_ROOT}/{pattern}"
        matches = self.expand(pattern)
        if not matches and not optional:
            self.doubt(stack)
        for match in matches:
            self.include_path(match, stack, depth, optional)

    def include_path(self, path, stack, depth, optional):
        names = self.listdir(path)
        if names is None:
            if optional and self.reader(path) is None:
                return
            self.walk_file(path, stack, depth + 1)
            return
        if depth + 1 >= INCLUDE_DEPTH:
            self.doubt(stack)
            return
        for name in names:
            self.include_path(f"{path}/{name}", stack, depth + 1, True)

    def expand(self, pattern):
        results = [""]
        for part in pattern.split("/")[1:]:
            nxt = []
            for base in results:
                if not _GLOB_CHARS.search(part):
                    nxt.append(f"{base}/{part}")
                    continue
                for name in self.listdir(base or "/") or []:
                    if fnmatchcase(name, part):
                        nxt.append(f"{base}/{name}")
            results = nxt
        return [p for p in results if self.listdir(p) is not None or self.reader(p) is not None]

    # -- answer --

    def applicable(self, scope):
        entries = [e for e in self.entries if e["scope"] in (None, scope)]
        return sorted(entries, key=lambda e: e["scope"] is not None)  # stable: global first

    def lookup(self, vhost):
        """AllowOverride of a vhost's DocumentRoot, or None when it cannot be told."""
        if vhost.get("partial") or not vhost.get("document_root"):
            return None
        config_file, line = vhost.get("config_file"), vhost.get("line")
        if line is not None:
            scopes = [(config_file, line)]
        else:
            # Without the block's line, sibling vhosts of the file must agree.
            scopes = sorted(s for s in self.scopes if s[0] == config_file) or [None]
        answers = {effective_allow_override(vhost["document_root"], self.applicable(s)) for s in scopes}
        return answers.pop() if len(answers) == 1 else None


# --- Collection ----------------------------------------------------------


def _php_i(runner, version, sapi, errors):
    binary = BINARIES[sapi].format(version)

    def php_run(flag):
        return run_tool(runner, [binary, flag], timeout=PHP_I_TIMEOUT, cwd="/", env=dict(CLEAN_ENV))

    text = php_run("-i")
    values = parse_php_i(text)
    if values["expose_php"] is None:
        raise util.CollectorError("unexpected php -i output: no expose_php")
    extensions = read_source(errors, f"{version}.{sapi}.extensions", lambda: parse_extensions(php_run("-m"), text))
    return {"values": values, **parse_build(text), "extensions": extensions}


def _apache_modules(runner, errors):
    """(loaded module names or None if unreadable, whether Apache is installed)."""
    text = read_source(errors, "apache2ctl", lambda: run_tool(runner, ["apache2ctl", "-M"]))
    if errors.get("apache2ctl") == "not_installed":
        del errors["apache2ctl"]  # no Apache is not a failure: the apache2 SAPI is just inactive
        return None, False
    return (None if text is None else apache.parse_ctl_m(text)), True


def _mod_php_version(reader, apache_dirs):
    """The version Apache loads as mod_php: its mods-enabled .load file, else the only apache2 dir."""
    loaded = [v for v in apache_dirs if reader(f"{APACHE_ROOT}/mods-enabled/php{v}.load") is not None]
    if len(loaded) == 1:
        return loaded[0]
    if not loaded and len(apache_dirs) == 1:
        return apache_dirs[0]
    return None


def _apache2_active(version, modules, has_apache, apache_dirs, mod_version):
    if not has_apache:
        return False
    if modules is None:
        return None  # apache2ctl -M failed: state unknown, source_errors says why
    if not any(m.startswith("php") for m in modules) or version not in apache_dirs:
        return False
    return None if mod_version is None else version == mod_version


def _collect_version(version, runner, reader, listdir, proc, apache, errors):
    modules, has_apache, apache_dirs, mod_version = apache
    sapis = {}
    for sapi in SAPIS:
        has_dir = listdir(f"{PHP_ROOT}/{version}/{sapi}") is not None
        key = f"{version}.{sapi}"
        entry = {"active": False, "source": "ini" if sapi == "apache2" else "php -i", "values": None,
                 "php_version": None, "build_date": None, "extensions": None}
        if sapi == "apache2":
            entry["active"] = _apache2_active(version, modules, has_apache, apache_dirs, mod_version)
            if has_dir:
                entry["values"] = read_source(errors, key, lambda: read_ini_files(version, reader, listdir))
        elif has_dir or sapi == "cli":
            running = sapi == "cli" or any(
                m["comm"] == f"php-fpm{version}" for m in master_pids(f"php-fpm{version}", proc))
            try:
                entry.update(_php_i(runner, version, sapi, errors))
                entry["active"] = running
            except util.NotInstalled:
                if has_dir:
                    errors[key] = "not_installed"
            except Exception as exc:  # the binary exists but its output is unusable
                entry["active"] = running
                errors[key] = describe(exc)
        else:
            entry["active"] = any(m["comm"] == f"php-fpm{version}" for m in master_pids(f"php-fpm{version}", proc))
        sapis[sapi] = entry
    return sapis


def _site_entry(site, vhost, pools_by_listen, config, fallback_version):
    mode = {"php-fpm": "fpm", "php-mod": "mod_php"}.get(site["type"])
    pool = None
    if mode == "fpm" and vhost.get("fpm_socket"):
        pool = pools_by_listen.get(sites.normalize_socket(vhost["fpm_socket"]))
    version = site["php_version"] or (fallback_version if mode == "mod_php" else None)
    return {
        "server_name": site["server_name"],
        "port": site["port"],
        "document_root": site["document_root"],
        "type": site["type"],
        "php_version": version,
        "mode": mode,
        "pool": pool,
        "allow_override": config.lookup(vhost),
    }


def collect(
    runner=util.run,
    reader=util.read_regular_file,
    listdir=_listdir,
    proc=None,
    load_vhosts=None,
    build_context=None,
    classify=sites.classify,
):
    names = listdir(PHP_ROOT)
    if names is None:
        raise util.NotInstalled(PHP_ROOT)
    proc = proc or ProcFS()
    errors = {}
    versions = sorted((n for n in names if _VERSION.match(n)), key=lambda v: tuple(map(int, v.split("."))))
    modules, has_apache = _apache_modules(runner, errors)
    apache_dirs = [v for v in versions if listdir(f"{PHP_ROOT}/{v}/apache2") is not None]
    mod_version = _mod_php_version(reader, apache_dirs)
    apache_state = (modules, has_apache, apache_dirs, mod_version)

    result, pools_by_listen = [], {}
    for version in versions:
        pools = read_pools(version, reader, listdir)
        for pool in pools:
            if pool["listen"]:
                pools_by_listen[sites.normalize_socket(pool["listen"].replace("$pool", pool["name"]))] = pool["name"]
        result.append({
            "version": version,
            "sapis": _collect_version(version, runner, reader, listdir, proc, apache_state, errors),
            "pools": pools,
        })

    site_list = []
    if has_apache:
        site_list = read_source(errors, "sites", lambda: _collect_sites(
            runner, reader, listdir, load_vhosts, build_context, classify, pools_by_listen, mod_version, modules))
    return Collected({"versions": result, "sites": site_list}, errors)


def _collect_sites(runner, reader, listdir, load_vhosts, build_context, classify, pools_by_listen, mod_version, modules):
    load_vhosts = load_vhosts or (lambda: apache.load_vhosts(runner=runner, reader=reader))
    # Certificates are not reported here: no `openssl` per vhost in a 30 s section.
    build_context = build_context or (
        lambda: dataclasses.replace(sites.build_context(runner=runner), cert_expiry=lambda path: None)
    )
    vhosts = load_vhosts()
    context = build_context()
    config = ApacheConfig(reader, listdir, modules)
    config.load(extra_files=[v.get("config_file") for v in vhosts])
    entries = [_site_entry(classify(v, context), v, pools_by_listen, config, mod_version) for v in vhosts]
    return sorted(entries, key=lambda e: (e["server_name"] or "", e["port"] or 0))
