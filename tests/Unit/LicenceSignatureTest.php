<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/sources/licence.functions.php';

/**
 * Verification of the X-Payload-Signature header carried by every licence server answer.
 *
 * The signature is checked against a key pair generated here rather than against the real
 * server key: the point is the verification code, not a fixture that would rot the day the
 * licence server rotates its key.
 */
class LicenceSignatureTest extends TestCase
{
    /** @var string */
    private static $privateKey = '';

    /** @var string */
    private static $publicKey = '';

    public static function setUpBeforeClass(): void
    {
        if (function_exists('openssl_pkey_new') === false) {
            self::markTestSkipped('openssl extension is required');
        }

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($resource);

        openssl_pkey_export($resource, self::$privateKey);
        $details = openssl_pkey_get_details($resource);
        self::$publicKey = (string) $details['key'];
    }

    /**
     * Sign a body the way the licence server does: RSA-SHA256 over the raw bytes, base64.
     */
    private function sign(string $body): string
    {
        $signature = '';
        openssl_sign($body, $signature, self::$privateKey, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    /**
     * The happy path, on the body exactly as it arrived.
     */
    public function testValidSignatureIsAccepted(): void
    {
        $body = '{"status":"TRIAL_GRANTED","trial":true}';

        self::assertTrue(licenceVerifySignature($body, $this->sign($body), self::$publicKey));
    }

    /**
     * One flipped byte must be enough to reject the answer.
     */
    public function testTamperedBodyIsRejected(): void
    {
        $body = '{"status":"TRIAL_GRANTED","trial":true}';
        $signature = $this->sign($body);
        $tampered = str_replace('TRIAL_GRANTED', 'TRIAL_GRANTEE', $body);

        self::assertSame(strlen($body), strlen($tampered));
        self::assertFalse(licenceVerifySignature($tampered, $signature, self::$publicKey));
    }

    /**
     * A signature computed over something else does not verify either.
     */
    public function testWrongSignatureIsRejected(): void
    {
        $body = '{"status":"VALID"}';

        self::assertFalse(
            licenceVerifySignature($body, $this->sign('{"status":"INVALID"}'), self::$publicKey)
        );
    }

    /**
     * A missing header is a missing proof, never an implicit pass.
     */
    public function testMissingSignatureIsRejected(): void
    {
        $body = '{"status":"VALID"}';

        self::assertFalse(licenceVerifySignature($body, '', self::$publicKey));
        self::assertFalse(licenceVerifySignature($body, '   ', self::$publicKey));
        self::assertFalse(licenceVerifySignature($body, 'not base64 @@@', self::$publicKey));
    }

    /**
     * An empty body cannot be authenticated whatever the header says.
     */
    public function testEmptyBodyIsRejected(): void
    {
        self::assertFalse(licenceVerifySignature('', $this->sign(''), self::$publicKey));
    }

    /**
     * A body signed by somebody else's key is refused: this is what protects the licence
     * state from an intercepting proxy.
     */
    public function testForeignKeyIsRejected(): void
    {
        $body = '{"status":"TRIAL_GRANTED"}';
        $other = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $otherPrivate = '';
        openssl_pkey_export($other, $otherPrivate);

        $signature = '';
        openssl_sign($body, $signature, $otherPrivate, OPENSSL_ALGO_SHA256);

        self::assertFalse(licenceVerifySignature($body, base64_encode($signature), self::$publicKey));
    }

    /**
     * The embedded production key must be a usable RSA public key: a broken constant would
     * silently reject every answer the licence server ever sends.
     */
    public function testEmbeddedProductionKeyIsUsable(): void
    {
        $key = openssl_pkey_get_public(LICENCE_SERVER_PUBLIC_KEY);

        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertSame(OPENSSL_KEYTYPE_RSA, $details['type']);
        self::assertSame(4096, $details['bits']);
    }

    /**
     * The staging escape hatch must never downgrade the transport to plain HTTP against a
     * remote host.
     */
    public function testBaseUrlOverrideOnlyAcceptsHttpsOrLoopback(): void
    {
        self::assertSame(LICENCE_SERVER_BASE_URL, licenceServerBaseUrl([]));
        self::assertSame(LICENCE_SERVER_BASE_URL, licenceServerBaseUrl(['licence_server_base_url' => '']));
        self::assertSame(
            LICENCE_SERVER_BASE_URL,
            licenceServerBaseUrl(['licence_server_base_url' => 'http://evil.example'])
        );
        self::assertSame(
            LICENCE_SERVER_BASE_URL,
            licenceServerBaseUrl(['licence_server_base_url' => 'ftp://licence.example'])
        );
        self::assertSame(
            'https://staging.teampass.net',
            licenceServerBaseUrl(['licence_server_base_url' => 'https://staging.teampass.net/'])
        );
        self::assertSame(
            'http://127.0.0.1:8099',
            licenceServerBaseUrl(['licence_server_base_url' => 'http://127.0.0.1:8099'])
        );
    }

    /**
     * The embedded key must be the one the deployed licence server advertises under
     * security.rsa_public_key_fingerprint on GET /. A mismatch means every signed answer
     * would be rejected, so the constant is pinned here.
     */
    public function testEmbeddedKeyFingerprintIsThePublishedOne(): void
    {
        self::assertSame(
            'ac1c599e654c67770e193d0708a3461a46d0f71481f9f2ab8738c2723b7e9256',
            licenceServerPublicKeyFingerprint()
        );
    }

    /**
     * A rotated server key must be told apart from a tampered answer: the two look identical
     * to openssl_verify() but call for completely different actions.
     */
    public function testKeyRotationIsDetected(): void
    {
        $matching = licenceTrialParseDiscovery([
            'status' => 'online',
            'security' => ['rsa_public_key_fingerprint' => 'sha256:' . licenceServerPublicKeyFingerprint()],
        ]);
        self::assertTrue(licenceServerKeyFingerprintMatches($matching));

        $rotated = licenceTrialParseDiscovery([
            'status' => 'online',
            'security' => ['rsa_public_key_fingerprint' => 'sha256:' . str_repeat('0', 64)],
        ]);
        self::assertFalse(licenceServerKeyFingerprintMatches($rotated));
    }

    /**
     * Not knowing the server fingerprint is not knowing it is wrong: a server that does not
     * advertise one must keep working.
     */
    public function testUnadvertisedFingerprintIsNotAMismatch(): void
    {
        self::assertTrue(
            licenceServerKeyFingerprintMatches(licenceTrialParseDiscovery(['status' => 'online']))
        );
        self::assertTrue(licenceServerKeyFingerprintMatches(licenceTrialParseDiscovery(null)));
    }

    /**
     * The offline request link targets the licence server itself, and carries everything
     * trial.php needs - the licence key included.
     *
     * That last point is deliberate and worth a test of its own: the administrator is at a
     * console with no Internet access and cannot copy 64 characters across by hand, and the
     * destination is the legitimate holder of the token. Pointing this link anywhere else
     * would turn it into a key leak.
     */
    public function testOfflineRequestUrlTargetsTheLicenceServerAndCarriesEverything(): void
    {
        $url = licenceTrialOfflineRequestUrl(
            [],
            'teampass.acme.example',
            'admin@acme.example',
            str_repeat('a', 64),
            'extension'
        );

        self::assertStringStartsWith(LICENCE_SERVER_BASE_URL . LICENCE_TRIAL_REQUEST_PATH . '?', $url);
        self::assertStringContainsString('fqdn=teampass.acme.example', $url);
        self::assertStringContainsString('email=admin%40acme.example', $url);
        self::assertStringContainsString('token=' . str_repeat('a', 64), $url);
        self::assertStringContainsString('product=extension', $url);
    }

    /**
     * A staging licence server is reached through licence_server_base_url, so the offline
     * link must follow it: a link still pointing at production would send a staging FQDN to
     * the real registry, and a trial is granted once per FQDN forever.
     */
    public function testOfflineRequestUrlFollowsTheBaseUrlOverride(): void
    {
        $url = licenceTrialOfflineRequestUrl(
            ['licence_server_base_url' => 'https://staging.licence.example'],
            'teampass.acme.example',
            'admin@acme.example',
            str_repeat('a', 64),
            'extension'
        );

        self::assertStringStartsWith('https://staging.licence.example' . LICENCE_TRIAL_REQUEST_PATH, $url);
    }

    /**
     * An http:// override that is not a loopback address must be ignored: the link carries
     * the licence key, so it may never be built on a plaintext third-party host.
     */
    public function testOfflineRequestUrlRefusesAnInsecureOverride(): void
    {
        $url = licenceTrialOfflineRequestUrl(
            ['licence_server_base_url' => 'http://evil.example'],
            'teampass.acme.example',
            'admin@acme.example',
            str_repeat('a', 64),
            'extension'
        );

        self::assertStringStartsWith(LICENCE_SERVER_BASE_URL, $url);
        self::assertStringNotContainsString('evil.example', $url);
    }
}
