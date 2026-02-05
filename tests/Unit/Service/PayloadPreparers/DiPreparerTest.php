<?php

namespace EasyAudit\Tests\Service\PayloadPreparers;

use EasyAudit\Exception\Fixer\CouldNotPreparePayloadException;
use EasyAudit\Service\PayloadPreparers\AbstractPreparer;
use EasyAudit\Service\PayloadPreparers\DiPreparer;
use EasyAudit\Service\PayloadPreparers\PreparerInterface;
use PHPUnit\Framework\TestCase;

class DiPreparerTest extends TestCase
{
    private DiPreparer $preparer;

    protected function setUp(): void
    {
        $this->preparer = new DiPreparer();
    }

    public function testImplementsPreparerInterface(): void
    {
        $this->assertInstanceOf(PreparerInterface::class, $this->preparer);
    }

    public function testExtendsAbstractPreparer(): void
    {
        $this->assertInstanceOf(AbstractPreparer::class, $this->preparer);
    }

    public function testPrepareFilesHandlesProxyConfiguration(): void
    {
        $findings = [
            [
                'ruleId' => 'noProxyUsedForHeavyClasses',
                'files' => [
                    [
                        'file' => '/tmp/test/Model/Product.php',
                        'metadata' => [
                            'diFile' => '/tmp/test/etc/di.xml',
                            'type' => 'Vendor\\Module\\Model\\Product',
                            'argument' => 'customerSession',
                            'proxy' => 'Magento\\Customer\\Model\\Session\\Proxy',
                        ],
                    ],
                ],
            ],
        ];

        // Rules are mapped: noProxyUsedForHeavyClasses -> proxyConfiguration
        $fixables = ['proxyConfiguration' => 1];

        $result = $this->preparer->prepareFiles($findings, $fixables);

        // Should be grouped by di.xml file
        $this->assertArrayHasKey('/tmp/test/etc/di.xml', $result);

        // Then by type
        $this->assertArrayHasKey('Vendor\\Module\\Model\\Product', $result['/tmp/test/etc/di.xml']);

        // Contains proxy entry
        $proxies = $result['/tmp/test/etc/di.xml']['Vendor\\Module\\Model\\Product'];
        $this->assertCount(1, $proxies);
        $this->assertEquals('customerSession', $proxies[0]['argument']);
        $this->assertEquals('Magento\\Customer\\Model\\Session\\Proxy', $proxies[0]['proxy']);
    }

    public function testPrepareFilesGroupsMultipleProxiesBySameType(): void
    {
        $findings = [
            [
                'ruleId' => 'noProxyUsedForHeavyClasses',
                'files' => [
                    [
                        'file' => '/tmp/test/Model/Product.php',
                        'metadata' => [
                            'diFile' => '/tmp/test/etc/di.xml',
                            'type' => 'Vendor\\Module\\Model\\Product',
                            'argument' => 'customerSession',
                            'proxy' => 'Magento\\Customer\\Model\\Session\\Proxy',
                        ],
                    ],
                    [
                        'file' => '/tmp/test/Model/Product.php',
                        'metadata' => [
                            'diFile' => '/tmp/test/etc/di.xml',
                            'type' => 'Vendor\\Module\\Model\\Product',
                            'argument' => 'storeManager',
                            'proxy' => 'Magento\\Store\\Model\\StoreManagerInterface\\Proxy',
                        ],
                    ],
                ],
            ],
        ];

        // Rules are mapped: noProxyUsedForHeavyClasses -> proxyConfiguration
        $fixables = ['proxyConfiguration' => 1];

        $result = $this->preparer->prepareFiles($findings, $fixables);

        // Should have 2 proxies for same type
        $proxies = $result['/tmp/test/etc/di.xml']['Vendor\\Module\\Model\\Product'];
        $this->assertCount(2, $proxies);
    }

    public function testPrepareFilesSkipsNonProxyRules(): void
    {
        $findings = [
            [
                'ruleId' => 'useOfObjectManager',  // Not a proxy rule
                'files' => [
                    [
                        'file' => '/tmp/test/Model/Product.php',
                        'metadata' => ['line' => 10],
                    ],
                ],
            ],
        ];

        $fixables = ['useOfObjectManager' => 1];

        $result = $this->preparer->prepareFiles($findings, $fixables);

        $this->assertEmpty($result);
    }

    public function testPrepareFilesSkipsNonFixableProxyRules(): void
    {
        $findings = [
            [
                'ruleId' => 'noProxyUsedForHeavyClasses',
                'files' => [
                    [
                        'file' => '/tmp/test/Model/Product.php',
                        'metadata' => [
                            'diFile' => '/tmp/test/etc/di.xml',
                            'type' => 'Vendor\\Module\\Model\\Product',
                            'argument' => 'customerSession',
                            'proxy' => 'Magento\\Customer\\Model\\Session\\Proxy',
                        ],
                    ],
                ],
            ],
        ];

        // noProxyUsedForHeavyClasses not in fixables
        $fixables = ['useOfObjectManager' => 1];

        $result = $this->preparer->prepareFiles($findings, $fixables);

        $this->assertEmpty($result);
    }

    public function testPrepareFilesSkipsMissingMetadata(): void
    {
        $findings = [
            [
                'ruleId' => 'noProxyUsedForHeavyClasses',
                'files' => [
                    [
                        'file' => '/tmp/test/Model/Product.php',
                        'metadata' => [
                            'diFile' => '/tmp/test/etc/di.xml',
                            // Missing 'type', 'argument', 'proxy'
                        ],
                    ],
                ],
            ],
        ];

        // Rules are mapped: noProxyUsedForHeavyClasses -> proxyConfiguration
        $fixables = ['proxyConfiguration' => 1];

        $result = $this->preparer->prepareFiles($findings, $fixables);

        $this->assertEmpty($result);
    }

    public function testPrepareFilesAvoidsDuplicates(): void
    {
        $findings = [
            [
                'ruleId' => 'noProxyUsedForHeavyClasses',
                'files' => [
                    [
                        'file' => '/tmp/test/Model/Product.php',
                        'metadata' => [
                            'diFile' => '/tmp/test/etc/di.xml',
                            'type' => 'Vendor\\Module\\Model\\Product',
                            'argument' => 'customerSession',
                            'proxy' => 'Magento\\Customer\\Model\\Session\\Proxy',
                        ],
                    ],
                    // Duplicate entry
                    [
                        'file' => '/tmp/test/Model/Product.php',
                        'metadata' => [
                            'diFile' => '/tmp/test/etc/di.xml',
                            'type' => 'Vendor\\Module\\Model\\Product',
                            'argument' => 'customerSession',
                            'proxy' => 'Magento\\Customer\\Model\\Session\\Proxy',
                        ],
                    ],
                ],
            ],
        ];

        // Rules are mapped: noProxyUsedForHeavyClasses -> proxyConfiguration
        $fixables = ['proxyConfiguration' => 1];

        $result = $this->preparer->prepareFiles($findings, $fixables);

        // Should only have 1 entry, not 2
        $proxies = $result['/tmp/test/etc/di.xml']['Vendor\\Module\\Model\\Product'];
        $this->assertCount(1, $proxies);
    }

    public function testPrepareFilesHandlesNoProxyInCommands(): void
    {
        $findings = [
            [
                'ruleId' => 'noProxyUsedInCommands',  // Another SPECIFIC_RULES rule
                'files' => [
                    [
                        'file' => '/tmp/test/Console/Command.php',
                        'metadata' => [
                            'diFile' => '/tmp/test/etc/di.xml',
                            'type' => 'Vendor\\Module\\Console\\Command',
                            'argument' => 'productRepository',
                            'proxy' => 'Magento\\Catalog\\Api\\ProductRepositoryInterface\\Proxy',
                        ],
                    ],
                ],
            ],
        ];

        // Rules are mapped: noProxyUsedInCommands -> proxyConfiguration
        $fixables = ['proxyConfiguration' => 1];

        $result = $this->preparer->prepareFiles($findings, $fixables);

        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('/tmp/test/etc/di.xml', $result);
    }

    public function testPrepareFilesHandlesEmptyFindings(): void
    {
        // Rules are mapped: noProxyUsedForHeavyClasses -> proxyConfiguration
        $result = $this->preparer->prepareFiles([], ['proxyConfiguration' => 1]);
        $this->assertEmpty($result);
    }

    public function testPreparePayloadForDiXml(): void
    {
        $tempFile = sys_get_temp_dir() . '/easyaudit_di_test_' . uniqid() . '.xml';
        $diContent = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <type name="Vendor\Module\Model\Product"/>
</config>
XML;
        file_put_contents($tempFile, $diContent);

        $data = [
            'Vendor\\Module\\Model\\Product' => [
                ['argument' => 'customerSession', 'proxy' => 'Magento\\Customer\\Model\\Session\\Proxy'],
                ['argument' => 'storeManager', 'proxy' => 'Magento\\Store\\Model\\StoreManagerInterface\\Proxy'],
            ],
        ];

        try {
            $result = $this->preparer->preparePayload($tempFile, $data);

            $this->assertArrayHasKey('content', $result);
            $this->assertArrayHasKey('rules', $result);
            $this->assertStringContainsString('Vendor\Module\Model\Product', $result['content']);

            // Should have proxyConfiguration rule with flattened entries
            $this->assertArrayHasKey('proxyConfiguration', $result['rules']);
            $proxyRules = $result['rules']['proxyConfiguration'];
            $this->assertCount(2, $proxyRules);

            // Each entry should have type, argument, proxy
            $this->assertEquals('Vendor\\Module\\Model\\Product', $proxyRules[0]['type']);
            $this->assertEquals('customerSession', $proxyRules[0]['argument']);
            $this->assertEquals('Magento\\Customer\\Model\\Session\\Proxy', $proxyRules[0]['proxy']);
        } finally {
            @unlink($tempFile);
        }
    }

    public function testPreparePayloadThrowsOnMissingFile(): void
    {
        $this->expectException(CouldNotPreparePayloadException::class);
        $this->expectExceptionMessage('Failed to read di.xml file');

        $this->preparer->preparePayload('/nonexistent/di.xml', []);
    }

    public function testPreparePayloadHandlesEmptyData(): void
    {
        $tempFile = sys_get_temp_dir() . '/easyaudit_di_test_' . uniqid() . '.xml';
        file_put_contents($tempFile, '<?xml version="1.0"?><config/>');

        try {
            $result = $this->preparer->preparePayload($tempFile, []);

            $this->assertArrayHasKey('content', $result);
            $this->assertArrayHasKey('rules', $result);
            $this->assertArrayHasKey('proxyConfiguration', $result['rules']);
            $this->assertEmpty($result['rules']['proxyConfiguration']);
        } finally {
            @unlink($tempFile);
        }
    }

    public function testPreparePayloadFlattensMultipleTypes(): void
    {
        $tempFile = sys_get_temp_dir() . '/easyaudit_di_test_' . uniqid() . '.xml';
        file_put_contents($tempFile, '<?xml version="1.0"?><config/>');

        $data = [
            'Vendor\\Module\\Model\\Product' => [
                ['argument' => 'customerSession', 'proxy' => 'Proxy1'],
            ],
            'Vendor\\Module\\Model\\Category' => [
                ['argument' => 'storeManager', 'proxy' => 'Proxy2'],
            ],
        ];

        try {
            $result = $this->preparer->preparePayload($tempFile, $data);

            $proxyRules = $result['rules']['proxyConfiguration'];
            $this->assertCount(2, $proxyRules);

            // Should have entries from both types
            $types = array_column($proxyRules, 'type');
            $this->assertContains('Vendor\\Module\\Model\\Product', $types);
            $this->assertContains('Vendor\\Module\\Model\\Category', $types);
        } finally {
            @unlink($tempFile);
        }
    }

    public function testCanFixOnlyAcceptsSpecificRulesForDiPreparer(): void
    {
        // DiPreparer should only handle rules defined in SPECIFIC_RULES that map to DiPreparer
        $findings = [
            [
                'ruleId' => 'noProxyUsedForHeavyClasses',  // This IS a DiPreparer rule
                'files' => [
                    [
                        'file' => '/tmp/test/Model/Product.php',
                        'metadata' => [
                            'diFile' => '/tmp/test/etc/di.xml',
                            'type' => 'Vendor\\Module\\Model\\Product',
                            'argument' => 'customerSession',
                            'proxy' => 'Magento\\Customer\\Model\\Session\\Proxy',
                        ],
                    ],
                ],
            ],
            [
                'ruleId' => 'replaceObjectManager',  // This is NOT a DiPreparer rule
                'files' => [
                    [
                        'file' => '/tmp/test/Model/Other.php',
                        'metadata' => ['line' => 10],
                    ],
                ],
            ],
        ];

        $fixables = [
            'proxyConfiguration' => 1,  // Mapped rule for proxy configs
            'replaceObjectManager' => 1,
        ];

        $result = $this->preparer->prepareFiles($findings, $fixables);

        // Should only contain proxy rule files grouped by di.xml
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('/tmp/test/etc/di.xml', $result);
    }
}
