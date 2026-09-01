<?php

use Modules\RondoIntegration\Services\SettingsService;
use Modules\RondoIntegration\Services\SidebarDocument;
use PHPUnit\Framework\TestCase;

class SidebarDocumentTest extends TestCase
{
    public function testRemoteMarkupIsSanitizedAndWrappedInOpaqueSandboxDocument()
    {
        $settings = $this->getMockBuilder(SettingsService::class)->onlyMethods(['baseUrl', 'get'])->getMock();
        $settings->method('baseUrl')->willReturn('https://rondo.example.nl');
        $settings->method('get')->willReturnCallback(function ($key, $default = null) {
            return ['accent' => '#006935', 'accent_surface' => '#CCE1D7'][$key] ?? $default;
        });
        $document = new SidebarDocument($settings);
        $result = $document->render('<div onclick="alert(1)"><script>alert(2)</script><a href="https://evil.example/a">bad</a><a href="https://rondo.example.nl/people/1">good</a><form><input></form></div>');
        $this->assertStringNotContainsString('onclick', $result['srcdoc']);
        $this->assertStringNotContainsString('alert(2)', $result['srcdoc']);
        $this->assertStringNotContainsString('https://evil.example', $result['srcdoc']);
        $this->assertStringContainsString('https://rondo.example.nl/people/1', $result['srcdoc']);
        $this->assertStringContainsString('default-src &#039;none&#039;', $result['srcdoc']);
        $this->assertStringContainsString('rondo-sidebar-height', $result['srcdoc']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', $result['channel']);
    }
}
