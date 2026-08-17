<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\StubSlack;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fileperms;
use function openssl_x509_check_private_key;
use function openssl_x509_parse;
use function str_contains;
use function time;

/**
 * @internal
 */
final class SelfSignedCertificateTest extends TestCase
{
    /**
     * The name the stub is always reached on.
     *
     * @throws StubSlackException
     */
    #[Test]
    public function namesTheStubsOwnHost(): void
    {
        $certificate = SelfSignedCertificate::generate();

        $parsed = openssl_x509_parse("file://{$certificate->certFile}");
        self::assertIsArray($parsed);
        $name = $parsed['name'];
        self::assertIsString($name);
        self::assertTrue(str_contains($name, 'CN=127.0.0.1'));
    }

    /**
     * A certificate a test can no longer use once the run that made it has ended is no use.
     *
     * @throws StubSlackException
     */
    #[Test]
    public function isValidRightNow(): void
    {
        $certificate = SelfSignedCertificate::generate();

        $parsed = openssl_x509_parse("file://{$certificate->certFile}");
        self::assertIsArray($parsed);
        self::assertGreaterThan(time(), $parsed['validTo_time_t']);
    }

    /**
     * The key and the certificate have to agree, or the TLS handshake this stub exists for fails.
     *
     * @throws StubSlackException
     */
    #[Test]
    public function theKeyMatchesTheCertificate(): void
    {
        $certificate = SelfSignedCertificate::generate();

        $matches = openssl_x509_check_private_key("file://{$certificate->certFile}", "file://{$certificate->keyFile}");

        self::assertTrue($matches);
    }

    /**
     * The private key is not left world-readable in a shared temp directory.
     *
     * @throws StubSlackException
     */
    #[Test]
    public function theKeyFileIsNotWorldReadable(): void
    {
        $certificate = SelfSignedCertificate::generate();

        $permissions = fileperms($certificate->keyFile);

        self::assertNotFalse($permissions);
        self::assertSame(0o600, $permissions & 0o777);
    }

    /**
     * A fresh pair every call: nothing here is a fixture generated once and reused.
     *
     * @throws StubSlackException
     */
    #[Test]
    public function generatesAFreshPairEveryCall(): void
    {
        $first = SelfSignedCertificate::generate();
        $second = SelfSignedCertificate::generate();

        self::assertNotSame($first->certFile, $second->certFile);
        self::assertNotSame($first->keyFile, $second->keyFile);
    }
}
