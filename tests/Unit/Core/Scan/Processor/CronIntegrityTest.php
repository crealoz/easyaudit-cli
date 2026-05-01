<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\CronIntegrity;
use PHPUnit\Framework\TestCase;

class CronIntegrityTest extends TestCase
{
    private CronIntegrity $processor;

    protected function setUp(): void
    {
        $this->processor = new CronIntegrity();
    }

    public function testGetIdentifier(): void
    {
        $this->assertEquals('cronIntegrity', $this->processor->getIdentifier());
    }

    public function testGetFileType(): void
    {
        $this->assertEquals('xml', $this->processor->getFileType());
    }

    public function testGetName(): void
    {
        $this->assertNotEmpty($this->processor->getName());
    }

    public function testGetLongDescriptionMentionsCron(): void
    {
        $this->assertStringContainsString('cron', strtolower($this->processor->getLongDescription()));
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
        $base = __DIR__ . '/../../../../fixtures/CronIntegrity/Bad';
        $files = [
            'xml' => [$base . '/etc/crontab.xml'],
            'php' => [$base . '/Vendor/Module/Cron/ExistingHandler.php'],
        ];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertEquals(2, $this->processor->getFoundCount());
        $this->assertNotEmpty($report[0]['files']);
    }

    public function testProcessIgnoresGoodFixture(): void
    {
        $base = __DIR__ . '/../../../../fixtures/CronIntegrity/Good';
        $files = [
            'xml' => [$base . '/etc/crontab.xml'],
            'php' => [$base . '/Vendor/Module/Cron/ExistingHandler.php'],
        ];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
        $this->assertEmpty($report[0]['files']);
    }

    public function testSkipsJobsWhoseInstanceIsNotInScan(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_cron_' . uniqid();
        mkdir($tempDir);
        $xml = $tempDir . '/crontab.xml';
        file_put_contents($xml, <<<'X'
<?xml version="1.0"?>
<config>
    <group id="default">
        <job name="external_handler"
             instance="External\Vendor\Module\Cron\NotInScan"
             method="anyMethod">
            <schedule>* * * * *</schedule>
        </job>
    </group>
</config>
X);

        ob_start();
        $this->processor->process(['xml' => [$xml], 'php' => []]);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());

        unlink($xml);
        rmdir($tempDir);
    }

    public function testIgnoresNonCrontabXml(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_cron_' . uniqid();
        mkdir($tempDir);
        $xml = $tempDir . '/events.xml';
        file_put_contents($xml, '<?xml version="1.0"?><config/>');

        ob_start();
        $this->processor->process(['xml' => [$xml], 'php' => []]);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());

        unlink($xml);
        rmdir($tempDir);
    }

    public function testSkipsMalformedCrontab(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_cron_' . uniqid();
        mkdir($tempDir);
        $xml = $tempDir . '/crontab.xml';
        file_put_contents($xml, '<?xml version="1.0"?><config><group unclosed');

        ob_start();
        $this->processor->process(['xml' => [$xml], 'php' => []]);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());

        unlink($xml);
        rmdir($tempDir);
    }
}
