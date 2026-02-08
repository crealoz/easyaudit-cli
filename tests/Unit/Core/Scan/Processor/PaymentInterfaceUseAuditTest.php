<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\PaymentInterfaceUseAudit;
use PHPUnit\Framework\TestCase;

class PaymentInterfaceUseAuditTest extends TestCase
{
    private PaymentInterfaceUseAudit $processor;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->processor = new PaymentInterfaceUseAudit();
        $this->tempDir = sys_get_temp_dir() . '/easyaudit_payment_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testGetIdentifier(): void
    {
        $this->assertEquals('extensionOfAbstractMethod', $this->processor->getIdentifier());
    }

    public function testGetFileType(): void
    {
        $this->assertEquals('php', $this->processor->getFileType());
    }

    public function testGetName(): void
    {
        $this->assertEquals('Payment Interface Use Audit', $this->processor->getName());
    }

    public function testGetMessageContainsAbstractMethod(): void
    {
        $this->assertStringContainsString('AbstractMethod', $this->processor->getMessage());
    }

    public function testGetLongDescriptionExplainsProblem(): void
    {
        $description = $this->processor->getLongDescription();
        $this->assertStringContainsString('deprecated', $description);
        $this->assertStringContainsString('PaymentMethodInterface', $description);
    }

    public function testProcessDetectsDeprecatedPaymentMethod(): void
    {
        $content = <<<'PHP'
<?php
namespace Vendor\Payment\Model\Method;

class DeprecatedPayment extends \Magento\Payment\Model\Method\AbstractMethod
{
    protected $_code = 'deprecated_payment';
}
PHP;
        $file = $this->tempDir . '/DeprecatedPayment.php';
        file_put_contents($file, $content);

        $files = ['php' => [$file]];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $this->processor->getFoundCount());
    }

    public function testProcessDetectsWithoutLeadingBackslash(): void
    {
        $content = <<<'PHP'
<?php
namespace Vendor\Payment\Model;

use Magento\Payment\Model\Method\AbstractMethod;

class BadPayment extends Magento\Payment\Model\Method\AbstractMethod
{
}
PHP;
        $file = $this->tempDir . '/BadPayment.php';
        file_put_contents($file, $content);

        $processor = new PaymentInterfaceUseAudit();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $processor->getFoundCount());
    }

    public function testProcessIgnoresModernPaymentMethod(): void
    {
        $content = <<<'PHP'
<?php
namespace Vendor\Payment\Model;

use Magento\Payment\Api\Data\PaymentMethodInterface;

class ModernPayment implements PaymentMethodInterface
{
    public function getCode(): string { return 'modern'; }
}
PHP;
        $file = $this->tempDir . '/ModernPayment.php';
        file_put_contents($file, $content);

        $processor = new PaymentInterfaceUseAudit();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount());
    }

    public function testProcessWithEmptyFilesArray(): void
    {
        $files = ['php' => []];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessWithNoPhpFiles(): void
    {
        $files = [];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessSkipsTestFiles(): void
    {
        $testDir = $this->tempDir . '/Test';
        mkdir($testDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Vendor\Payment\Model;

class TestPayment extends \Magento\Payment\Model\Method\AbstractMethod
{
}
PHP;
        $file = $testDir . '/TestPayment.php';
        file_put_contents($file, $content);

        $processor = new PaymentInterfaceUseAudit();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount());

        unlink($file);
        rmdir($testDir);
    }

    public function testGetReportFormatWhenIssuesFound(): void
    {
        $content = <<<'PHP'
<?php
namespace Vendor\Payment\Model\Method;

class DeprecatedPayment extends \Magento\Payment\Model\Method\AbstractMethod
{
    protected $_code = 'deprecated';
}
PHP;
        $file = $this->tempDir . '/DeprecatedPayment.php';
        file_put_contents($file, $content);

        $files = ['php' => [$file]];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertNotEmpty($report);
        $this->assertArrayHasKey('ruleId', $report[0]);
        $this->assertEquals('extensionOfAbstractMethod', $report[0]['ruleId']);
        $this->assertArrayHasKey('name', $report[0]);
        $this->assertArrayHasKey('shortDescription', $report[0]);
        $this->assertArrayHasKey('longDescription', $report[0]);
        $this->assertArrayHasKey('files', $report[0]);
        $this->assertNotEmpty($report[0]['files']);
    }

    public function testGetReportEmptyWhenNoIssues(): void
    {
        $content = <<<'PHP'
<?php
namespace Vendor\Payment\Model;

class CleanPayment
{
    public function process(): void {}
}
PHP;
        $file = $this->tempDir . '/CleanPayment.php';
        file_put_contents($file, $content);

        $processor = new PaymentInterfaceUseAudit();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertEmpty($report);
    }
}
