<?php

namespace EasyAudit\Tests\Core\Scan;

use EasyAudit\Core\Scan\Severity;
use PHPUnit\Framework\TestCase;

class SeverityTest extends TestCase
{
    public function testToSarif(): void
    {
        $this->assertEquals('error', Severity::HIGH->toSarif());
        $this->assertEquals('warning', Severity::MEDIUM->toSarif());
        $this->assertEquals('note', Severity::LOW->toSarif());
    }

    public function testLabel(): void
    {
        $this->assertEquals('High', Severity::HIGH->label());
        $this->assertEquals('Medium', Severity::MEDIUM->label());
        $this->assertEquals('Low', Severity::LOW->label());
    }

    public function testDefault(): void
    {
        $this->assertEquals(Severity::MEDIUM, Severity::default());
    }

    public function testFromValidValues(): void
    {
        $this->assertEquals(Severity::HIGH, Severity::from('high'));
        $this->assertEquals(Severity::MEDIUM, Severity::from('medium'));
        $this->assertEquals(Severity::LOW, Severity::from('low'));
    }

    public function testFromInvalidValueThrows(): void
    {
        $this->expectException(\ValueError::class);
        Severity::from('error');
    }

    public function testTryFromInvalidReturnsNull(): void
    {
        $this->assertNull(Severity::tryFrom('error'));
        $this->assertNull(Severity::tryFrom('warning'));
        $this->assertNull(Severity::tryFrom('note'));
    }

    public function testBackedValues(): void
    {
        $this->assertEquals('high', Severity::HIGH->value);
        $this->assertEquals('medium', Severity::MEDIUM->value);
        $this->assertEquals('low', Severity::LOW->value);
    }
}
