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
        $result = $document->render('<div onclick="alert(1)"><script>alert(2)</script><a href="https://evil.example/a">bad</a><a href="https://rondo.example.nl/people/1">good</a><a href="https://club.sportlink.com/member/member-details/SQJG27J/general">Sportlink</a><a href="https://club.sportlink.com/member/member-details/SQJG27J/financial">bad Sportlink path</a><a href="https://evil.club.sportlink.com/member/member-details/SQJG27J/general">bad Sportlink host</a><a class="rondo-inline-action" href="https://wa.me/31612345678">WhatsApp</a><a href="https://wa.me/not-a-phone">bad WhatsApp</a><form><input></form><button type="button" data-rondo-tab="member" onclick="bad()">Lid</button><select data-rondo-profile-switcher><option value="profile-1">Profiel</option></select></div>');
        $this->assertStringNotContainsString('onclick', $result['srcdoc']);
        $this->assertStringNotContainsString('alert(2)', $result['srcdoc']);
        $this->assertStringNotContainsString('https://evil.example', $result['srcdoc']);
        $this->assertStringContainsString('https://rondo.example.nl/people/1', $result['srcdoc']);
        $this->assertStringContainsString('https://club.sportlink.com/member/member-details/SQJG27J/general', $result['srcdoc']);
        $this->assertStringNotContainsString('https://club.sportlink.com/member/member-details/SQJG27J/financial', $result['srcdoc']);
        $this->assertStringNotContainsString('https://evil.club.sportlink.com', $result['srcdoc']);
        $this->assertStringContainsString('https://wa.me/31612345678', $result['srcdoc']);
        $this->assertStringNotContainsString('href="https://wa.me/not-a-phone"', $result['srcdoc']);
        $this->assertStringContainsString('<button type="button" data-rondo-tab="member">Lid</button>', $result['srcdoc']);
        $this->assertStringContainsString('<select data-rondo-profile-switcher>', $result['srcdoc']);
        $this->assertStringNotContainsString('<form', $result['srcdoc']);
        $this->assertStringNotContainsString('<input', $result['srcdoc']);
        $this->assertStringContainsString('default-src &#039;none&#039;', $result['srcdoc']);
        $this->assertStringContainsString('script-src https://freescout.example.test', $result['srcdoc']);
        $this->assertStringContainsString('data-rondo-channel="' . $result['channel'] . '"', $result['srcdoc']);
        $this->assertStringContainsString('data-rondo-parent-origin="https://freescout.example.test"', $result['srcdoc']);
        $this->assertStringContainsString('<script src="https://freescout.example.test/modules/rondointegration/js/sidebar-frame.js"></script>', $result['srcdoc']);
        $this->assertStringNotContainsString('<script nonce=', $result['srcdoc']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', $result['channel']);
        $this->assertStringContainsString('.rondo-tabs{', $result['srcdoc']);
        $this->assertStringContainsString('.rondo-alert{', $result['srcdoc']);
        $this->assertStringContainsString('.rondo-inline-action{', $result['srcdoc']);
        $this->assertStringContainsString('body{margin:0;padding:4px 12px 12px', $result['srcdoc']);
    }

    public function testProfileSwitcherSurvivesSanitizingWithoutInlineScript()
    {
        $settings = $this->getMockBuilder(SettingsService::class)->onlyMethods(['baseUrl', 'get'])->getMock();
        $settings->method('baseUrl')->willReturn('https://rondo.example.nl');
        $settings->method('get')->willReturnCallback(function ($key, $default = null) {
            return $default;
        });
        $document = new SidebarDocument($settings);
        $html = '<select data-rondo-profile-switcher onchange="alert(1)"><option value="rondo-profile-0">Maaike</option></select>'
            . '<div id="rondo-profile-0" data-rondo-profile-panel>Profile</div>';

        $result = $document->render($html);

        $this->assertStringContainsString('data-rondo-profile-switcher', $result['srcdoc']);
        $this->assertStringContainsString('data-rondo-profile-panel', $result['srcdoc']);
        $this->assertStringNotContainsString('onchange', $result['srcdoc']);
        $this->assertStringNotContainsString('alert(1)', $result['srcdoc']);
    }
}
