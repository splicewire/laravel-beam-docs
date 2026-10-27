<?php

namespace Splicewire\Beam\Docs\Theme;

use Splicewire\Beam\Ux\Schema\ThemeNamespaceSource;
use Splicewire\Beam\Ux\Schema\ThemeSchemas;

/** The documentation capability opts into the generic UX theme namespace extension. */
class DocsThemeNamespaceSource implements ThemeNamespaceSource
{
    public function schema(): array
    {
        return ThemeSchemas::docs();
    }
}
