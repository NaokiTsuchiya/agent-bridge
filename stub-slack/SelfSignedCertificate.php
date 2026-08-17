<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\StubSlack;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use OpenSSLCertificateSigningRequest;

use function chmod;
use function file_put_contents;
use function implode;
use function openssl_csr_new;
use function openssl_csr_sign;
use function openssl_error_string;
use function openssl_pkey_export;
use function openssl_pkey_new;
use function openssl_x509_export;
use function sys_get_temp_dir;
use function tempnam;

use const OPENSSL_KEYTYPE_RSA;

/**
 * A fresh, self-signed TLS certificate for `127.0.0.1`, generated for one test run and never
 * committed anywhere: `stub-slack/bin/stub-slack` makes one of these each time it starts, and the
 * files live in the system temp directory rather than the repository.
 */
final readonly class SelfSignedCertificate
{
    /** The name on the certificate; the stub only ever answers on this host. */
    private const string COMMON_NAME = '127.0.0.1';

    /**
     * Long enough to outlast a test run, short enough that a leaked temp file is not a standing
     * credential.
     */
    private const int VALIDITY_DAYS = 1;

    /** Only {@see generate()} calls this — the pair it hands over is already a matched, signed pair. */
    private function __construct(
        public string $certFile,
        public string $keyFile,
    ) {}

    /** @throws StubSlackException when openssl cannot produce a key, a CSR, or a signed certificate */
    public static function generate(): self
    {
        $key = self::key();
        $csr = self::csr($key);
        $certificate = self::sign($csr, $key);

        $certPem = '';
        $keyPem = '';
        openssl_x509_export($certificate, $certPem);
        openssl_pkey_export($key, $keyPem);

        $certFile = tempnam(sys_get_temp_dir(), prefix: 'stub-slack-cert-');
        $keyFile = tempnam(sys_get_temp_dir(), prefix: 'stub-slack-key-');

        if ($certFile === false || $keyFile === false) {
            throw new StubSlackException('Cannot create temp files for the stub certificate.');
        }

        file_put_contents($certFile, $certPem);
        file_put_contents($keyFile, $keyPem);
        // The key must not be world-readable even in a shared temp directory; a self-signed cert
        // is throwaway, but the private key is still what a TLS handshake trusts.
        chmod($keyFile, permissions: 0o600);

        return new self($certFile, $keyFile);
    }

    /** @throws StubSlackException */
    private static function key(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false) {
            throw new StubSlackException('Cannot generate a key for the stub certificate: ' . self::opensslErrors());
        }

        return $key;
    }

    /** @throws StubSlackException */
    private static function csr(OpenSSLAsymmetricKey $key): OpenSSLCertificateSigningRequest
    {
        $csr = openssl_csr_new(['commonName' => self::COMMON_NAME], $key, ['digest_alg' => 'sha256']);

        // `openssl_csr_new()` is typed as capable of a legacy `resource` return; every supported
        // PHP version here only ever hands back the object or `false`, but the guard has to name
        // the object type explicitly for that to be provable.
        if (!$csr instanceof OpenSSLCertificateSigningRequest) {
            throw new StubSlackException('Cannot build a CSR for the stub certificate: ' . self::opensslErrors());
        }

        return $csr;
    }

    /** @throws StubSlackException */
    private static function sign(OpenSSLCertificateSigningRequest $csr, OpenSSLAsymmetricKey $key): OpenSSLCertificate
    {
        // A `null` CA certificate is what makes this self-signed: there is nothing else to trust it.
        $certificate = openssl_csr_sign(
            $csr,
            ca_certificate: null,
            private_key: $key,
            days: self::VALIDITY_DAYS,
            options: ['digest_alg' => 'sha256'],
        );

        if ($certificate === false) {
            throw new StubSlackException('Cannot self-sign the stub certificate: ' . self::opensslErrors());
        }

        return $certificate;
    }

    /**
     * Every queued openssl error, oldest first.
     *
     * `openssl_error_string()` pops one message per call and returns `false` once the queue is
     * empty; left undrained, a later unrelated call would report this failure's message instead of
     * its own.
     */
    private static function opensslErrors(): string
    {
        $messages = [];

        while (true) {
            $message = openssl_error_string();

            if ($message === false) {
                break;
            }

            $messages[] = $message;
        }

        return $messages === [] ? 'no further detail from openssl' : implode('; ', $messages);
    }
}
