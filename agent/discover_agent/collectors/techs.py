"""Which CMS and frameworks a site runs, read from its files (nothing is executed).

Every file read here belongs to the site owner: read it with util.read_regular_file
and keep only a known product name and a version-shaped string.
"""

import json
import os
import re

from discover_agent import util

PARENT_LEVELS = 2
LOCK_LIMIT = 8 * 1024 * 1024
MAX_TECHNOLOGIES = 10

_VERSION = re.compile(r"^[0-9A-Za-z.+\-]{1,32}$")
_PACKAGE_NAME = re.compile(r"^[@A-Za-z0-9._/\-]{1,214}$")

# CMS that carry their version in a PHP file: (path under the site, product, version pattern).
VERSION_FILES = (
    ("wp-includes/version.php", "WordPress", r"\$wp_version\s*=\s*'([^']+)'"),
    ("version.php", "Nextcloud", r"\$OC_VersionString\s*=\s*'([^']+)'"),
    ("core/lib/Drupal.php", "Drupal", r"const\s+VERSION\s*=\s*'([^']+)'"),
    ("app/AppKernel.php", "PrestaShop", r"const\s+VERSION\s*=\s*'([^']+)'"),
)
JOOMLA_FILE = "libraries/src/Version.php"

# Composer packages, in display order.
COMPOSER_PACKAGES = {
    "laravel/framework": "Laravel",
    "filament/filament": "Filament",
    "livewire/livewire": "Livewire",
    "symfony/framework-bundle": "Symfony",
    "drupal/core": "Drupal",
    "magento/product-community-edition": "Magento",
    "cakephp/cakephp": "CakePHP",
    "codeigniter4/framework": "CodeIgniter",
    "yiisoft/yii2": "Yii",
    "slim/slim": "Slim",
}

# npm packages (runtime dependencies only), in display order.
NPM_PACKAGES = {
    "next": "Next.js",
    "nuxt": "Nuxt",
    "@sveltejs/kit": "SvelteKit",
    "astro": "Astro",
    "@remix-run/node": "Remix",
    "@nestjs/core": "NestJS",
    "@adonisjs/core": "AdonisJS",
    "@strapi/strapi": "Strapi",
    "express": "Express",
    "fastify": "Fastify",
    "koa": "Koa",
    "hono": "Hono",
}

_MARKERS = ("composer.json", "package.json", "wp-includes/version.php", "version.php", JOOMLA_FILE)


def clean_version(value):
    if not isinstance(value, str):
        return None
    value = value.strip()
    value = value[1:] if value[:1] in ("v", "V") and value[1:2].isdigit() else value
    return value if _VERSION.match(value) else None


def _json(text):
    try:
        data = json.loads(text) if text else None
    except ValueError:
        return None
    return data if isinstance(data, dict) else None


def from_version_files(directory, reader):
    found = []
    for path, name, pattern in VERSION_FILES:
        text = reader(os.path.join(directory, path))
        match = re.search(pattern, text) if text else None
        if match:
            found.append({"name": name, "version": clean_version(match.group(1))})
    text = reader(os.path.join(directory, JOOMLA_FILE))
    if text and "Joomla" in text:
        parts = [re.search(rf"const\s+{part}_VERSION\s*=\s*(\d+)", text) for part in ("MAJOR", "MINOR", "PATCH")]
        version = ".".join(m.group(1) for m in parts) if all(parts) else None
        found.append({"name": "Joomla", "version": version})
    return found


def from_composer(directory, reader):
    lock = _json(reader(os.path.join(directory, "composer.lock"), LOCK_LIMIT))
    versions = {}
    if lock and isinstance(lock.get("packages"), list):
        for package in lock["packages"]:
            if isinstance(package, dict) and package.get("name") in COMPOSER_PACKAGES:
                versions[package["name"]] = clean_version(package.get("version"))
    else:
        # Without a lock file only the names are known: constraints are not versions.
        manifest = _json(reader(os.path.join(directory, "composer.json")))
        required = manifest.get("require") if manifest else None
        if isinstance(required, dict):
            versions = {name: None for name in required if name in COMPOSER_PACKAGES}
    return [{"name": COMPOSER_PACKAGES[name], "version": versions[name]}
            for name in COMPOSER_PACKAGES if name in versions]


def from_npm(directory, reader):
    manifest = _json(reader(os.path.join(directory, "package.json")))
    dependencies = manifest.get("dependencies") if manifest else None
    if not isinstance(dependencies, dict):
        return []
    found = []
    for package, name in NPM_PACKAGES.items():
        if package not in dependencies:
            continue
        # The installed copy gives the exact version; package.json only a range.
        installed = _json(reader(os.path.join(directory, "node_modules", package, "package.json")))
        found.append({"name": name, "version": clean_version(installed.get("version")) if installed else None})
    return found


def detect_technologies(app_dir, reader=util.read_regular_file):
    """Look in app_dir, then up to two parents (Laravel serves public/, the project is above)."""
    if not app_dir:
        return []
    directory = os.path.abspath(app_dir)
    for _ in range(PARENT_LEVELS + 1):
        if any(reader(os.path.join(directory, marker)) is not None for marker in _MARKERS):
            found, seen = [], set()
            for tech in from_version_files(directory, reader) + from_composer(directory, reader) + from_npm(directory, reader):
                if tech["name"] not in seen:
                    seen.add(tech["name"])
                    found.append(tech)
            return found[:MAX_TECHNOLOGIES]
        parent = os.path.dirname(directory)
        if parent == directory:
            break
        directory = parent
    return []


def read_package(app_dir, reader=util.read_regular_file):
    """Name and version an application declares in its package.json."""
    manifest = _json(reader(os.path.join(app_dir, "package.json"))) if app_dir else None
    if not manifest:
        return None, None
    name = manifest.get("name")
    return (name if isinstance(name, str) and _PACKAGE_NAME.match(name) else None), clean_version(manifest.get("version"))
