"""Inventory sections (agent 0.5): one module per section, run by `core.run_all`.

A section module exposes `collect(...) -> Collected` and is added to REGISTRY here.
"""

from discover_agent.sections.core import (  # noqa: F401
    REGISTRY,
    SECTION_MAX,
    SECTION_NAMES,
    TIMEOUT,
    TIMEOUT_APT,
    TIMEOUT_WEEKLY,
    TOTAL_MAX,
    WEEKLY,
    Collected,
    canonical_sha256,
    describe,
    due_weekly,
    enforce_caps,
    read_source,
    record_attempt,
    record_weekly,
    run_all,
    timeout_for,
)

from discover_agent.sections import (  # noqa: E402
    apache,
    apt_sources,
    apt_updates,
    code_patterns,
    docker_images,
    integrity,
    kernel,
    kernel_surface,
    network,
    node_apps,
    packages,
    persistence,
    php,
    restart_needed,
    site_dependencies,
)

REGISTRY.update(
    packages=packages.collect,
    apt_sources=apt_sources.collect,
    apt_updates=apt_updates.collect,
    kernel=kernel.collect,
    restart_needed=restart_needed.collect,
    kernel_surface=kernel_surface.collect,
    network=network.collect,
    php=php.collect,
    apache=apache.collect,
    site_dependencies=site_dependencies.collect,
    node_apps=node_apps.collect,
    docker=docker_images.collect,
    persistence=persistence.collect,
    integrity=integrity.collect,
    code_patterns=code_patterns.collect,
)
