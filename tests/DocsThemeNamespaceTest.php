<?php

namespace Splicewire\Beam\Docs\Tests;

use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Splicewire\Beam\Ux\Schema\ThemeSchemas;
use Splicewire\Beam\Ux\Theme\ThemeResolver;

class DocsThemeNamespaceTest extends TestCase
{
    public function test_the_optional_docs_capability_registers_its_theme_before_schema_boot(): void
    {
        $theme = app(ThemeResolver::class)->defaults();
        $this->assertArrayHasKey('docs', $theme);
        $this->assertSame('#eef1ee', $theme['docs']['paper']);
        $this->assertSame('oklch(0.2 0.02 165)', $theme['docs']['darkPaper']);
        $this->assertSame(['$ref' => 'theme.docs'], ThemeSchemas::root()['properties']['docs']);
        $this->assertTrue((new FilesystemSchemaRegistry(ThemeSchemas::directory()))->has('theme.docs'));
    }
}
