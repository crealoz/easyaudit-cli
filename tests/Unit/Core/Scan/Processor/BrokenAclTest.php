<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\BrokenAcl;
use PHPUnit\Framework\TestCase;

class BrokenAclTest extends TestCase
{
    private BrokenAcl $processor;

    protected function setUp(): void
    {
        $this->processor = new BrokenAcl();
    }

    public function testGetIdentifier(): void
    {
        $this->assertEquals('brokenAcl', $this->processor->getIdentifier());
    }

    public function testGetFileType(): void
    {
        $this->assertEquals('xml', $this->processor->getFileType());
    }

    public function testGetName(): void
    {
        $this->assertNotEmpty($this->processor->getName());
    }

    public function testGetLongDescriptionMentionsAcl(): void
    {
        $this->assertStringContainsString('ACL', $this->processor->getLongDescription());
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
        $base = __DIR__ . '/../../../../fixtures/BrokenAcl/Bad';
        $files = [
            'xml' => [
                $base . '/etc/acl.xml',
                $base . '/etc/adminhtml/menu.xml',
            ],
        ];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        // Two undefined resources: Vendor_Module::customers and Vendor_Module::undefined_shipments.
        $this->assertEquals(2, $this->processor->getFoundCount());
        $this->assertNotEmpty($report[0]['files']);
    }

    public function testProcessIgnoresGoodFixture(): void
    {
        $base = __DIR__ . '/../../../../fixtures/BrokenAcl/Good';
        $files = [
            'xml' => [
                $base . '/etc/acl.xml',
                $base . '/etc/adminhtml/menu.xml',
            ],
        ];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
        $this->assertEmpty($report[0]['files']);
    }

    public function testFrontendMenuIsIgnored(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_bracl_' . uniqid();
        mkdir($tempDir . '/etc/frontend', 0777, true);
        mkdir($tempDir . '/etc/adminhtml', 0777, true);

        file_put_contents(
            $tempDir . '/etc/acl.xml',
            '<?xml version="1.0"?><config><acl><resources>'
            . '<resource id="Magento_Backend::admin"><resource id="X::y"/></resource>'
            . '</resources></acl></config>'
        );
        file_put_contents(
            $tempDir . '/etc/frontend/menu.xml',
            '<?xml version="1.0"?><config><menu>'
            . '<add id="not-admin" resource="undefined" parent="root"/>'
            . '</menu></config>'
        );

        ob_start();
        $this->processor->process([
            'xml' => [
                $tempDir . '/etc/acl.xml',
                $tempDir . '/etc/frontend/menu.xml',
            ],
        ]);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());

        unlink($tempDir . '/etc/acl.xml');
        unlink($tempDir . '/etc/frontend/menu.xml');
        rmdir($tempDir . '/etc/frontend');
        rmdir($tempDir . '/etc/adminhtml');
        rmdir($tempDir . '/etc');
        rmdir($tempDir);
    }

    public function testMalformedAclIsSkipped(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_bracl_' . uniqid();
        mkdir($tempDir . '/etc/adminhtml', 0777, true);

        file_put_contents($tempDir . '/etc/acl.xml', '<?xml version="1.0"?><config><acl><resources');
        file_put_contents(
            $tempDir . '/etc/adminhtml/menu.xml',
            '<?xml version="1.0"?><config><menu>'
            . '<add id="orphan" resource="ghost" parent="root"/>'
            . '</menu></config>'
        );

        ob_start();
        $this->processor->process([
            'xml' => [
                $tempDir . '/etc/acl.xml',
                $tempDir . '/etc/adminhtml/menu.xml',
            ],
        ]);
        ob_end_clean();

        // acl.xml can't be parsed, so every menu resource is seen as orphan.
        $this->assertEquals(1, $this->processor->getFoundCount());

        unlink($tempDir . '/etc/acl.xml');
        unlink($tempDir . '/etc/adminhtml/menu.xml');
        rmdir($tempDir . '/etc/adminhtml');
        rmdir($tempDir . '/etc');
        rmdir($tempDir);
    }
}
