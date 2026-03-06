<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

use OCP\Util;

// Load the compiled Vue app bundle
Util::addScript('photodedup', 'photodedup-main');
Util::addStyle('photodedup', 'style');
?>

<div id="photodedup-content"></div>
