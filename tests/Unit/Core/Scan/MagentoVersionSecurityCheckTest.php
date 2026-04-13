<?php

namespace EasyAudit\Tests\Core\Scan;

use EasyAudit\Core\Scan\MagentoVersionSecurityCheck;
use PHPUnit\Framework\TestCase;

class MagentoVersionSecurityCheckTest extends TestCase
{
    private MagentoVersionSecurityCheck $check;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->check = new MagentoVersionSecurityCheck();
        $this->fixturesPath = dirname(__DIR__, 3) . '/fixtures/MagentoVersionSecurity';
    }

    public function testOutdatedVersionProducesHighSeverityFindings(): void
    {
        // 2.4.7-p1 is outdated -- there are many patches after it
        $scanPath = $this->createTempScanPath('outdated-composer.lock');

        ob_start();
        $findings = $this->check->check($scanPath);
        ob_end_clean();

        $this->assertNotEmpty($findings, 'Outdated version should produce findings');
        $this->assertCount(1, $findings);

        $finding = $findings[0];
        $this->assertEquals('magento-version-security', $finding['ruleId']);
        $this->assertEquals('Magento Security Vulnerabilities', $finding['name']);
        $this->assertStringContainsString('2.4.7-p1', $finding['shortDescription']);
        $this->assertNotEmpty($finding['files']);

        // All file entries should be high severity
        foreach ($finding['files'] as $file) {
            $this->assertEquals('high', $file['severity']);
            $this->assertStringContainsString('Missing patch', $file['message']);
        }

        $this->cleanupTempScanPath($scanPath);
    }

    public function testLatestVersionProducesNoFindings(): void
    {
        $scanPath = $this->createTempScanPath('latest-composer.lock');

        ob_start();
        $findings = $this->check->check($scanPath);
        ob_end_clean();

        $this->assertEmpty($findings, 'Latest version should produce no findings');

        $this->cleanupTempScanPath($scanPath);
    }

    public function testUnknownVersionProducesMediumSeverityFinding(): void
    {
        $scanPath = $this->createTempScanPath('unknown-composer.lock');

        ob_start();
        $findings = $this->check->check($scanPath);
        ob_end_clean();

        $this->assertNotEmpty($findings, 'Unknown version should produce a finding');
        $this->assertCount(1, $findings);

        $finding = $findings[0];
        $this->assertEquals('magento-version-security', $finding['ruleId']);
        $this->assertStringContainsString('could not be matched', $finding['shortDescription']);
        $this->assertStringContainsString('helpx.adobe.com', $finding['longDescription']);

        // Should be medium severity
        $this->assertEquals('medium', $finding['files'][0]['severity']);

        $this->cleanupTempScanPath($scanPath);
    }

    public function testMissingComposerLockProducesNoFindings(): void
    {
        $scanPath = sys_get_temp_dir() . '/easyaudit_security_test_' . uniqid();
        mkdir($scanPath, 0777, true);

        ob_start();
        $findings = $this->check->check($scanPath);
        ob_end_clean();

        $this->assertEmpty($findings, 'Missing composer.lock should produce no findings');

        rmdir($scanPath);
    }

    public function testBetaVersionIsSkipped(): void
    {
        $scanPath = $this->createTempScanPath('beta-composer.lock');

        ob_start();
        $findings = $this->check->check($scanPath);
        ob_end_clean();

        $this->assertEmpty($findings, 'Beta version should be skipped');

        $this->cleanupTempScanPath($scanPath);
    }

    public function testEnterpriseEditionIsDetected(): void
    {
        $scanPath = $this->createTempScanPath('enterprise-composer.lock');

        ob_start();
        $findings = $this->check->check($scanPath);
        ob_end_clean();

        // Enterprise 2.4.7-p1 is outdated just like community
        $this->assertNotEmpty($findings, 'Enterprise edition should be detected and checked');
        $this->assertEquals('magento-version-security', $findings[0]['ruleId']);

        $this->cleanupTempScanPath($scanPath);
    }

    public function testNoMagentoPackageProducesNoFindings(): void
    {
        $scanPath = $this->createTempScanPath('no-magento-composer.lock');

        ob_start();
        $findings = $this->check->check($scanPath);
        ob_end_clean();

        $this->assertEmpty($findings, 'Non-Magento project should produce no findings');

        $this->cleanupTempScanPath($scanPath);
    }

    public function testFindingContainsApsbAndUrl(): void
    {
        $scanPath = $this->createTempScanPath('outdated-composer.lock');

        ob_start();
        $findings = $this->check->check($scanPath);
        ob_end_clean();

        $this->assertNotEmpty($findings);
        $fileEntries = $findings[0]['files'];
        $this->assertNotEmpty($fileEntries);

        // At least one entry should contain an APSB reference and URL
        $hasApsb = false;
        $hasUrl = false;
        foreach ($fileEntries as $entry) {
            if (str_contains($entry['message'], 'APSB')) {
                $hasApsb = true;
            }
            if (str_contains($entry['message'], 'helpx.adobe.com')) {
                $hasUrl = true;
            }
        }
        $this->assertTrue($hasApsb, 'Findings should reference APSB bulletins');
        $this->assertTrue($hasUrl, 'Findings should include bulletin URLs');

        $this->cleanupTempScanPath($scanPath);
    }

    public function testMissingPatchesAreInAscendingOrder(): void
    {
        $scanPath = $this->createTempScanPath('outdated-composer.lock');

        ob_start();
        $findings = $this->check->check($scanPath);
        ob_end_clean();

        $this->assertNotEmpty($findings);
        $fileEntries = $findings[0]['files'];
        $this->assertGreaterThan(1, count($fileEntries), 'Should have multiple missing patches');

        // Extract patch numbers from messages
        $patchNumbers = [];
        foreach ($fileEntries as $entry) {
            if (preg_match('/2\.4\.7-p(\d+)/', $entry['message'], $m)) {
                $patchNumbers[] = (int) $m[1];
            }
        }

        $sorted = $patchNumbers;
        sort($sorted);
        $this->assertEquals($sorted, $patchNumbers, 'Missing patches should be in ascending order');

        $this->cleanupTempScanPath($scanPath);
    }

    public function testFindingContainsVulnerabilityDetails(): void
    {
        $scanPath = $this->createTempScanPath('outdated-composer.lock');

        ob_start();
        $findings = $this->check->check($scanPath);
        ob_end_clean();

        $this->assertNotEmpty($findings);
        $fileEntries = $findings[0]['files'];

        // Check if any message contains vulnerability category/severity info
        $hasVulnDetails = false;
        foreach ($fileEntries as $entry) {
            // Vulnerability details contain severity levels like Critical, Important, Moderate
            if (preg_match('/(Critical|Important|Moderate)/i', $entry['message'])) {
                $hasVulnDetails = true;
                break;
            }
        }
        $this->assertTrue($hasVulnDetails, 'Findings should include vulnerability severity details');

        $this->cleanupTempScanPath($scanPath);
    }

    public function testLatestPatchOnOldReleaseLineRecommendsUpgrade(): void
    {
        // 2.4.6-p14 is the latest for 2.4.6, but 2.4.8 exists
        $scanPath = $this->createTempScanPath('latest-old-line-composer.lock');

        ob_start();
        $findings = $this->check->check($scanPath);
        ob_end_clean();

        $this->assertNotEmpty($findings, 'Latest patch on old release line should recommend upgrade');
        $this->assertCount(1, $findings);

        $finding = $findings[0];
        $this->assertEquals('magento-version-security', $finding['ruleId']);
        $this->assertStringContainsString('2.4.8', $finding['longDescription']);
        $this->assertStringContainsString('newer release line', $finding['shortDescription']);

        // Should be medium severity (patched but old line)
        $this->assertEquals('medium', $finding['files'][0]['severity']);

        $this->cleanupTempScanPath($scanPath);
    }

    public function testEolVersionGetsHighSeverity(): void
    {
        // 2.4.4-p17 is the latest for 2.4.4, but 2.4.4 extended support ends April 2026
        $scanPath = $this->createTempScanPath('eol-composer.lock');

        ob_start();
        $findings = $this->check->check($scanPath);
        ob_end_clean();

        $this->assertNotEmpty($findings, 'EOL version should produce findings');
        $this->assertCount(1, $findings);

        $finding = $findings[0];
        $this->assertEquals('magento-version-security', $finding['ruleId']);

        // Should be high severity (EOL or extended support)
        $this->assertEquals('high', $finding['files'][0]['severity']);

        // Should mention upgrading to 2.4.8
        $this->assertStringContainsString('2.4.8', $finding['longDescription']);

        $this->cleanupTempScanPath($scanPath);
    }

    public function testOutdatedVersionMentionsNewerReleaseLine(): void
    {
        // 2.4.7-p1 is outdated AND 2.4.8 exists
        $scanPath = $this->createTempScanPath('outdated-composer.lock');

        ob_start();
        $findings = $this->check->check($scanPath);
        ob_end_clean();

        $this->assertNotEmpty($findings);
        $finding = $findings[0];

        // The longDescription should mention the newer release line
        $this->assertStringContainsString('2.4.8', $finding['longDescription']);
        $this->assertStringContainsString('upgrading', $finding['longDescription']);

        $this->cleanupTempScanPath($scanPath);
    }

    /**
     * Create a temp directory with a composer.lock copied from fixtures.
     */
    private function createTempScanPath(string $fixtureName): string
    {
        $scanPath = sys_get_temp_dir() . '/easyaudit_security_test_' . uniqid();
        mkdir($scanPath, 0777, true);
        copy(
            $this->fixturesPath . '/' . $fixtureName,
            $scanPath . '/composer.lock'
        );
        return $scanPath;
    }

    private function cleanupTempScanPath(string $scanPath): void
    {
        @unlink($scanPath . '/composer.lock');
        @rmdir($scanPath);
    }
}