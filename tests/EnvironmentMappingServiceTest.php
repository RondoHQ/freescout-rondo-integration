<?php

use Modules\RondoIntegration\Services\EnvironmentMappingService;
use PHPUnit\Framework\TestCase;

class EnvironmentMappingServiceTest extends TestCase
{
    public function testItParsesAllowedUniqueMappings()
    {
        $service = new EnvironmentMappingService();
        $this->assertSame(['ledenadministratie' => 7], $service->parse('{"ledenadministratie":7}', ['ledenadministratie']));
    }

    public function testItRejectsUnknownKeys()
    {
        $this->expectException(InvalidArgumentException::class);
        (new EnvironmentMappingService())->parse('{"unknown":7}', ['ledenadministratie']);
    }

    public function testItRejectsDuplicateMailboxIds()
    {
        $this->expectException(InvalidArgumentException::class);
        (new EnvironmentMappingService())->parse('{"ledenadministratie":7,"bestuur":7}', ['ledenadministratie', 'bestuur']);
    }
}
