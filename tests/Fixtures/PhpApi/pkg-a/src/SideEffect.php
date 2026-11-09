<?php

namespace Fixture\ApiA;

// If the extractor ever EXECUTED package code, this would leave a sentinel and throw. It must only be parsed.
file_put_contents(getenv('PHP_API_SENTINEL') ?: sys_get_temp_dir().'/beam-docs-php-api-executed', 'executed');

throw new \RuntimeException('package code was executed');

/**
 * Declared after top-level code.
 *
 * @api
 */
class SideEffect {}
