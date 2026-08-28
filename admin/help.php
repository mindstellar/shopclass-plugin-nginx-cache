<?php
/*
 * This file is part of the nginx Cache plugin for Shopclass.
 * Copyright (c) 2021-2026 Mindstellar Community
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

// TODO(phase 3): help page.
// Prints what this install needs, filled in with its own values rather than a
// generic sample: the two-stage Dockerfile that builds ngx_cache_purge as a
// dynamic module, the load_module line, the purge location block keyed to match
// fastcgi_cache_key, and the internal address to point purge_endpoint at.
// A standalone install has to add all of it by hand; the bundled image ships it.
