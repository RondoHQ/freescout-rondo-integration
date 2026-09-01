<?php

use PHPUnit\Framework\TestCase;

class PackageContractTest extends TestCase
{
    public function testReleasePackageHasExactlyOneTopLevelDirectory()
    {
        passthru(PHP_BINARY . ' ' . escapeshellarg(dirname(__DIR__) . '/scripts/package.php'), $status);
        $this->assertSame(0, $status);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open(dirname(__DIR__) . '/build/rondo-integration.zip') === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        $this->assertContains('RondoIntegration/module.json', $names);
        $this->assertContains('RondoIntegration/LICENSES/Sidebar-Webhook-MIT.txt', $names);
        foreach ($names as $name) {
            $this->assertStringStartsWith('RondoIntegration/', $name);
            $this->assertStringNotContainsString('/tests/', $name);
            $this->assertStringNotContainsString('/.git/', $name);
        }
    }
}
