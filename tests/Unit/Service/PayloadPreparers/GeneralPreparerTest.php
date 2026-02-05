<?php

namespace EasyAudit\Tests\Service\PayloadPreparers;

use EasyAudit\Exception\Fixer\CouldNotPreparePayloadException;
use EasyAudit\Service\PayloadPreparers\AbstractPreparer;
use EasyAudit\Service\PayloadPreparers\GeneralPreparer;
use EasyAudit\Service\PayloadPreparers\PreparerInterface;
use PHPUnit\Framework\TestCase;

class GeneralPreparerTest extends TestCase
{
    private GeneralPreparer $preparer;

    protected function setUp(): void
    {
        $this->preparer = new GeneralPreparer();
    }

    public function testImplementsPreparerInterface(): void
    {
        $this->assertInstanceOf(PreparerInterface::class, $this->preparer);
    }

    public function testExtendsAbstractPreparer(): void
    {
        $this->assertInstanceOf(AbstractPreparer::class, $this->preparer);
    }

    public function testPrepareFilesGroupsByPath(): void
    {
        $findings = [
            [
                'ruleId' => 'useOfObjectManager',
                'files' => [
                    ['file' => '/tmp/test/Model/Product.php', 'metadata' => ['line' => 10]],
                    ['file' => '/tmp/test/Model/Category.php', 'metadata' => ['line' => 20]],
                ]
            ],
            [
                'ruleId' => 'aroundToBeforePlugin',
                'files' => [
                    ['file' => '/tmp/test/Model/Product.php', 'metadata' => ['function' => 'execute']],
                ]
            ],
        ];

        $fixables = [
            'useOfObjectManager' => 1,
            'aroundToBeforePlugin' => 2,
        ];

        $result = $this->preparer->prepareFiles($findings, $fixables);

        // Should group by file path
        $this->assertArrayHasKey('/tmp/test/Model/Product.php', $result);
        $this->assertArrayHasKey('/tmp/test/Model/Category.php', $result);

        // Product.php should have 2 issues from different rules
        $productIssues = $result['/tmp/test/Model/Product.php']['issues'];
        $this->assertCount(2, $productIssues);

        // Category.php should have 1 issue
        $categoryIssues = $result['/tmp/test/Model/Category.php']['issues'];
        $this->assertCount(1, $categoryIssues);
    }

    public function testPrepareFilesExcludesProxyRules(): void
    {
        $findings = [
            [
                'ruleId' => 'noProxyUsedForHeavyClasses',  // This is a SPECIFIC_RULES rule
                'files' => [
                    ['file' => '/tmp/test/Model/Product.php', 'metadata' => ['diFile' => 'di.xml']],
                ]
            ],
            [
                'ruleId' => 'useOfObjectManager',
                'files' => [
                    ['file' => '/tmp/test/Model/Product.php', 'metadata' => ['line' => 10]],
                ]
            ],
        ];

        $fixables = [
            'noProxyUsedForHeavyClasses' => 1,
            'useOfObjectManager' => 1,
        ];

        $result = $this->preparer->prepareFiles($findings, $fixables);

        // Should only have useOfObjectManager issue, not proxy rule
        $this->assertArrayHasKey('/tmp/test/Model/Product.php', $result);
        $issues = $result['/tmp/test/Model/Product.php']['issues'];
        $this->assertCount(1, $issues);
        $this->assertEquals('useOfObjectManager', $issues[0]['ruleId']);
    }

    public function testPrepareFilesSkipsNonFixableRules(): void
    {
        $findings = [
            [
                'ruleId' => 'nonFixableRule',
                'files' => [
                    ['file' => '/tmp/test/Model/Product.php', 'metadata' => ['line' => 10]],
                ]
            ],
        ];

        $fixables = [
            'useOfObjectManager' => 1,  // nonFixableRule not in fixables
        ];

        $result = $this->preparer->prepareFiles($findings, $fixables);

        // Should be empty since rule is not fixable
        $this->assertEmpty($result);
    }

    public function testPrepareFilesHandlesEmptyFindings(): void
    {
        $result = $this->preparer->prepareFiles([], ['useOfObjectManager' => 1]);
        $this->assertEmpty($result);
    }

    public function testPrepareFilesHandlesEmptyFixables(): void
    {
        $findings = [
            [
                'ruleId' => 'useOfObjectManager',
                'files' => [
                    ['file' => '/tmp/test/Model/Product.php', 'metadata' => []],
                ]
            ],
        ];

        $result = $this->preparer->prepareFiles($findings, []);
        $this->assertEmpty($result);
    }

    public function testPreparePayloadReadsFileContent(): void
    {
        $tempFile = sys_get_temp_dir() . '/easyaudit_preparer_test_' . uniqid() . '.php';
        file_put_contents($tempFile, '<?php class Test {}');

        $data = [
            'issues' => [
                ['ruleId' => 'useOfObjectManager', 'metadata' => ['line' => 5]],
            ]
        ];

        try {
            $result = $this->preparer->preparePayload($tempFile, $data);

            $this->assertArrayHasKey('content', $result);
            $this->assertArrayHasKey('rules', $result);
            $this->assertEquals('<?php class Test {}', $result['content']);
        } finally {
            @unlink($tempFile);
        }
    }

    public function testPreparePayloadTransformsIssuesToRules(): void
    {
        $tempFile = sys_get_temp_dir() . '/easyaudit_preparer_test_' . uniqid() . '.php';
        file_put_contents($tempFile, '<?php class Test {}');

        $data = [
            'issues' => [
                ['ruleId' => 'useOfObjectManager', 'metadata' => ['line' => 5]],
                ['ruleId' => 'aroundToBeforePlugin', 'metadata' => ['function' => 'execute']],
            ]
        ];

        try {
            $result = $this->preparer->preparePayload($tempFile, $data);

            $this->assertArrayHasKey('useOfObjectManager', $result['rules']);
            $this->assertArrayHasKey('aroundToBeforePlugin', $result['rules']);
            $this->assertEquals(['line' => 5], $result['rules']['useOfObjectManager']);
            $this->assertEquals(['function' => 'execute'], $result['rules']['aroundToBeforePlugin']);
        } finally {
            @unlink($tempFile);
        }
    }

    public function testPreparePayloadMergesMetadataForSameRule(): void
    {
        $tempFile = sys_get_temp_dir() . '/easyaudit_preparer_test_' . uniqid() . '.php';
        file_put_contents($tempFile, '<?php class Test {}');

        $data = [
            'issues' => [
                ['ruleId' => 'useOfObjectManager', 'metadata' => ['line' => 5]],
                ['ruleId' => 'useOfObjectManager', 'metadata' => ['line' => 10]],
            ]
        ];

        try {
            $result = $this->preparer->preparePayload($tempFile, $data);

            // Metadata should be merged (array_merge behavior)
            $this->assertArrayHasKey('useOfObjectManager', $result['rules']);
            $this->assertEquals(['line' => 10], $result['rules']['useOfObjectManager']);
        } finally {
            @unlink($tempFile);
        }
    }

    public function testPreparePayloadThrowsOnMissingFile(): void
    {
        $this->expectException(CouldNotPreparePayloadException::class);
        $this->expectExceptionMessage('Failed to read file');

        $this->preparer->preparePayload('/nonexistent/file.php', ['issues' => []]);
    }

    public function testPreparePayloadHandlesEmptyIssues(): void
    {
        $tempFile = sys_get_temp_dir() . '/easyaudit_preparer_test_' . uniqid() . '.php';
        file_put_contents($tempFile, '<?php class Test {}');

        try {
            $result = $this->preparer->preparePayload($tempFile, ['issues' => []]);

            $this->assertEquals('<?php class Test {}', $result['content']);
            $this->assertEmpty($result['rules']);
        } finally {
            @unlink($tempFile);
        }
    }

    public function testPreparePayloadHandlesEmptyMetadata(): void
    {
        $tempFile = sys_get_temp_dir() . '/easyaudit_preparer_test_' . uniqid() . '.php';
        file_put_contents($tempFile, '<?php class Test {}');

        $data = [
            'issues' => [
                ['ruleId' => 'useOfObjectManager'],  // No metadata key
            ]
        ];

        try {
            $result = $this->preparer->preparePayload($tempFile, $data);

            $this->assertArrayHasKey('useOfObjectManager', $result['rules']);
            $this->assertEmpty($result['rules']['useOfObjectManager']);
        } finally {
            @unlink($tempFile);
        }
    }

    public function testCanFixExcludesSpecificRules(): void
    {
        // GeneralPreparer should NOT handle rules that are in SPECIFIC_RULES
        $findings = [
            [
                'ruleId' => 'noProxyUsedForHeavyClasses',  // This is a SPECIFIC_RULES rule for DiPreparer
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
                'ruleId' => 'noProxyUsedInCommands',  // Another SPECIFIC_RULES rule for DiPreparer
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
            [
                'ruleId' => 'replaceObjectManager',  // This is NOT a SPECIFIC_RULES rule
                'files' => [
                    ['file' => '/tmp/test/Model/Other.php', 'metadata' => ['line' => 10]],
                ],
            ],
        ];

        $fixables = [
            'proxyConfiguration' => 1,
            'replaceObjectManager' => 1,
        ];

        $result = $this->preparer->prepareFiles($findings, $fixables);

        // Should only contain replaceObjectManager files, not proxy rules
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('/tmp/test/Model/Other.php', $result);

        // Should not have proxy rule files
        $this->assertArrayNotHasKey('/tmp/test/Model/Product.php', $result);
        $this->assertArrayNotHasKey('/tmp/test/Console/Command.php', $result);
    }
}
