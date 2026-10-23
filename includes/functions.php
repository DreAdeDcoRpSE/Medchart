<?php
/** MedChart encrypted field format: v2:<base64(nonce[12] || tag[16] || ciphertext)> */
function med_encryption_key() {
    if (!defined('ENCRYPTION_KEY') || strlen(ENCRYPTION_KEY) !== 32) {
        throw new RuntimeException('MedChart encryption key must be 32 bytes.');
    }
    return ENCRYPTION_KEY;
}
function med_decrypt_legacy($cipherText) {
    if (!defined('ENCRYPTION_IV') || strlen(ENCRYPTION_IV) !== 16) {
        throw new RuntimeException('Legacy encryption IV is unavailable.');
    }
    $inner = base64_decode((string)$cipherText, true);
    if ($inner === false) throw new RuntimeException('Invalid legacy ciphertext encoding.');
    $plain = openssl_decrypt($inner, 'aes-256-cbc', med_encryption_key(), 0, ENCRYPTION_IV);
    if ($plain === false) throw new RuntimeException('Legacy ciphertext could not be decrypted.');
    return $plain;
}
function encryptData($plainText) {
    $nonce = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt((string)$plainText, 'aes-256-gcm', med_encryption_key(), OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($cipher === false || strlen($tag) !== 16) throw new RuntimeException('Encryption failed.');
    return 'v2:' . base64_encode($nonce . $tag . $cipher);
}
function decryptData($cipherText) {
    if ($cipherText === null) return '';
    $value = (string)$cipherText;
    if (strncmp($value, 'v2:', 3) !== 0) return med_decrypt_legacy($value);
    $raw = base64_decode(substr($value, 3), true);
    if ($raw === false || strlen($raw) < 28) throw new RuntimeException('Invalid encrypted value.');
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', med_encryption_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    if ($plain === false) throw new RuntimeException('Encrypted value failed authentication.');
    return $plain;
}
