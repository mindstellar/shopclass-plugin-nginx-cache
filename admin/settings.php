<?php
/*
 * This file is part of the nginx Cache plugin for Shopclass.
 * Copyright (c) 2021-2026 Mindstellar Community
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

// TODO(phase 3): settings page.
// Endpoint, Host, the three TTLs, and a Test purge button that runs
// Plugin::selfTest() -- the raised windows stay inert until it passes, and the
// result is the page's headline so a broken setup is not something you discover
// an hour later on a stale listing.
