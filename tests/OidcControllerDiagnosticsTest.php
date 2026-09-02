<?php

namespace Illuminate\Routing {
    class Controller
    {
    }
}

namespace {
    use PHPUnit\Framework\TestCase;

    class DB
    {
        public static $table;
        public static $row;

        public static function table($table)
        {
            self::$table = $table;
            return new OidcAuditTableStub();
        }
    }

    class OidcAuditTableStub
    {
        public function insert(array $row)
        {
            DB::$row = $row;
        }
    }

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

        public function testDiagnosticRedactsStructuredSecretsAndJwtValues()
        {
            require_once dirname(__DIR__) . '/Http/Controllers/OidcController.php';
            $class = new ReflectionClass(Modules\RondoIntegration\Http\Controllers\OidcController::class);
            $controller = $class->newInstanceWithoutConstructor();
            $method = $class->getMethod('diagnosticMessage');
            $method->setAccessible(true);
            $diagnostic = $method->invoke(
                $controller,
                new RuntimeException('{"client_secret":"very-secret","access_token":"eyJheader.payload.signature"}')
            );

            $this->assertStringContainsString('client_secret":"[redacted]', $diagnostic);
            $this->assertStringContainsString('access_token":"[redacted]', $diagnostic);
            $this->assertStringNotContainsString('very-secret', $diagnostic);
            $this->assertStringNotContainsString('eyJheader.payload.signature', $diagnostic);
        }

        public function testUnexpectedFailureDiagnosticIsPersistedUnderItsVisibleReference()
        {
            require_once dirname(__DIR__) . '/Http/Controllers/OidcController.php';
            $class = new ReflectionClass(Modules\RondoIntegration\Http\Controllers\OidcController::class);
            $controller = $class->newInstanceWithoutConstructor();
            $method = $class->getMethod('persistFailure');
            $method->setAccessible(true);
            $method->invoke($controller, [
                'reference' => 'abc123def456',
                'reason' => 'authentication_failed',
                'exception' => RuntimeException::class,
                'diagnostic' => 'http_401',
                'location' => 'BoundedHttpClient.php:49',
            ]);

            $this->assertSame('rondo_oidc_binding_audit', DB::$table);
            $this->assertSame('oidc_sign_in_failed', DB::$row['event_type']);
            $this->assertSame('abc123def456', DB::$row['correlation_id']);
            $details = json_decode(DB::$row['reason'], true);
            $this->assertSame('authentication_failed', $details['reason']);
            $this->assertSame('http_401', $details['diagnostic']);
            $this->assertArrayNotHasKey('reference', $details);
        }
    }
}
