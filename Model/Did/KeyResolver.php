<?php

declare(strict_types=1);

namespace MSR\AgenticUcp\Model\Did;

use MSR\AgenticUcp\Api\Did\ResolverInterface;

/**
 * Resolves did:key DIDs to PEM public keys.
 *
 * Supports Ed25519 (EdDSA), P-256 (ES256), and secp256k1 (ES256K) key types
 * identified by their multicodec prefix inside the base58btc-encoded identifier.
 */
class KeyResolver implements ResolverInterface
{
    private const MULTICODEC_ED25519    = 0xED;    // varint bytes: 0xED 0x01
    private const MULTICODEC_P256       = 0x1200;  // varint bytes: 0x80 0x24
    private const MULTICODEC_SECP256K1  = 0xE7;   // varint bytes: 0xE7 0x01

    /**
     * @param string $did
     * @return bool
     */
    public function supports(string $did): bool
    {
        return str_starts_with($did, 'did:key:');
    }

    /**
     * @param string $did
     * @return string|null
     */
    public function resolvePublicKey(string $did): ?string
    {
        try {
            $identifier = substr($did, strlen('did:key:'));

            if ($identifier === '' || $identifier[0] !== 'z') {
                return null; // only base58btc (z) multibase supported
            }

            $raw = $this->base58Decode(substr($identifier, 1));
            if ($raw === '') {
                return null;
            }

            $offset = 0;
            $codec  = $this->decodeVarint($raw, $offset);
            $key    = substr($raw, $offset);

            return match ($codec) {
                self::MULTICODEC_ED25519   => $this->toPem($this->ed25519Der($key)),
                self::MULTICODEC_P256      => $this->toPem($this->p256Der($key)),
                self::MULTICODEC_SECP256K1 => $this->toPem($this->secp256k1Der($key)),
                default                    => null,
            };
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param string|null $der
     * @return string|null
     */
    private function toPem(?string $der): ?string
    {
        if ($der === null) {
            return null;
        }
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    /**
     * SubjectPublicKeyInfo DER for Ed25519 (OID 1.3.101.112).
     *
     * Key must be exactly 32 bytes.
     *
     * @param string $key
     * @return string|null
     */
    private function ed25519Der(string $key): ?string
    {
        if (strlen($key) !== 32) {
            return null;
        }
        return "\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00" . $key;
    }

    /**
     * SubjectPublicKeyInfo DER for P-256 (OID 1.2.840.10045.2.1, curve 1.2.840.10045.3.1.7).
     *
     * Accepts compressed (33 bytes) or uncompressed (65 bytes) key.
     *
     * @param string $key
     * @return string|null
     */
    private function p256Der(string $key): ?string
    {
        $algorithmSeq = "\x30\x13"                             // SEQUENCE (19 bytes)
            . "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"         // OID ecPublicKey
            . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";    // OID P-256 curve

        return match (strlen($key)) {
            33 => "\x30\x39" . $algorithmSeq . "\x03\x22\x00" . $key, // compressed
            65 => "\x30\x59" . $algorithmSeq . "\x03\x42\x00" . $key, // uncompressed
            default => null,
        };
    }

    /**
     * SubjectPublicKeyInfo DER for secp256k1 (OID 1.2.840.10045.2.1, curve 1.3.132.0.10).
     *
     * Accepts compressed (33 bytes) or uncompressed (65 bytes) key.
     *
     * @param string $key
     * @return string|null
     */
    private function secp256k1Der(string $key): ?string
    {
        $algorithmSeq = "\x30\x10"                            // SEQUENCE (16 bytes)
            . "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"        // OID ecPublicKey
            . "\x06\x05\x2b\x81\x04\x00\x0a";               // OID secp256k1 curve

        return match (strlen($key)) {
            33 => "\x30\x36" . $algorithmSeq . "\x03\x22\x00" . $key, // compressed
            65 => "\x30\x56" . $algorithmSeq . "\x03\x42\x00" . $key, // uncompressed
            default => null,
        };
    }

    /**
     * @param string $data
     * @param int $offset
     * @return int
     */
    private function decodeVarint(string $data, int &$offset): int
    {
        $value = 0;
        $shift = 0;
        do {
            if ($offset >= strlen($data)) {
                return -1;
            }
            $byte   = ord($data[$offset++]);
            $value |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while ($byte & 0x80);
        return $value;
    }

    /**
     * @param string $data
     * @return string
     */
    private function base58Decode(string $data): string
    {
        $alphabet   = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $result     = gmp_init(0);
        $base       = gmp_init(58);
        $dataLength = strlen($data);

        for ($i = 0; $i < $dataLength; $i++) {
            $pos = strpos($alphabet, $data[$i]);
            if ($pos === false) {
                return '';
            }
            $result = gmp_add(gmp_mul($result, $base), gmp_init($pos));
        }

        $hex = gmp_strval($result, 16);
        if (strlen($hex) % 2 !== 0) {
            $hex = '0' . $hex;
        }

        $leadingZeros = '';
        for ($i = 0; $i < $dataLength && $data[$i] === '1'; $i++) {
            $leadingZeros .= "\x00";
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        return $leadingZeros . hex2bin($hex);
    }
}
