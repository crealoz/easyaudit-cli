<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\AnonymousApiExposure;
use PHPUnit\Framework\TestCase;

class AnonymousApiExposureTest extends TestCase
{
    private AnonymousApiExposure $processor;

    protected function setUp(): void
    {
        $this->processor = new AnonymousApiExposure();
    }

    public function testGetIdentifier(): void
    {
        $this->assertEquals('anonymousApiExposure', $this->processor->getIdentifier());
    }

    public function testGetFileType(): void
    {
        $this->assertEquals('xml', $this->processor->getFileType());
    }

    public function testGetName(): void
    {
        $this->assertNotEmpty($this->processor->getName());
    }

    public function testGetLongDescriptionMentionsAnonymous(): void
    {
        $this->assertStringContainsString('anonymous', strtolower($this->processor->getLongDescription()));
    }

    public function testProcessIgnoresEmptyBucket(): void
    {
        ob_start();
        $this->processor->process(['xml' => []]);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessFlagsBadFixture(): void
    {
        $file = __DIR__ . '/../../../../fixtures/AnonymousApiExposure/Bad/etc/webapi.xml';

        ob_start();
        $this->processor->process(['xml' => [$file]]);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertEquals(2, $this->processor->getFoundCount());
        $this->assertNotEmpty($report[0]['files']);
    }

    public function testProcessIgnoresGoodFixture(): void
    {
        $file = __DIR__ . '/../../../../fixtures/AnonymousApiExposure/Good/etc/webapi.xml';

        ob_start();
        $this->processor->process(['xml' => [$file]]);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
        $this->assertEmpty($report[0]['files']);
    }

    public function testCommentFurtherAwayThanWindowDoesNotCount(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_anon_' . uniqid();
        mkdir($tempDir);
        $file = $tempDir . '/webapi.xml';
        $xml = <<<'XML'
<?xml version="1.0"?>
<routes>
    <!-- A distant comment that is too far above to be a justification -->


    <!-- filler -->
    <!-- filler -->
    <!-- filler -->
    <route url="/V1/too/far" method="GET">
        <service class="Vendor\Module\Api\Interface" method="get"/>
        <resources>
            <resource ref="anonymous"/>
        </resources>
    </route>
</routes>
XML;
        file_put_contents($file, $xml);

        ob_start();
        $this->processor->process(['xml' => [$file]]);
        ob_end_clean();

        // Three filler comments sit within the 3-line window, so this counts as justified.
        // Redraw the scenario with blank lines between comment and route.
        $processor2 = new AnonymousApiExposure();
        $xml2 = <<<'XML'
<?xml version="1.0"?>
<routes>
    <!-- Justifying comment placed far above -->



    <route url="/V1/too/far" method="GET">
        <service class="Vendor\Module\Api\Interface" method="get"/>
        <resources>
            <resource ref="anonymous"/>
        </resources>
    </route>
</routes>
XML;
        file_put_contents($file, $xml2);
        ob_start();
        $processor2->process(['xml' => [$file]]);
        ob_end_clean();

        $this->assertEquals(1, $processor2->getFoundCount());

        unlink($file);
        rmdir($tempDir);
    }

    public function testIgnoresNonWebapiXml(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_anon_' . uniqid();
        mkdir($tempDir);
        $file = $tempDir . '/routes.xml';
        file_put_contents($file, '<?xml version="1.0"?><config/>');

        ob_start();
        $this->processor->process(['xml' => [$file]]);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());

        unlink($file);
        rmdir($tempDir);
    }

    public function testSkipsMalformedXml(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_anon_' . uniqid();
        mkdir($tempDir);
        $file = $tempDir . '/webapi.xml';
        file_put_contents($file, '<?xml version="1.0"?><routes><route unclosed');

        ob_start();
        $this->processor->process(['xml' => [$file]]);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());

        unlink($file);
        rmdir($tempDir);
    }
}
