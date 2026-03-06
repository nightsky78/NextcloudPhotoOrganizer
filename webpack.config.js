/**
 * SPDX-FileCopyrightText: 2026 Johannes
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

const webpackConfig = require('@nextcloud/webpack-vue-config')
const path = require('path')

webpackConfig.entry = {
	main: path.join(__dirname, 'src', 'main.js'),
}
webpackConfig.output.path = path.resolve(__dirname, 'js')

module.exports = webpackConfig
