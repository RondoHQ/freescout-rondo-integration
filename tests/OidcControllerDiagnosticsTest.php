<?php

namespace Illuminate\Routing {
    class Controller
    {
    }
}

namespace {
    use PHPUnit\Framework\TestCase;

    class OidcControllerDiagnosticsTest extends TestCase
    {
        public function testDiagnosticRedactionCannotBreakFailureHandling()
        {
            require_once dirname(__DIR__) . '/Http/Controllers/OidcController.php';
            $class = new ReflectionClass(Modules\RondoIntegration\Http\Controllers\OidcController::class);
            $controller = $class->newInstanceWithoutConstructor();
            $method = $class->getMethod('diagnosticMessage');
            $method->setAccessible(true);
            $diagnostic = $method->invoke(
                $controller,
                new RuntimeException('Authorization: Basic abc/+=:xyz https://example.test/callback?code=secret&state=ok')
            );

            $this->assertStringContainsString('Basic [redacted]', $diagnostic);
            $this->assertStringContainsString('code=[redacted]', $diagnostic);
            $this->assertStringNotContainsString('abc/+=:xyz', $diagnostic);
            $this->assertStringNotContainsString('code=secret', $diagnostic);
        }
    }
}
