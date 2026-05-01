<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\OrphanedCronGroup;
use PHPUnit\Framework\TestCase;

class OrphanedCronGroupTest extends TestCase
{
    private OrphanedCronGroup $processor;

    protected function setUp(): void
    {
        $this->processor = new OrphanedCronGroup();
    }

    public function testGetIdentifier(): void
    {
        $this->assertEquals('orphanedCronGroup', $this->processor->getIdentifier());
    }

    public function testGetFileType(): void
    {
        $this->assertEquals('xml', $this->processor->getFileType());
    }

    public function testGetName(): void
    {
        $this->assertNotEmpty($this->processor->getName());
    }

    public function testGetLongDescriptionMentionsCronGroups(): void
    {
        $this->assertStringContainsString('cron_groups.xml', $this->processor->getLongDescription());
    }

    public function testProcessIgnoresEmptyBucket(): void
    {
        ob_start();
        $this->processor->process(['xml' => []]);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessFlagsOrphanedGroup(): void
    {
        $file = __DIR__ . '/../../../../fixtures/OrphanedCronGroup/Bad/etc/crontab.xml';

        ob_start();
        $this->processor->process(['xml' => [$file]]);
        $report = $this->processor->getReport();
        ob_end_clean();

        // Only vendor_module_undeclared is orphaned; default is a builtin.
        $this->assertEquals(1, $this->processor->getFoundCount());
        $this->assertNotEmpty($report[0]['files']);
    }

    public function testGoodFixtureWithMatchingCronGroupsIsClean(): void
    {
        $base = __DIR__ . '/../../../../fixtures/OrphanedCronGroup/Good';
        $files = [
            'xml' => [
                $base . '/etc/crontab.xml',
                $base . '/etc/cron_groups.xml',
            ],
        ];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testDefaultGroupIsAlwaysConsideredValid(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_ocg_' . uniqid();
        mkdir($tempDir);
        $xml = $tempDir . '/crontab.xml';
        file_put_contents($xml, <<<'X'
<?xml version="1.0"?>
<config>
    <group id="default">
        <job name="ok" instance="X" method="y"><schedule>* * * * *</schedule></job>
    </group>
    <group id="index">
        <job name="ok2" instance="X" method="y"><schedule>* * * * *</schedule></job>
    </group>
</config>
X);

        ob_start();
        $this->processor->process(['xml' => [$xml]]);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());

        unlink($xml);
        rmdir($tempDir);
    }

    public function testIgnoresNonCrontabXml(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_ocg_' . uniqid();
        mkdir($tempDir);
        $xml = $tempDir . '/events.xml';
        file_put_contents($xml, '<?xml version="1.0"?><config/>');

        ob_start();
        $this->processor->process(['xml' => [$xml]]);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());

        unlink($xml);
        rmdir($tempDir);
    }
}
